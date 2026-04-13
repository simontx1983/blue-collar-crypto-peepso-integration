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
    // wp_cache_add is atomic (returns false if key exists). For the DB
    // fallback, use set_transient with a 30-second TTL — if the process
    // dies, the lock self-heals when the transient expires. The old
    // add_option approach had no TTL and stuck locks were permanent.
    $lock_key = 'bcc_integrity_lock_' . $page_id;
    if (wp_using_ext_object_cache()) {
        $lock_acquired = wp_cache_add($lock_key, 1, 'bcc_locks', 30);
    } else {
        // Check if lock already exists and is still fresh.
        $existing = get_transient('_lock_' . $lock_key);
        if ($existing) {
            $lock_acquired = false;
        } else {
            set_transient('_lock_' . $lock_key, 1, 30);
            $lock_acquired = true;
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
            delete_transient('_lock_' . $lock_key);
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
add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy) {
    // PeepSo page categories use the 'peepso_page_categories' relation table,
    // not WP taxonomies. But some integrations may fire set_object_terms.
    // Also hook into PeepSo-specific actions if available.
    if (get_post_type($object_id) === 'peepso-page') {
        delete_post_meta($object_id, '_bcc_integrity_ok');
    }
}, 10, 4);

// PeepSo uses its own category system — hook into page saves to detect
// category changes and clear the integrity flag.
add_action('save_post_peepso-page', function ($post_id) {
    if (wp_is_post_revision($post_id)) return;
    delete_post_meta($post_id, '_bcc_integrity_ok');
}, 5);

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