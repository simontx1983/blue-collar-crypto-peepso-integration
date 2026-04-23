<?php

namespace BCC\PeepSo\Services;

use BCC\PeepSo\Domain\AbstractPageType;
use BCC\PeepSo\Repositories\GalleryRepository;
use BCC\PeepSo\Repositories\LockRepository;
use BCC\PeepSo\Repositories\PeepSoPageRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo Page → Shadow CPT synchronisation.
 *
 * Responsibilities:
 *   1. Auto-create missing shadow CPTs on page load (owner-gated, atomic lock).
 *   2. Queue page title/metadata changes during save and flush them at shutdown.
 *   3. Prevent duplicate shadow CPTs for the same (page, type) pair.
 *   4. Cascade delete shadow CPTs (and gallery rows) when the source page dies.
 */
final class ShadowPageSyncService
{
    private const INTEGRITY_META = '_bcc_integrity_ok';
    private const LOCK_TTL       = 30;

    /** @var array<int, array{title: string, author: int}> */
    private static array $pending = [];

    public static function register(): void
    {
        add_action('wp', [self::class, 'autoCreateOnPageLoad']);
        add_action('save_post_peepso-page', [self::class, 'queueOnSave'], 10, 3);
        add_action('shutdown', [self::class, 'flushQueue']);
        add_action('save_post', [self::class, 'preventDuplicate'], 10, 2);
        add_action('before_delete_post', [self::class, 'cascadeDelete'], 10);
    }

    /**
     * Auto-create missing shadow CPTs on page load.
     *
     * Gated: visitors (unauthenticated / non-owners) never pay the
     * wp_insert_post() cost. Once all shadows exist, the integrity meta
     * short-circuits future loads until a category change clears it.
     */
    public static function autoCreateOnPageLoad(): void
    {
        if (!is_singular('peepso-page') || !is_user_logged_in()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $pageId = (int) $post->ID;

        if (get_post_meta($pageId, self::INTEGRITY_META, true)) {
            return;
        }

        if (function_exists('bcc_user_is_owner') && !bcc_user_is_owner($pageId)) {
            return;
        }

        $lockKey = 'integrity_' . $pageId;
        if (!LockRepository::tryAcquire($lockKey, self::LOCK_TTL)) {
            return;
        }

        try {
            if (!function_exists('bcc_get_category_map')) {
                return;
            }

            $catIds = PeepSoPageRepository::getCategoryIdsForPage($pageId);
            if (empty($catIds)) {
                update_post_meta($pageId, self::INTEGRITY_META, 1);
                return;
            }

            $map        = bcc_get_category_map();
            $targetCpts = [];
            foreach ($catIds as $catId) {
                if (isset($map[(int) $catId]['cpt'])) {
                    $targetCpts[] = $map[(int) $catId]['cpt'];
                }
            }
            $targetCpts = array_unique($targetCpts);

            $allOk = true;
            foreach ($targetCpts as $cpt) {
                $existing = get_post_meta($pageId, '_linked_' . $cpt . '_id', true);
                if ($existing && get_post($existing)) {
                    continue;
                }

                $created = AbstractPageType::create_from_page_by_type($pageId, $cpt);
                if (!$created) {
                    $allOk = false;
                }
            }

            if ($allOk) {
                update_post_meta($pageId, self::INTEGRITY_META, 1);
            }
        } finally {
            LockRepository::release($lockKey);
        }
    }

    /**
     * Queue a page for shadow-CPT sync at shutdown.
     *
     * @param int      $postId
     * @param \WP_Post $post
     * @param bool     $update
     */
    public static function queueOnSave($postId, $post, $update): void
    {
        if (!$post) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        self::$pending[(int) $postId] = [
            'title'  => (string) $post->post_title,
            'author' => (int) $post->post_author,
        ];
    }

    /**
     * Flush pending page changes to their linked shadow CPTs.
     * Called on `shutdown` so that expensive writes happen after the
     * HTTP response has been delivered.
     */
    public static function flushQueue(): void
    {
        if (empty(self::$pending)) {
            return;
        }
        if (!function_exists('bcc_get_category_map')) {
            return;
        }
        if (!PeepSoPageRepository::tableExists()) {
            return;
        }

        $map = bcc_get_category_map();

        foreach (self::$pending as $pageId => $data) {
            // Serialize across flushQueue / cascadeDelete / PageRepairService::repair
            // so the three writers of `_linked_cpts` cannot clobber each other.
            // Lock key matches autoCreateOnPageLoad's so the entire shadow-CPT
            // integrity namespace for this page is single-writer.
            $lockKey = 'integrity_' . $pageId;
            if (!LockRepository::tryAcquire($lockKey, self::LOCK_TTL)) {
                continue;
            }

            try {
                $rows = PeepSoPageRepository::getCategoryRowsForPage((int) $pageId);
                if (empty($rows)) {
                    continue;
                }

                $targets = [];
                foreach ($rows as $row) {
                    $catId = (int) $row->cat_id;
                    if (!isset($map[$catId]['cpt'])) {
                        continue;
                    }
                    $targets[$map[$catId]['cpt']] = $catId;
                }

                if (empty($targets)) {
                    continue;
                }

                $linked = [];
                foreach ($targets as $postType => $catId) {
                    if (!post_type_exists($postType)) {
                        continue;
                    }

                    $existing = get_post_meta($pageId, '_linked_' . $postType . '_id', true);

                    if ($existing && get_post($existing)) {
                        if (get_the_title($existing) !== $data['title']) {
                            wp_update_post([
                                'ID'         => $existing,
                                'post_title' => $data['title'],
                                'post_name'  => sanitize_title($data['title']),
                            ]);
                        }
                        $linked[$postType] = (int) $existing;
                        continue;
                    }

                    $cptId = AbstractPageType::create_from_page_by_type((int) $pageId, $postType);
                    if (!$cptId) {
                        continue;
                    }

                    update_post_meta($cptId, '_peepso_cat_id', (int) $catId);
                    $linked[$postType] = (int) $cptId;
                }

                update_post_meta($pageId, '_linked_cpts', $linked);
            } finally {
                LockRepository::release($lockKey);
            }
        }

        self::$pending = [];
    }

    /**
     * When a shadow CPT is saved, trash it if another shadow is already
     * linked to the same source page (prevents manual duplicate creation).
     *
     * @param int      $postId
     * @param \WP_Post $post
     */
    public static function preventDuplicate($postId, $post): void
    {
        if (wp_is_post_revision($postId)) {
            return;
        }

        $pageId = get_post_meta($postId, '_peepso_page_id', true);
        if (!$pageId) {
            return;
        }

        $existing = get_post_meta($pageId, '_linked_' . $post->post_type . '_id', true);
        if ($existing && (int) $existing !== (int) $postId) {
            wp_trash_post($postId);
        }
    }

    /**
     * When a PeepSo page is deleted, trash linked shadow CPTs and clean
     * up gallery rows that reference them.
     *
     * @param int $postId
     */
    public static function cascadeDelete($postId): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'peepso-page') {
            return;
        }

