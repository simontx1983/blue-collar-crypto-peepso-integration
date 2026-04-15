<?php
if (!defined('ABSPATH')) exit;

/**
 * ======================================================
 * DATA INTEGRITY GUARD RAILS
 * ======================================================
 */

/**
 * Auto-create missing shadow CPT on page load.
 *
 * Gated on authentication to prevent unauthenticated visitors from
 * triggering wp_insert_post. Uses a transient lock to prevent
 * concurrent requests from creating duplicate CPTs.
 * Only marks integrity as OK when ALL shadow CPTs were created
 * (or already existed).
 */
add_action('wp', function () {
    if (!is_singular('peepso-page')) return;
    if (!is_user_logged_in()) return;

    global $post;
    if (!$post) return;

    $page_id = $post->ID;

    // Skip if integrity was already confirmed for this page.
    // This flag is cleared when the page's category changes (see hook below).
    if (get_post_meta($page_id, '_bcc_integrity_ok', true)) {
        return;
    }

    // Only the page owner should trigger shadow CPT creation —
    // visitors should not pay the wp_insert_post() cost.
    if (function_exists('bcc_user_is_owner') && !bcc_user_is_owner($page_id)) {
        return;
    }

    // Atomic lock with TTL: prevents stuck locks if the process crashes.
    //
    // Strategy:
    //   - With ext object cache (Redis): wp_cache_add is atomic (returns
    //     false if key exists). TTL provides self-healing.
    //   - Without ext object cache: use INSERT IGNORE into wp_options.
    //     The UNIQUE KEY on option_name provides atomicity. A timestamp
    //     value enables stale-lock detection for self-healing.
    //
    // The old transient approach (get then set) had a TOCTOU race under
    // concurrent requests, allowing duplicate shadow CPT creation.
    $lock_key = 'bcc_integrity_lock_' . $page_id;
    $lock_ttl = 30; // seconds

    if (wp_using_ext_object_cache()) {
        $lock_acquired = wp_cache_add($lock_key, 1, 'bcc_locks', $lock_ttl);
    } else {
        global $wpdb;
        $option_name = '_bcc_lock_' . $lock_key;

        // Atomic: INSERT IGNORE fails (returns 0) if the option_name already exists.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $option_name,
            (string) time()
        ));

        if ($inserted) {
            $lock_acquired = true;
        } else {
            // Lock exists — check if it's stale (older than TTL).
            $lock_time = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            ));
            if ($lock_time > 0 && (time() - $lock_time) > $lock_ttl) {
                // Stale lock — reclaim it atomically.
                $reclaimed = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s
                     WHERE option_name = %s AND option_value = %s",
                    (string) time(),
                    $option_name,
                    (string) $lock_time
                ));
                $lock_acquired = ($reclaimed > 0);
            } else {
                $lock_acquired = false;
            }
        }
    }

    if (!$lock_acquired) {
        return;
    }

    // Helper to release the lock on any exit path.
    $release_lock = function () use ($lock_key) {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($lock_key, 'bcc_locks');
        } else {
            global $wpdb;
            $wpdb->delete($wpdb->options, ['option_name' => '_bcc_lock_' . $lock_key]);
        }
    };

    // Look up which CPTs this page needs based on its PeepSo category
    if (!function_exists('bcc_get_category_map')) {
        $release_lock();
        return;
    }

    $cat_ids = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryIdsForPage($page_id);
    if (empty($cat_ids)) {
        // No categories — mark OK and release lock.
        update_post_meta($page_id, '_bcc_integrity_ok', 1);
        $release_lock();
        return;
    }

    $map = bcc_get_category_map();

    // Collect the CPT types this page actually belongs to
    $target_cpts = [];
    foreach ($cat_ids as $cat_id) {
        if (isset($map[(int) $cat_id]['cpt'])) {
            $target_cpts[] = $map[(int) $cat_id]['cpt'];
        }
    }
    $target_cpts = array_unique($target_cpts);

    $all_ok = true;
    foreach ($target_cpts as $cpt_name) {
        $existing = get_post_meta($page_id, '_linked_' . $cpt_name . '_id', true);

        if ($existing && get_post($existing)) continue;

        // Create the shadow post via domain layer
        $created_id = \BCC\PeepSo\Domain\AbstractPageType::create_from_page_by_type($page_id, $cpt_name);
        if (!$created_id) {
            $all_ok = false;
        }
    }

    // Only mark integrity as confirmed if ALL shadow CPTs were created successfully.
    if ($all_ok) {
        update_post_meta($page_id, '_bcc_integrity_ok', 1);
    }

    $release_lock();
});

