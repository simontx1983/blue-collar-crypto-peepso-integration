<?php

namespace BCC\PeepSo\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gallery Repository (DB Layer)
 */
class GalleryRepository
{
    /* ======================================================
       TABLE HELPERS
    ====================================================== */

    private static function collections_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_collections';
    }

    private static function images_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_collection_images';
    }

    /* ======================================================
       COLLECTIONS
    ====================================================== */

    public static function get_or_create_collection(int $post_id, int $user_id, int $sort_order)
    {
        global $wpdb;
        $table = self::collections_table();

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id=%d AND sort_order=%d",
                $post_id,
                $sort_order
            )
        );

        if ($existing) {
            return $existing;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO $table (post_id, user_id, name, sort_order, image_count)
                 VALUES (%d, %d, %s, %d, %d)",
                $post_id,
                $user_id,
                'Collection ' . ($sort_order + 1),
                $sort_order,
                0
            )
        );

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id=%d AND sort_order=%d",
                $post_id,
                $sort_order
            )
        );
    }

    /* ======================================================
       IMAGES
    ====================================================== */

    public static function count_images(int $collection_id): int
    {
        global $wpdb;
        $table = self::images_table();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE collection_id=%d",
                $collection_id
            )
        );
    }

    public static function insert_image(int $collection_id, array $data): int
    {
        global $wpdb;
        $table = self::images_table();

        $next_order = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM $table WHERE collection_id = %d",
                $collection_id
            )
        );

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

        return $image_id;
    }

    public static function get_images_paged(int $collection_id, int $page = 1, int $per_page = 12): array
    {
        global $wpdb;
        $table = self::images_table();

        $page = max(1, $page);
        $per_page = max(1, min(50, $per_page));
        $offset = ($page - 1) * $per_page;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
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

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total
        ];
    }

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

        return true;
    }

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
                "SELECT * FROM $table WHERE collection_id = %d AND id IN ($placeholders)",
                ...array_merge([$collection_id], $image_ids)
            )
        );

        $found_map = [];
        foreach ($found as $img) {
            $found_map[(int) $img->id] = $img;
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
            $del_ids = array_map(function ($img) { return (int) $img->id; }, $deleted);
            $del_placeholders = implode(',', array_fill(0, count($del_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table WHERE id IN ($del_placeholders)",
                    ...$del_ids
                )
            );

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . self::collections_table() . "
                     SET image_count = GREATEST(image_count - %d, 0)
                     WHERE id = %d",
                    count($del_ids),
                    $collection_id
                )
            );
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    public static function delete_image(int $image_id, int $collection_id = 0)
    {
        global $wpdb;
        $table = self::images_table();

        if ($collection_id > 0) {
            $image = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE id=%d AND collection_id=%d",
                    $image_id,
                    $collection_id
                )
            );
        } else {
            $image = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE id=%d",
                    $image_id
                )
            );
        }

        if (!$image) return false;

        $wpdb->delete($table, ['id' => $image_id], ['%d']);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::collections_table() . "
                 SET image_count = GREATEST(image_count - 1, 0)
                 WHERE id=%d",
                $image->collection_id
            )
        );

        return $image;
    }
}
