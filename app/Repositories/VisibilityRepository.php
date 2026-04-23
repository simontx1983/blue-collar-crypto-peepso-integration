<?php

namespace BCC\PeepSo\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field visibility persistence.
 *
 * Exists so the atomic visibility-version bump can live inside a
 * Repository per the Repository-only DB-access guardrail. The bump
 * MUST be an atomic single-statement UPDATE (not a read-modify-write
 * via get_post_meta / update_post_meta) to avoid lost-update races
 * between concurrent visibility saves on the same post.
 */
final class VisibilityRepository
{
    public const VERSION_META_KEY = '_bcc_vis_version';

    /**
     * Atomically increment the visibility-version counter for a post.
     *
     * Returns true when the counter row existed and was bumped, false
     * otherwise (caller should create the initial row via
     * {@see self::seedVersion()} if needed).
     */
    public static function bumpVersion(int $post_id): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
                SET meta_value = meta_value + 1
              WHERE post_id = %d
                AND meta_key = %s",
            $post_id,
            self::VERSION_META_KEY
        ));

        return $updated !== false && $updated > 0;
    }

    /**
     * Seed the initial version row. Safe to call multiple times — the
     * underlying add_post_meta `$unique=true` flag prevents duplicates.
     */
    public static function seedVersion(int $post_id): void
    {
        add_post_meta($post_id, self::VERSION_META_KEY, 1, true);
    }
}
