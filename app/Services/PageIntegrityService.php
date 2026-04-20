<?php

namespace BCC\PeepSo\Services;

use BCC\PeepSo\Repositories\PeepSoPageRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the page ↔ shadow-CPT relationship internally consistent.
 *
 * Responsibilities:
 *   - Clear the `_bcc_integrity_ok` flag whenever a page's categories
 *     may have changed, forcing a re-verify on next load.
 *   - Invalidate per-page category caches and the global category map
 *     when category-related state mutates.
 *   - Detect and self-heal category-map cache drift (hourly, admin only).
 *   - Admin UX guard-rails around linked shadow CPTs (title lock, notice).
 */
final class PageIntegrityService
{
    private const INTEGRITY_META       = '_bcc_integrity_ok';
    private const CATEGORY_MAP_CACHE   = 'bcc_category_map';
    private const CATEGORY_MAP_GROUP   = 'bcc_peepso';
    private const DRIFT_THROTTLE_KEY   = 'bcc_catmap_drift_check';
    private const LINKED_CPT_TYPES     = ['validators', 'nft', 'builder', 'dao'];

    public static function register(): void
    {
        add_action('save_post_peepso-page', [self::class, 'invalidatePageIntegrity'], 5);
        add_action('save_post_peepso-page-cat', [self::class, 'invalidateCategoryMap']);
        add_action('admin_init', [self::class, 'lockLinkedCptTitles']);
        add_action('admin_init', [self::class, 'detectCategoryMapDrift']);
        add_action('admin_notices', [self::class, 'renderLinkedCptNotice']);
    }

    /**
     * When a page is saved, drop its integrity flag and category cache
     * so the next visit re-verifies shadow CPT linkage.
     *
     * @param int $postId
     */
    public static function invalidatePageIntegrity($postId): void
    {
        if (wp_is_post_revision($postId)) {
            return;
        }
        delete_post_meta($postId, self::INTEGRITY_META);
        PeepSoPageRepository::invalidateCategoryCache((int) $postId);
    }

    /**
     * When a peepso-page-cat post is saved, the category map is dirty.
     * Flush the global map cache and invalidate per-page caches for
     * every page that referenced this category.
     *
     * @param int $postId
     */
    public static function invalidateCategoryMap($postId): void
    {
        if (wp_is_post_revision($postId)) {
            return;
        }

        wp_cache_delete(self::CATEGORY_MAP_CACHE, self::CATEGORY_MAP_GROUP);

        if (!PeepSoPageRepository::tableExists()) {
            return;
        }

        foreach (PeepSoPageRepository::getPageIdsByCategory((int) $postId) as $pageId) {
            PeepSoPageRepository::invalidateCategoryCache($pageId);
        }
    }

    /**
     * Strip title support from linked shadow CPTs so the source page
     * remains the single source of truth for titles.
     */
    public static function lockLinkedCptTitles(): void
    {
        foreach (self::LINKED_CPT_TYPES as $cpt) {
            remove_post_type_support($cpt, 'title');
        }
    }

    /**
     * Admin notice on a linked shadow CPT edit screen explaining why
     * the title is locked.
     */
    public static function renderLinkedCptNotice(): void
    {
        global $post;
        if (!$post) {
            return;
        }
        if (!in_array($post->post_type, self::LINKED_CPT_TYPES, true)) {
            return;
        }
        if (!get_post_meta($post->ID, '_peepso_page_id', true)) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '⚠️ This content is linked to a PeepSo Page. Title and some settings are managed by the source page.';
        echo '</p></div>';
    }

    /**
     * Hourly, admin-only drift check against the category map cache.
     * Auto-repair (cache flush + log) happens inside
     * bcc_category_map_is_fresh().
     */
    public static function detectCategoryMapDrift(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!function_exists('bcc_category_map_is_fresh')) {
            return;
        }
        if (get_transient(self::DRIFT_THROTTLE_KEY)) {
            return;
        }
        set_transient(self::DRIFT_THROTTLE_KEY, 1, HOUR_IN_SECONDS);

        bcc_category_map_is_fresh();
    }
}
