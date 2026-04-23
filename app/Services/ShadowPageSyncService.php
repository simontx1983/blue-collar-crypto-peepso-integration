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

    /** Continuation hook for onUserDeleted batching. */
    private const USER_CLEANUP_HOOK = 'bcc_peepso_user_cleanup_batch';

    /** Max shadow posts handled per CPT per batch. A delete_user admin
     *  request must not stall on tens of thousands of shadows. */
    private const USER_CLEANUP_BATCH = 100;

    /** @var array<int, array{title: string, author: int}> */
    private static array $pending = [];

    public static function register(): void
    {
        add_action('wp', [self::class, 'autoCreateOnPageLoad']);
        add_action('save_post_peepso-page', [self::class, 'queueOnSave'], 10, 3);
        add_action('shutdown', [self::class, 'flushQueue']);
        add_action('save_post', [self::class, 'preventDuplicate'], 10, 2);

        // Lifecycle: shadow CPTs + gallery rows must follow the source
        // PeepSo page through both trash (reversible) and permanent delete
        // (irreversible). The prior registration hooked ONLY
        // before_delete_post, which fires on permanent delete — leaving
        // shadow CPTs visible for the entire trash window (up to
        // EMPTY_TRASH_DAYS) even though their source page was "deleted"
        // from the UI. cascadeTrash handles the trash transition and
        // defers gallery-row cleanup to cascadeDelete on permanent delete.
        add_action('wp_trash_post', [self::class, 'cascadeTrash'], 10);
        add_action('before_delete_post', [self::class, 'cascadeDelete'], 10);

        // User lifecycle: clean up rows tied to a user id that is about to
        // disappear from wp_users. Without these hooks, _peepso_page_id
        // meta and bcc_collections.user_id rows reference dead users
        // indefinitely, breaking the page_id → valid owner contract that
        // bcc-trust-engine and bcc-disputes both assume. deleted_user
        // fires AFTER the wp_users row is gone but with the id still in
        // scope, so this is the correct hook for cross-table cleanup.
        add_action('deleted_user', [self::class, 'onUserDeleted'], 10, 1);
        add_action(self::USER_CLEANUP_HOOK, [self::class, 'runUserCleanupBatch'], 10, 1);
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
     * When a PeepSo page is TRASHED (not yet permanently deleted), trash
     * the linked shadow CPTs so they disappear from user-facing queries
     * in lockstep with the source page. Gallery-row DB cleanup is
     * deferred to cascadeDelete — a trashed page can still be restored,
     * and restore-from-trash rehydrates the links. Deleting gallery rows
     * on trash would make restore lossy.
     *
     * @param int $postId
     */
    public static function cascadeTrash($postId): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'peepso-page') {
            return;
        }

        $pageId = (int) $postId;

        // Serialise with flushQueue / cascadeDelete / PageRepairService
        // on the integrity namespace for this page.
        $lockKey      = 'integrity_' . $pageId;
        $lockAcquired = LockRepository::tryAcquire($lockKey, self::LOCK_TTL);

        try {
            $shadowIds = self::findShadowPostIds($pageId);

            foreach ($shadowIds as $cptId) {
                $shadowPost = get_post($cptId);
                if (!$shadowPost) {
                    continue;
                }
                // Only trash shadows that are not already trashed; trashing
                // a trashed post flips status back to a published state in
                // some WP versions (wp_trash_post no-ops then).
                if ($shadowPost->post_status === 'trash') {
                    continue;
                }
                wp_trash_post($cptId);
            }
        } finally {
            if ($lockAcquired) {
                LockRepository::release($lockKey);
            }
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
            $shadowIds = self::findShadowPostIds($pageId);

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

    /**
     * Resolve every shadow CPT post id that belongs to the given source
     * page id.
     *
     * Combines two sources so we never miss a shadow:
     *   1. Authoritative scan: query every shadow CPT type for posts
     *      whose `_peepso_page_id` meta equals $pageId. NUMERIC compare
     *      is load-bearing — some writers stored the meta as int, some
     *      as string, and a plain equality check can miss the other.
     *   2. Cached aggregate: `_linked_cpts` post_meta on the source page.
     *      A drifted aggregate can omit recent shadows (which is why the
     *      scan exists), but the aggregate can also include trashed
     *      shadows whose meta query missed them due to type mismatch.
     *
     * Returns a deduplicated list of shadow post ids, sorted by
     * insertion order (scan first, aggregate second).
     *
     * @return int[]
     */
    private static function findShadowPostIds(int $pageId): array
    {
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
                'meta_query'     => [[
                    'key'     => '_peepso_page_id',
                    'value'   => $pageId,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ]],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($found as $id) {
                $shadowIds[] = (int) $id;
            }
        }

        $linked = get_post_meta($pageId, '_linked_cpts', true);
        if (is_array($linked)) {
            foreach ($linked as $cptId) {
                $shadowIds[] = (int) $cptId;
            }
        }

        return array_values(array_unique(array_filter($shadowIds)));
    }

    /**
     * Lifecycle: the given user is being removed. Tear down the records
     * that reference them.
     *
     * PeepSo's own user-delete flow typically reassigns peepso-page posts
     * to an admin (content reassignment), but the shadow-CPT graph and
     * bcc_collections rows reference the original user_id in ways PeepSo
     * doesn't know about. Left in place, those rows break the
     * page_id → valid owner contract the trust engine assumes.
     *
     * Scope:
     *   - Find every shadow CPT authored by the deleted user and run the
     *     same cascade used for page-delete: trash the CPT and purge
     *     its gallery rows. We do NOT touch peepso-page posts themselves
     *     — WordPress core (or the reassign_user_content path) handles
     *     those; doing it here could race with that handling.
     *   - Delete bcc_collections / bcc_collection_images rows owned by
     *     this user on any post, via GalleryRepository::deleteByUserId.
     *
     * @param int $userId
     */
    public static function onUserDeleted($userId): void
    {
        self::runUserCleanupBatch($userId);
    }

    /**
     * Trash up to {@see self::USER_CLEANUP_BATCH} shadow CPTs per type
     * for the given user. If any CPT type still has a full batch, a
     * continuation event is scheduled rather than blocking the current
     * admin request — a user who owns tens of thousands of shadow
     * posts previously caused the `deleted_user` hook to OOM or time
     * out under `posts_per_page => -1`.
     *
     * We query excluding `trash` so re-invocations don't re-enumerate
     * shadows we already trashed in an earlier batch (wp_trash_post
     * does not change post_author).
     *
     * Gallery collection cleanup (collections rows attributed to this
     * user on other users' pages) runs only after the final batch so
     * we touch it exactly once, with scoped deletes that do not spill
     * across to other users' collections on the same posts.
     *
     * @param int $userId
     */
    public static function runUserCleanupBatch($userId): void
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $shadowTypes = function_exists('bcc_get_shadow_cpt_types')
            ? bcc_get_shadow_cpt_types()
            : ['validators', 'nft', 'builder', 'dao'];

        $hasMore = false;

        foreach ($shadowTypes as $cpt) {
            if (!post_type_exists($cpt)) {
                continue;
            }

            $owned = get_posts([
                'post_type'      => $cpt,
                // Exclude 'trash' so subsequent continuation batches
                // don't re-fetch rows we already trashed — wp_trash_post
                // leaves post_author intact and the author filter would
                // keep matching otherwise.
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'author'         => $userId,
                'posts_per_page' => self::USER_CLEANUP_BATCH,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($owned as $cptId) {
                if (class_exists(GalleryRepository::class)) {
                    GalleryRepository::deleteByPostId((int) $cptId);
                }
                wp_trash_post((int) $cptId);
            }

            if (count($owned) === self::USER_CLEANUP_BATCH) {
                $hasMore = true;
            }
        }

        if ($hasMore) {
            wp_schedule_single_event(time() + 30, self::USER_CLEANUP_HOOK, [$userId]);
            return;
        }

        // Final batch — purge any remaining collection rows still
        // attributed to this user on posts owned by OTHER users.
        // Scoped delete: only the user's own collections, not everything
        // on the affected posts (see GalleryRepository::deleteByUserId).
        if (class_exists(GalleryRepository::class)) {
            GalleryRepository::deleteByUserId($userId);
        }
    }
}
