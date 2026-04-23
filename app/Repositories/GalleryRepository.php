<?php

namespace BCC\PeepSo\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gallery Repository (DB Layer)
 *
 * Rows returned by $wpdb are reshaped into typed stdClass objects via
 * the hydrate helpers below. Callers receive pre-cast scalar properties
 * (int where numeric, string otherwise) — no raw wpdb strings escape
 * this class.
 *
 * @phpstan-type CollectionRow object{
 *     id: int,
 *     post_id: int,
 *     user_id: int,
 *     name: string,
 *     sort_order: int,
 *     image_count: int,
 *     created_at: string
 * }
 * @phpstan-type ImageRow object{
 *     id: int,
 *     collection_id: int,
 *     file: string,
 *     url: string,
 *     thumbnail: string,
 *     size: int,
 *     sort_order: int,
 *     created_at: string
 * }
 */
class GalleryRepository
{
    /** Column set written by bcc_collections schema — every field is NOT NULL. */
    private const COLL_REQUIRED = [
        'id', 'post_id', 'user_id', 'name', 'sort_order', 'image_count', 'created_at',
    ];

    /** Column set written by bcc_collection_images schema — every field is NOT NULL. */
    private const IMG_REQUIRED = [
        'id', 'collection_id', 'file', 'url', 'thumbnail', 'size', 'sort_order', 'created_at',
    ];

    /**
     * Verify a raw wpdb row carries every required column. Detects SELECT-list
     * drift (aliases renamed, columns dropped) at the boundary so we never
     * return a row with silently-defaulted fields.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $required
     */
    private static function assertColumns(array $data, array $required, string $context): void
    {
        foreach ($required as $col) {
            if (!array_key_exists($col, $data)) {
                throw new \LogicException(
                    "[GalleryRepository] Missing required column '{$col}' in {$context} result — SELECT list drift?"
                );
            }
        }
    }

    /**
     * Hydrate a raw wpdb row into a shaped CollectionRow object.
     * All columns are NOT NULL in the schema, so missing keys are a contract
     * violation (LogicException), not a run-time condition to paper over.
     *
     * @phpstan-return CollectionRow
     */
    private static function hydrateCollection(object $row): object
    {
        $a = (array) $row;
        self::assertColumns($a, self::COLL_REQUIRED, 'bcc_collections');
        return (object) [
            'id'          => (int) $a['id'],
            'post_id'     => (int) $a['post_id'],
            'user_id'     => (int) $a['user_id'],
            'name'        => (string) $a['name'],
            'sort_order'  => (int) $a['sort_order'],
            'image_count' => (int) $a['image_count'],
            'created_at'  => (string) $a['created_at'],
        ];
    }

    /**
     * Hydrate a raw wpdb row into a shaped ImageRow object.
     * All columns are NOT NULL in the schema — see hydrateCollection() notes.
     *
     * @phpstan-return ImageRow
     */
    private static function hydrateImage(object $row): object
    {
        $a = (array) $row;
        self::assertColumns($a, self::IMG_REQUIRED, 'bcc_collection_images');
        return (object) [
            'id'            => (int) $a['id'],
            'collection_id' => (int) $a['collection_id'],
            'file'          => (string) $a['file'],
            'url'           => (string) $a['url'],
            'thumbnail'     => (string) $a['thumbnail'],
            'size'          => (int) $a['size'],
            'sort_order'    => (int) $a['sort_order'],
            'created_at'    => (string) $a['created_at'],
        ];
    }

    /** @var string Explicit column list for bcc_collections. */
    private const COLL_COLUMNS = 'id, post_id, user_id, name, sort_order, image_count, created_at';

    /** @var string Explicit column list for bcc_collection_images. */
    private const IMG_COLUMNS = 'id, collection_id, file, url, thumbnail, size, sort_order, created_at';

    private const CACHE_GROUP = 'bcc_gallery';
    private const CACHE_TTL   = 60; // seconds