/**
 * Prevent duplicate shadow CPTs
 */
add_action('save_post', function ($post_id, $post) {
    if (wp_is_post_revision($post_id)) return;

    $page_id = get_post_meta($post_id, '_peepso_page_id', true);
    if (!$page_id) return;

    $type = $post->post_type;
    $existing = get_post_meta($page_id, '_linked_' . $type . '_id', true);

    if ($existing && (int)$existing !== (int)$post_id) {
        wp_trash_post($post_id);
    }
}, 10, 2);

// Title sync is handled by page-to-cpt-sync.php (shutdown hook).
// Removed duplicate save_post_peepso-page hook that was causing double wp_update_post calls.

/**
 * Invalidate the integrity flag when a page's categories change.
 * This forces the next page load to re-check and potentially create/update
 * the shadow CPT for the new category.
 */
// PeepSo uses its own category system — hook into page saves to detect
// category changes and clear the integrity flag.
add_action('save_post_peepso-page', function ($post_id) {
    if (wp_is_post_revision($post_id)) return;
    delete_post_meta($post_id, '_bcc_integrity_ok');
    \BCC\PeepSo\Repositories\PeepSoPageRepository::invalidateCategoryCache($post_id);
}, 5);

// Invalidate category cache when the peepso-page-cat CPT is updated
// (covers admin re-assignment of categories to pages).
add_action('save_post_peepso-page-cat', function ($post_id) {
    if (wp_is_post_revision($post_id)) return;

    // Bust the global category map cache (bcc_get_category_map).
    wp_cache_delete('bcc_category_map', 'bcc_peepso');

    // Category CPT changed — flush all page caches that reference it.
    // Since the relation table maps page→cat, we query affected pages.
    if (\BCC\PeepSo\Repositories\PeepSoPageRepository::tableExists()) {
        global $wpdb;
        $table = \BCC\PeepSo\Repositories\PeepSoPageRepository::tableName();
        $page_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm_page_id FROM {$table} WHERE pm_cat_id = %d",
            $post_id
        ));
        foreach ($page_ids as $page_id) {
            \BCC\PeepSo\Repositories\PeepSoPageRepository::invalidateCategoryCache((int) $page_id);
        }
    }
});

/**
 * Lock CPT title editing
 */
add_action('admin_init', function () {
    $cpts = ['validators', 'nft', 'builder', 'dao'];
    foreach ($cpts as $cpt) {
        remove_post_type_support($cpt, 'title');
    }
});

/**
 * Admin notice for linked CPTs
 */
add_action('admin_notices', function () {
    global $post;
    if (!$post) return;

    $cpts = ['validators', 'nft', 'builder', 'dao'];
    if (!in_array($post->post_type, $cpts, true)) return;
    if (!get_post_meta($post->ID, '_peepso_page_id', true)) return;

    echo '<div class="notice notice-warning is-dismissible"><p>';
    echo '⚠️ This content is linked to a PeepSo Page. Title and some settings are managed by the source page.';
    echo '</p></div>';
});

/**
 * Periodic category map drift detection (admin-only, throttled to once per hour).
 *
 * Compares cached category map checksum against live DB state. On drift,
 * auto-repairs by busting the cache and logging a warning. This catches
 * silent invalidation bugs before they cause visible data corruption.
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!function_exists('bcc_category_map_is_fresh')) {
        return;
    }
    // Throttle: check at most once per hour per site.
    $throttleKey = 'bcc_catmap_drift_check';
    if (get_transient($throttleKey)) {
        return;
    }
    set_transient($throttleKey, 1, HOUR_IN_SECONDS);

    bcc_category_map_is_fresh();
});