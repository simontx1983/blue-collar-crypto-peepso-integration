<?php
if (!defined('ABSPATH')) exit;

/**
 * ======================================================
 * DATA INTEGRITY GUARD RAILS
 * ======================================================
 */

/**
 * Auto-create missing shadow CPT on page load
 */
add_action('wp', function () {
    if (!is_singular('peepso-page')) return;

    global $post;
    if (!$post) return;

    $page_id = $post->ID;

    // Skip if integrity was already confirmed for this page
    if (get_post_meta($page_id, '_bcc_integrity_ok', true)) {
        return;
    }

    // Look up which CPTs this page needs based on its PeepSo category
    if (!function_exists('bcc_find_peepso_relation_table') || !function_exists('bcc_get_category_map')) {
        return;
    }

    list($rel_table, $rel_page_col, $rel_cat_col) = bcc_find_peepso_relation_table();
    if (!$rel_table || !$rel_page_col || !$rel_cat_col) return;

    // Validate identifiers contain only safe characters
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $rel_table) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $rel_page_col) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $rel_cat_col)) {
        return;
    }

    global $wpdb;
    $cat_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT {$rel_cat_col} FROM {$rel_table} WHERE {$rel_page_col} = %d",
            $page_id
        )
    );

    if (empty($cat_ids)) return;

    $map = bcc_get_category_map();

    // Collect the CPT types this page actually belongs to
    $target_cpts = [];
    foreach ($cat_ids as $cat_id) {
        if (isset($map[(int) $cat_id]['cpt'])) {
            $target_cpts[] = $map[(int) $cat_id]['cpt'];
        }
    }
    $target_cpts = array_unique($target_cpts);

    foreach ($target_cpts as $cpt_name) {
        $existing = get_post_meta($page_id, '_linked_' . $cpt_name . '_id', true);

        if ($existing && get_post($existing)) continue;

        // Create the shadow post via domain layer
        BCCPeepSoDomainAbstractPageType::create_from_page_by_type($page_id, $cpt_name);
    }

    // Mark integrity as confirmed so future page loads skip these checks
    update_post_meta($page_id, '_bcc_integrity_ok', 1);
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