    /**
     * Invalidate all caches for a given collection (count + paged images).
     * Uses a generation counter so we don't need to know every page/per_page combo.
     */
    private static function invalidateCollectionCaches(int $collection_id): void
    {
        wp_cache_delete("img_count_{$collection_id}", self::CACHE_GROUP);
        wp_cache_incr("img_gen_{$collection_id}", 1, self::CACHE_GROUP)
            || wp_cache_set("img_gen_{$collection_id}", 1, self::CACHE_GROUP, 0);

        // Also invalidate post-level aggregate caches.
        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM " . self::collections_table() . " WHERE id = %d LIMIT 1",
            $collection_id
        ));
        if ($post_id) {
            self::invalidatePostCaches($post_id);
        }
    }

    /**
     * Invalidate post-level aggregate caches (image count, collection count).
     */
    private static function invalidatePostCaches(int $post_id): void
    {
        wp_cache_delete("post_img_count_{$post_id}", self::CACHE_GROUP);
        wp_cache_delete("coll_count_{$post_id}", self::CACHE_GROUP);
    }

    private static function imgCacheKey(int $collection_id, int $page, int $per_page): string
    {
        $gen = (int) wp_cache_get("img_gen_{$collection_id}", self::CACHE_GROUP);
        return "imgs_{$collection_id}_{$page}_{$per_page}_g{$gen}";
    }

    /* ======================================================
       TABLE HELPERS
    ====================================================== */

    private static function collections_table(): string
    {
        if (class_exists('\\BCC\\Core\\DB\\DB')) {
            return \BCC\Core\DB\DB::table('collections');
        }
        global $wpdb;
        return $wpdb->prefix . 'bcc_collections';
    }

    private static function images_table(): string
    {
        if (class_exists('\\BCC\\Core\\DB\\DB')) {
            return \BCC\Core\DB\DB::table('collection_images');
        }
        global $wpdb;
        return $wpdb->prefix . 'bcc_collection_images';
    }

    /* ======================================================
       COLLECTIONS
    ====================================================== */

    /**
     * Read-only collection lookup (no INSERT). Used by render_view().
     *
     * @phpstan-return CollectionRow|null
     */
    public static function get_collection(int $post_id, int $sort_order): ?object
    {
        $cache_key = "coll_{$post_id}_{$sort_order}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_object($cached) ? self::hydrateCollection($cached) : null;
        }

        global $wpdb;
        $table = self::collections_table();
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLL_COLUMNS . " FROM $table WHERE post_id=%d AND sort_order=%d LIMIT 1",
                $post_id,
                $sort_order
            )
        );

        wp_cache_set($cache_key, $result ?: 0, self::CACHE_GROUP, self::CACHE_TTL);
        return is_object($result) ? self::hydrateCollection($result) : null;
    }

    /**
     * @phpstan-return CollectionRow|null
     */
    public static function get_or_create_collection(int $post_id, int $user_id, int $sort_order): ?object
    {
        global $wpdb;
        $table = self::collections_table();

        // Fast path: check without locking.
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLL_COLUMNS . " FROM $table WHERE post_id=%d AND sort_order=%d LIMIT 1",
                $post_id,
                $sort_order
            )
        );

        if (is_object($existing)) {
            return self::hydrateCollection($existing);
        }

        // Resolve the page owner so the collection is always attributed
        // to the post author, not whoever triggered the creation.
        $post_obj = get_post($post_id);
        $owner_id = $post_obj ? (int) $post_obj->post_author : $user_id;

        // Slow path: serialize concurrent creators via INSERT IGNORE
        // inside a transaction so the subsequent SELECT is guaranteed
        // to return the winning row.
        $wpdb->query('START TRANSACTION');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO $table (post_id, user_id, name, sort_order, image_count)
                 VALUES (%d, %d, %s, %d, %d)",
                $post_id,
                $owner_id,
                'Collection ' . ($sort_order + 1),
                $sort_order,
                0
            )
        );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLL_COLUMNS . " FROM $table WHERE post_id=%d AND sort_order=%d LIMIT 1",
                $post_id,
                $sort_order
            )
        );

        $wpdb->query('COMMIT');

        wp_cache_delete("coll_{$post_id}_{$sort_order}", self::CACHE_GROUP);
        self::invalidatePostCaches($post_id);

        return is_object($row) ? self::hydrateCollection($row) : null;
    }

    /* ======================================================
       IMAGES
    ====================================================== */

    public static function count_images(int $collection_id): int
    {
        $cache_key = "img_count_{$collection_id}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = self::images_table();

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE collection_id=%d",
                $collection_id
            )
        );

        wp_cache_set($cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL);
        return $count;
    }

    /**
     * Count total images across ALL collections for a given post.
     */
    public static function count_images_for_post(int $post_id): int
    {
        $cache_key = "post_img_count_{$post_id}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $coll_table = self::collections_table();

        // Use SUM(image_count) from the collections table instead of a
        // JOIN, since image_count is already maintained on each collection.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(image_count), 0) FROM {$coll_table} WHERE post_id = %d",
            $post_id
        ));

        wp_cache_set($cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL);
        return $count;
    }

    /**
     * Count collections for a given post.
     */
    public static function count_collections(int $post_id): int
    {
        $cache_key = "coll_count_{$post_id}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = self::collections_table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        wp_cache_set($cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL);
        return $count;
    }

    /** @param array<string, mixed> $data */
    public static function insert_image(int $collection_id, array $data, int $max_images = 0): int
    {
        global $wpdb;
        $table = self::images_table();

        $wpdb->query('START TRANSACTION');

        // Lock the collection's image rows so concurrent inserts serialize.
        // FOR UPDATE ensures two concurrent requests cannot read the same MAX.
        $next_order = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM $table WHERE collection_id = %d FOR UPDATE",
                $collection_id
            )
        );

        // Atomic cap check inside the transaction — prevents race where two
        // concurrent uploads both pass the controller-level count check.
        if ($max_images > 0) {
            $current_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE collection_id = %d",
                    $collection_id
                )
            );
            if ($current_count >= $max_images) {
                $wpdb->query('ROLLBACK');
                return -1;
            }
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'collection_id' => $collection_id,
                'file'          => $data['file'],
                'url'           => $data['url'],
                'thumbnail'     => $data['thumbnail'],
                'size'          => $data['size'],
                'sort_order'    => $next_order,
            ],
            ['%d','%s','%s','%s','%d','%d']
        );

        if ($inserted === false) {
            $wpdb->query('ROLLBACK');
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-peepso] image_insert_failed', [
                    'collection_id' => $collection_id,
                    'db_error'      => $wpdb->last_error,
                ]);
            } else {
                error_log('[bcc-peepso] image_insert_failed collection_id=' . $collection_id . ' error=' . $wpdb->last_error);
            }
            return 0;
        }

        $image_id = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::collections_table() . "
                 SET image_count = image_count + 1
                 WHERE id=%d",
                $collection_id
            )
        );

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return 0;
        }

        $wpdb->query('COMMIT');

        self::invalidateCollectionCaches($collection_id);

        return $image_id;
    }

    /** @phpstan-return array{items: list<ImageRow>, total: int} */
    public static function get_images_paged(int $collection_id, int $page = 1, int $per_page = 12): array
    {
        $page = max(1, $page);
        $per_page = max(1, min(50, $per_page));
        $offset = ($page - 1) * $per_page;

        $cache_key = self::imgCacheKey($collection_id, $page, $per_page);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached) && isset($cached['items'], $cached['total'])) {
            return $cached;
        }

        global $wpdb;
        $table = self::images_table();

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::IMG_COLUMNS . " FROM $table
                 WHERE collection_id=%d
                 ORDER BY sort_order ASC, id ASC
                 LIMIT %d OFFSET %d",
                $collection_id,
                $per_page,
                $offset
            )
        );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE collection_id=%d",
                $collection_id
            )
        );

        if ($items !== null && !is_array($items)) {
            throw new \RuntimeException(
                '[GalleryRepository] get_images_paged: $wpdb->get_results returned non-array, non-null value'
            );
        }

        $hydrated = [];
        foreach ((array) $items as $row) {
            if (!is_object($row)) {
                // Silently skipping would corrupt pagination / image counts.
                throw new \RuntimeException(
                    '[GalleryRepository] get_images_paged: non-object row in result set'
                );
            }
            $hydrated[] = self::hydrateImage($row);
        }

        $result = [
            'items' => $hydrated,
            'total' => $total,
        ];

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /** @param array<int, int> $ordered_ids */
    public static function update_sort_orders(int $collection_id, array $ordered_ids): bool
    {
        global $wpdb;
        $table = self::images_table();

        $ordered_ids = array_values(array_filter(array_map('intval', $ordered_ids)));

        if (!$ordered_ids) return false;

        $placeholders = implode(',', array_fill(0, count($ordered_ids), '%d'));

        $valid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                 WHERE collection_id=%d AND id IN ($placeholders)",
                ...array_merge([$collection_id], $ordered_ids)
            )
        );

        if ($valid !== count($ordered_ids)) {
            return false;
        }

        $case_parts = [];
        $case_args  = [];
        foreach ($ordered_ids as $index => $id) {
            $case_parts[] = 'WHEN %d THEN %d';
            $case_args[]  = $id;
            $case_args[]  = $index;
        }
        $case_sql     = implode(' ', $case_parts);
        $placeholders = implode(',', array_fill(0, count($ordered_ids), '%d'));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                 SET sort_order = CASE id $case_sql END
                 WHERE collection_id = %d AND id IN ($placeholders)",
                ...array_merge($case_args, [$collection_id], $ordered_ids)
            )
        );

        self::invalidateCollectionCaches($collection_id);

        return true;
    }

    /**
     * @param array<int, int> $image_ids
     * @phpstan-return array{deleted: list<ImageRow>, failed: list<int>}
     */
    public static function delete_images_bulk(array $image_ids, int $collection_id): array
    {
        global $wpdb;
        $table = self::images_table();

        $image_ids = array_values(array_filter(array_map('intval', $image_ids)));
        if (!$image_ids) {
            return ['deleted' => [], 'failed' => []];
        }

        $placeholders = implode(',', array_fill(0, count($image_ids), '%d'));
        $found = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::IMG_COLUMNS . " FROM $table WHERE collection_id = %d AND id IN ($placeholders)",
                ...array_merge([$collection_id], $image_ids)
            )
        );

        if ($found !== null && !is_array($found)) {
            throw new \RuntimeException(
                '[GalleryRepository] delete_images_bulk: $wpdb->get_results returned non-array, non-null value'
            );
        }

        $found_map = [];
        foreach ((array) $found as $img) {
            if (!is_object($img)) {
                throw new \RuntimeException(
                    '[GalleryRepository] delete_images_bulk: non-object row in result set'
                );
            }
            $hydrated = self::hydrateImage($img);
            $found_map[$hydrated->id] = $hydrated;
        }

        $deleted = [];
        $failed  = [];

        foreach ($image_ids as $id) {
            if (isset($found_map[$id])) {
                $deleted[] = $found_map[$id];
            } else {
                $failed[] = $id;
            }
        }

        if (!empty($deleted)) {
            $del_ids = array_map(static fn(object $img): int => $img->id, $deleted);
            $del_placeholders = implode(',', array_fill(0, count($del_ids), '%d'));

            $wpdb->query('START TRANSACTION');

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table WHERE id IN ($del_placeholders)",
                    ...$del_ids
                )
            );

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return ['deleted' => [], 'failed' => $image_ids];
            }

            $update_result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . self::collections_table() . "
                     SET image_count = GREATEST(image_count - %d, 0)
                     WHERE id = %d",
                    count($del_ids),
                    $collection_id
                )
            );

            if ($update_result === false) {
                $wpdb->query('ROLLBACK');
                return ['deleted' => [], 'failed' => $image_ids];
            }

            $wpdb->query('COMMIT');

            self::invalidateCollectionCaches($collection_id);
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @phpstan-return ImageRow|false */
    public static function delete_image(int $image_id, int $collection_id = 0)
    {
        global $wpdb;
        $table = self::images_table();

        if ($collection_id > 0) {
            $image = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT " . self::IMG_COLUMNS . " FROM $table WHERE id=%d AND collection_id=%d",
                    $image_id,
                    $collection_id
                )
            );
        } else {
            $image = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT " . self::IMG_COLUMNS . " FROM $table WHERE id=%d",
                    $image_id
                )
            );
        }

        if (!is_object($image)) return false;

        $hydrated = self::hydrateImage($image);

        $wpdb->query('START TRANSACTION');

        $wpdb->delete($table, ['id' => $image_id], ['%d']);

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::collections_table() . "
                 SET image_count = GREATEST(image_count - 1, 0)
                 WHERE id=%d",
                $hydrated->collection_id
            )
        );

        if ($update_result === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');

        self::invalidateCollectionCaches($hydrated->collection_id);

        return $hydrated;
    }

    /**
     * Delete all collections and their images for a given post.
     * Used during shadow CPT trash/delete to prevent orphaned rows.
     */
    public static function deleteByPostId(int $post_id): void
    {
        global $wpdb;

        $coll_table = self::collections_table();
        $img_table  = self::images_table();

        $collection_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$coll_table} WHERE post_id = %d", $post_id)
        );

        if (empty($collection_ids)) return;

        $placeholders = implode(',', array_fill(0, count($collection_ids), '%d'));

        $wpdb->query('START TRANSACTION');

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$img_table} WHERE collection_id IN ({$placeholders})",
            ...$collection_ids
        ));

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$coll_table} WHERE id IN ({$placeholders})",
            ...$collection_ids
        ));

        if ($wpdb->last_error !== '') {
            $wpdb->query('ROLLBACK');
            return;
        }

        $wpdb->query('COMMIT');

        foreach ($collection_ids as $cid) {
            self::invalidateCollectionCaches((int) $cid);
        }
        self::invalidatePostCaches($post_id);
    }

    /**
     * Delete every collection + image owned by the given user.
     *
     * Called from the deleted_user lifecycle hook so that bcc_collections
     * rows referencing a no-longer-existent user_id do not persist
     * indefinitely (which would also leave the companion
     * bcc_collection_images rows dangling).
     *
     * Scoped strictly to collections whose user_id matches. A prior
     * implementation delegated to deleteByPostId() which deletes EVERY
     * collection on the affected post — that destroyed collaborators'
     * data on any page the deleted user had contributed to (silent
     * cross-user data loss). This version deletes only the user's own
     * collections + their images, leaving other users' collections on
     * the same post untouched.
     */
    public static function deleteByUserId(int $user_id): void
    {
        global $wpdb;

        if ($user_id <= 0) {
            return;
        }

        $coll_table = self::collections_table();
        $img_table  = self::images_table();

        /** @var list<object{id: int|numeric-string, post_id: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id FROM {$coll_table} WHERE user_id = %d",
            $user_id
        ));

        if (!is_array($rows) || empty($rows)) {
            return;
        }

        $collection_ids = [];
        $post_ids       = [];
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $collection_ids[] = (int) $row->id;
            $post_ids[(int) $row->post_id] = true;
        }
        $collection_ids = array_values(array_unique(array_filter($collection_ids)));
        if (empty($collection_ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($collection_ids), '%d'));

        $wpdb->query('START TRANSACTION');

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$img_table} WHERE collection_id IN ({$placeholders})",
            ...$collection_ids
        ));
        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$coll_table} WHERE id IN ({$placeholders})",
            ...$collection_ids
        ));
        if ($wpdb->last_error !== '') {
            $wpdb->query('ROLLBACK');
            return;
        }

        $wpdb->query('COMMIT');

        foreach ($collection_ids as $cid) {
            self::invalidateCollectionCaches($cid);
        }
        foreach (array_keys($post_ids) as $pid) {
            self::invalidatePostCaches((int) $pid);
        }
    }
}