        $pageId = (int) $postId;

        // Acquire the shared integrity lock so cascadeDelete cannot race
        // with flushQueue / PageRepairService::repair writing `_linked_cpts`
        // while we are reading it. If we cannot acquire, fall through to a
        // direct meta scan below — missing orphans is worse than waiting.
        $lockKey     = 'integrity_' . $pageId;
        $lockAcquired = LockRepository::tryAcquire($lockKey, self::LOCK_TTL);

        try {
            // Authoritative source: scan every known shadow CPT type for
            // posts whose `_peepso_page_id` meta equals our page id. This
            // is resilient to a drifted `_linked_cpts` aggregate that could
            // omit a shadow created between a prior flushQueue and this
            // delete — such omissions previously turned those shadows (and
            // their gallery rows) into permanent orphans.
            $shadowTypes = function_exists('bcc_get_shadow_cpt_types')
                ? bcc_get_shadow_cpt_types()
                : ['validators', 'nft', 'builder', 'dao'];

            $shadowIds = [];
            foreach ($shadowTypes as $cpt) {
                if (!post_type_exists($cpt)) {
                    continue;
                }
                $found = get_posts([
                    'post_type'      => $cpt,
                    'post_status'    => 'any',
                    'meta_key'       => '_peepso_page_id',
                    'meta_value'     => (string) $pageId,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);
                foreach ($found as $id) {
                    $shadowIds[] = (int) $id;
                }
            }

            // Belt-and-suspenders: also include anything the cached
            // aggregate knew about, in case the meta scan missed a row
            // (e.g. the shadow was trashed and the meta_value stored as
            // int-string vs string mismatches).
            $linked = get_post_meta($postId, '_linked_cpts', true);
            if (is_array($linked)) {
                foreach ($linked as $cptId) {
                    $shadowIds[] = (int) $cptId;
                }
            }
            $shadowIds = array_values(array_unique(array_filter($shadowIds)));

            foreach ($shadowIds as $cptId) {
                if (!get_post($cptId)) {
                    continue;
                }

                if (class_exists(GalleryRepository::class)) {
                    GalleryRepository::deleteByPostId($cptId);
                }

                wp_trash_post($cptId);
            }

            delete_post_meta($postId, '_linked_cpts');
        } finally {
            if ($lockAcquired) {
                LockRepository::release($lockKey);
            }
        }
    }
}
