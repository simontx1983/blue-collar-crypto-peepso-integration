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
     *
     * NOTE: this is unconditional — it does NOT guard against
     * concurrent writers. For the race-safe compare-and-swap used by
     * the visibility REST endpoint, call
     * {@see self::compareAndBumpVersion()} instead.
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
     * Atomic compare-and-swap: bump the version ONLY if the current
     * stored value matches $expected. Returns true on success, false
     * on mismatch (someone else wrote between the client's read and
     * this write — 409 territory) or on a missing counter row (caller
     * should seed and retry once).
     *
     * The check-then-set pattern that lived in bcc_set_field_visibility()
     * previously is NOT safe under concurrency: two writers could both
     * read version=N, both PHP-compare-equal, both write their field
     * value, and both bump — the second silently clobbers the first.
     * This method makes the bump conditional at the DB level so one
     * writer always loses cleanly.
     */
    public static function compareAndBumpVersion(int $post_id, int $expected): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
                SET meta_value = meta_value + 1
              WHERE post_id = %d
                AND meta_key = %s
                AND meta_value = %d",
            $post_id,
            self::VERSION_META_KEY,
            $expected
        ));

        return $updated !== false && $updated > 0;
    }

    /**
     * Undo a prior {@see self::compareAndBumpVersion()} when the
     * follow-up field write failed. Clamps at 0 so a corrupted counter
     * cannot go negative.
     *
     * Not a perfect rollback — a reader arriving between the bump and
     * this decrement sees an inflated version and retries, which is
     * correct-under-pessimism (worst case: the user retries once).
     */
    public static function decrementVersion(int $post_id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
                SET meta_value = GREATEST(CAST(meta_value AS SIGNED) - 1, 0)
              WHERE post_id = %d
                AND meta_key = %s",
            $post_id,
            self::VERSION_META_KEY
        ));
    }

    /**
     * Seed the initial version row. Safe to call multiple times — the
     * underlying add_post_meta `$unique=true` flag prevents duplicates.
     *
     * Seeds to 0, not 1: a freshly-rendered FieldRenderer for a post
     * that has never been saved reads `get_post_meta(..., true)` which
     * returns '' for a missing row and casts to int 0. The client
     * echoes that back as `vis_version=0`. If we seeded to 1 here,
     * {@see self::compareAndBumpVersion()} would compare client 0
     * against stored 1 on every first-ever save and reject it with
     * a spurious 409. Seeding to 0 keeps bootstrap and the CAS aligned:
     * first save matches, bumps to 1; subsequent saves observe the
     * post-bump value. Existing rows that already hold 1 (created by
     * the old unconditional bump path) continue to work because
     * add_post_meta with $unique=true is a no-op when the row exists.
     */
    public static function seedVersion(int $post_id): void
    {
        add_post_meta($post_id, self::VERSION_META_KEY, 0, true);
    }
}
