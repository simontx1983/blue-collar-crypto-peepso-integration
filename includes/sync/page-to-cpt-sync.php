<?php
if (!defined('ABSPATH')) exit;

/**
 * =====================================================
 * PeepSo Page → Shadow CPT Sync Engine
 * =====================================================
 * - Create shadow CPTs on page save
 * - Page title is source of truth
 * - Auto-repair mismatched titles
 * - Prevent duplicates
 * - Set default visibility
 * - Delete shadows when page deleted
 */

/* ----------------------------------------------------
   Queue pages modified in this request
---------------------------------------------------- */

$GLOBALS['bcc_pending_peepso_pages'] = [];

add_action('save_post_peepso-page', function ($post_id, $post, $update) {

    if (!$post) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    static $queued = [];
    if (isset($queued[$post_id])) return;
    $queued[$post_id] = true;

    $GLOBALS['bcc_pending_peepso_pages'][$post_id] = [
        'title'  => $post->post_title,
        'author' => (int) $post->post_author,
    ];

}, 10, 3);




/* ----------------------------------------------------
   Find PeepSo Page ↔ Category Relation Table
---------------------------------------------------- */

function bcc_find_peepso_relation_table() {

    global $wpdb;

    $table = $wpdb->prefix . 'peepso_page_categories';

    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
        return [null, null, null];
    }

    $cols = $wpdb->get_results("DESCRIBE {$table}");

    $page_col = null;
    $cat_col  = null;

    foreach ($cols as $c) {

        if (!$page_col && stripos($c->Field, 'page') !== false) {
            $page_col = $c->Field;
        }

        if (!$cat_col && stripos($c->Field, 'cat') !== false) {
            $cat_col = $c->Field;
        }
    }

    // Validate column names contain only safe characters
    if ($page_col && !preg_match('/^[a-zA-Z_]+$/', $page_col)) {
        $page_col = null;
    }
    if ($cat_col && !preg_match('/^[a-zA-Z_]+$/', $cat_col)) {
        $cat_col = null;
    }

    return [$table, $page_col, $cat_col];
}




/* ----------------------------------------------------
   Sync Engine (Runs After Request)
---------------------------------------------------- */

add_action('shutdown', function () {

    if (empty($GLOBALS['bcc_pending_peepso_pages'])) return;
    if (!function_exists('bcc_get_category_map')) return;

    global $wpdb;

    [$table, $page_col, $cat_col] = bcc_find_peepso_relation_table();

    if (!$table || !$page_col || !$cat_col) return;

    $map = bcc_get_category_map();

    foreach ($GLOBALS['bcc_pending_peepso_pages'] as $page_id => $data) {

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$cat_col} AS cat_id
                 FROM {$table}
                 WHERE {$page_col} = %d",
                $page_id
            )
        );

        if (empty($rows)) continue;

        $targets = [];

        foreach ($rows as $row) {

            $cat_id = (int) $row->cat_id;

            if (!isset($map[$cat_id]['cpt'])) continue;

            $targets[$map[$cat_id]['cpt']] = $cat_id;
        }

        if (empty($targets)) continue;

        $linked = [];

        foreach ($targets as $post_type => $cat_id) {

            if (!post_type_exists($post_type)) continue;

            // Existing link?
            $existing = get_post_meta($page_id, '_linked_' . $post_type . '_id', true);

            if ($existing && get_post($existing)) {

                // Repair title if wrong
                if (get_the_title($existing) !== $data['title']) {

                    wp_update_post([
                        'ID'         => $existing,
                        'post_title'=> $data['title'],
                        'post_name' => sanitize_title($data['title'])
                    ]);
                }

                $linked[$post_type] = (int) $existing;
                continue;
            }

            // Create shadow CPT via domain layer
            $cpt_id = BCC_Domain_Abstract::create_from_page_by_type($page_id, $post_type);

            if (!$cpt_id) continue;

            update_post_meta($cpt_id, '_peepso_cat_id', (int) $cat_id);

            $linked[$post_type] = (int) $cpt_id;
        }

        update_post_meta($page_id, '_linked_cpts', $linked);
    }

});




/* ----------------------------------------------------
   Delete Shadow CPTs When Page Deleted
---------------------------------------------------- */

add_action('before_delete_post', function ($post_id) {

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'peepso-page') return;

    $linked = get_post_meta($post_id, '_linked_cpts', true);

    if (!is_array($linked)) return;

    foreach ($linked as $cpt_id) {

        if ($cpt_id && get_post($cpt_id)) {
            wp_trash_post($cpt_id);
        }
    }

    delete_post_meta($post_id, '_linked_cpts');

}, 10);
