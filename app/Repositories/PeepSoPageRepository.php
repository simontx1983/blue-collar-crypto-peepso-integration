<?php

namespace BCC\PeepSo\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo Page Category Repository
 *
 * Centralises all queries against the PeepSo `peepso_page_categories` table.
 * Column names are hardcoded (pm_page_id, pm_cat_id) — the DESCRIBE-based
 * discovery in page-to-cpt-sync.php was unnecessary runtime overhead for a
 * known schema.
 *
 * @package BCC\PeepSo\Repositories
 */
final class PeepSoPageRepository
{
    private const PAGE_COL = 'pm_page_id';
    private const CAT_COL  = 'pm_cat_id';

    /** Columns queried from the PeepSo page-categories relation table. */
    private const COLUMNS = 'pm_page_id, pm_cat_id';

    /**
     * Check if the PeepSo page-categories relation table exists.
     * Result is cached per-process.
     */
    public static function tableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        global $wpdb;
        $table = self::tableName();
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        return $exists;
    }

    /**
     * Get the full table name.
     */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'peepso_page_categories';
    }

    private const CACHE_GROUP = 'bcc_peepso_pages';
    private const CACHE_TTL   = 3600; // seconds

    /**
     * Get category IDs for a given page.
     *
     * Delegates to getCategoryRowsForPage() to avoid duplicating the query,
     * then extracts the integer IDs from the row objects.
     *
     * @param int $pageId PeepSo page ID.
     * @return array<int, int> Array of category post IDs.
     */
    public static function getCategoryIdsForPage(int $pageId): array
    {
        $cache_key = "cat_ids_{$pageId}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        $rows   = self::getCategoryRowsForPage($pageId);
        $result = array_map(fn(object $row): int => (int) $row->cat_id, $rows);

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /**
     * Invalidate the cached category IDs for a given page.
     *
     * Call this whenever a page's category assignments change so that
     * subsequent reads reflect the updated state.
     *
     * @param int $pageId PeepSo page ID.
     */
    public static function invalidateCategoryCache(int $pageId): void
    {
        wp_cache_delete("cat_ids_{$pageId}", self::CACHE_GROUP);
    }

    /**
     * Get category rows for a given page (category_id as 'cat_id').
     *
     * @param int $pageId PeepSo page ID.
     * @return object[] Array of objects with ->cat_id.
     */
    public static function getCategoryRowsForPage(int $pageId): array
    {
        if (!self::tableExists()) {
            return [];
        }

        global $wpdb;
        $table = self::tableName();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::CAT_COL . " AS cat_id FROM {$table} WHERE " . self::PAGE_COL . " = %d",
            $pageId
        ));
    }

}
