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
            return is_array($cached) ? $cached : [];
        }

        $result = [];
        foreach (self::getCategoryRowsForPage($pageId) as $row) {
            $result[] = (int) $row->cat_id;
        }

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
     * Get category rows for a given page (category_id exposed as 'cat_id').
     *
     * Rows are hydrated to a shaped stdClass with a pre-cast integer
     * ->cat_id — callers receive typed data, not raw wpdb strings.
     *
     * @param int $pageId PeepSo page ID.
     * @return list<object{cat_id: int}>
     */
    public static function getCategoryRowsForPage(int $pageId): array
    {
        if (!self::tableExists()) {
            return [];
        }

        global $wpdb;
        $table = self::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::CAT_COL . " AS cat_id FROM {$table} WHERE " . self::PAGE_COL . " = %d",
            $pageId
        ));

        if ($rows !== null && !is_array($rows)) {
            throw new \RuntimeException(
                '[PeepSoPageRepository] getCategoryRowsForPage: $wpdb->get_results returned non-array, non-null value'
            );
        }

        $hydrated = [];
        foreach ((array) $rows as $row) {
            if (!is_object($row)) {
                throw new \RuntimeException(
                    '[PeepSoPageRepository] getCategoryRowsForPage: non-object row in result set'
                );
            }
            $a = (array) $row;
            if (!array_key_exists('cat_id', $a)) {
                throw new \LogicException(
                    "[PeepSoPageRepository] Missing required column 'cat_id' in peepso_page_categories result — SELECT alias drift?"
                );
            }
            $hydrated[] = (object) [
                'cat_id' => (int) $a['cat_id'],
            ];
        }
        return $hydrated;
    }

    /**
     * Get all page IDs assigned to a given category.
     *
     * @param int $categoryId PeepSo category post ID.
     * @return int[]
     */
    public static function getPageIdsByCategory(int $categoryId): array
    {
        if (!self::tableExists()) {
            return [];
        }

        global $wpdb;
        $table = self::tableName();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT " . self::PAGE_COL . " FROM {$table} WHERE " . self::CAT_COL . " = %d",
            $categoryId
        ));

        return array_map('intval', (array) $rows);
    }

}
