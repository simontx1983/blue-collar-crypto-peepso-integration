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
     *
     * Self-heals duplicate counter rows for the same (post_id, meta_key)
     * pair on the way in. WordPress's postmeta table has no UNIQUE
     * constraint on (post_id, meta_key), and add_post_meta($unique=true)
     * is SELECT-then-INSERT (not atomic), so concurrent first-saves can
     * leak two rows. If left alone, every subsequent CAS UPDATE affects
     * BOTH rows (the WHERE clause matches both), but get_post_meta(...,
     * $single=true) returns an arbitrary one — the counters drift per
     * row and the user sees random spurious 409s. Consolidate-first
     * keeps the CAS single-row by construction.
     */
    public static function compareAndBumpVersion(int $post_id, int $expected): bool
    {
        global $wpdb;

        self::consolidateIfDuplicated($post_id);

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
     * If more than one version-counter row exists for this post, keep
     * the row with the highest meta_value (furthest-advanced writer is
     * the safest floor — a lagging row would under-report the current
     * version and accept stale CAS from a client) and delete the rest.
     *
     * One SELECT COUNT guard means the common no-duplicates case costs
     * a single indexed count lookup and does not rewrite postmeta.
     */
    private static function consolidateIfDuplicated(int $post_id): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
              WHERE post_id = %d AND meta_key = %s",
            $post_id,
            self::VERSION_META_KEY
        ));

        if ($count <= 1) {
            return;
        }

        // Keep the row with the largest meta_value (tie-broken by
        // lowest meta_id so the canonical row is stable across calls).
        $keepId = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
              WHERE post_id = %d AND meta_key = %s
           ORDER BY CAST(meta_value AS SIGNED) DESC, meta_id ASC
              LIMIT 1",
            $post_id,
            self::VERSION_META_KEY
        ));

        if (!$keepId) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
              WHERE post_id = %d
                AND meta_key = %s
                AND meta_id <> %d",
            $post_id,
            self::VERSION_META_KEY,
            (int) $keepId
        ));

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-peepso] consolidated duplicate _bcc_vis_version rows', [
                'post_id'   => $post_id,
                'kept'      => (int) $keepId,
                'had_rows'  => $count,
            ]);
        }
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
     * Seed the initial version row.
     *
     * Seeds to 0, not 1: a freshly-rendered FieldRenderer for a post
     * that has never been saved reads `get_post_meta(..., true)` which
     * returns '' for a missing row and casts to int 0. The client
     * echoes that back as `vis_version=0`. If we seeded to 1 here,
     * {@see self::compareAndBumpVersion()} would compare client 0
     * against stored 1 on every first-ever save and reject it with
     * a spurious 409.
     *
     * Uses a DB-level "insert only if absent" statement rather than
     * add_post_meta($unique=true). The WordPress helper is
     * SELECT-then-INSERT without a transaction, which races under
     * concurrent first-saves: two requests both see "no row", both
     * INSERT, two rows now exist. Postmeta has no UNIQUE constraint
     * to catch that. The `INSERT ... WHERE NOT EXISTS` form runs the
     * existence check and the insert as a single statement, so only
     * one concurrent seeder can insert. Matches the consolidator in
     * compareAndBumpVersion() belt-and-suspenders.
     */
    public static function seedVersion(int $post_id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT %d, %s, '0' FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key = %s
             )",
            $post_id,
            self::VERSION_META_KEY,
            $post_id,
            self::VERSION_META_KEY
        ));
    }
}
