<?php

namespace BCC\PeepSo\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomic named-lock repository.
 *
 * Backed by either the external object cache (Redis/Memcached) when
 * available, or by the wp_options table's UNIQUE KEY on option_name when
 * not. In both cases acquisition is atomic and includes TTL-based
 * self-healing for stale locks after a crashed process.
 */
final class LockRepository
{
    private const CACHE_GROUP   = 'bcc_locks';
    private const OPTION_PREFIX = '_bcc_lock_';

    /**
     * Attempt to acquire a lock. Returns true on success, false if held.
     *
     * @param string $key Opaque identifier; must be unique per protected resource.
     * @param int    $ttl Maximum hold time in seconds before a stale lock can be reclaimed.
     */
    public static function tryAcquire(string $key, int $ttl): bool
    {
        if (wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($key, 1, self::CACHE_GROUP, $ttl);
        }

        global $wpdb;
        $option = self::OPTION_PREFIX . $key;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $option,
            (string) time()
        ));

        if ($inserted) {
            return true;
        }

        $lockTime = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option
        ));

        if ($lockTime <= 0 || (time() - $lockTime) <= $ttl) {
            return false;
        }

        $reclaimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s
             WHERE option_name = %s AND option_value = %s",
            (string) time(),
            $option,
            (string) $lockTime
        ));

        return $reclaimed > 0;
    }

    public static function release(string $key): void
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($key, self::CACHE_GROUP);
            return;
        }

        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::OPTION_PREFIX . $key]);
    }
}
