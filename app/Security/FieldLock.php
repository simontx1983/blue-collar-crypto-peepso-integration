<?php

namespace BCC\PeepSo\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-(post_id, field) advisory lock for ACF repeater mutations.
 *
 * All three repeater mutators — add_new, per-row update, reorder, and
 * delete — read a rows array from ACF, mutate it in PHP, and write it
 * back. Without serialization on the shared (post_id, field) key the
 * second writer overwrites the first: deletes get resurrected,
 * concurrent add_new loses one entry, reorder undoes a concurrent
 * delete. Every path that does read-modify-write on the same ACF field
 * must route through this helper.
 *
 * Lock scope is `(post_id, field)` — different fields on the same post
 * stay parallel. Key is md5'd to stay under identifier-length limits
 * for the underlying GET_LOCK / wp_cache_add backend.
 */
final class FieldLock
{
    /** Short TTL — each mutation completes in well under a second. */
    public const DEFAULT_TTL = 5;

    /**
     * Attempt to acquire the lock. Returns the lock key on success, null
     * on contention / backend unavailable. Caller MUST pass the returned
     * key to {@see self::release()} in a finally block.
     *
     * When the AdvisoryLock class is unavailable (bcc-core inactive
     * after boot) we return null so the caller can reject the request —
     * silent fall-through to an unlocked read-modify-write is exactly
     * what this helper exists to prevent.
     */
    public static function acquire(int $post_id, string $field, int $ttl = self::DEFAULT_TTL): ?string
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return null;
        }
        $key = self::keyFor($post_id, $field);
        return \BCC\Core\DB\AdvisoryLock::acquire($key, $ttl) ? $key : null;
    }

    public static function release(?string $key): void
    {
        if ($key === null) {
            return;
        }
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            \BCC\Core\DB\AdvisoryLock::release($key);
        }
    }

    private static function keyFor(int $post_id, string $field): string
    {
        return 'bcc_repeater_' . $post_id . '_' . md5($field);
    }
}
