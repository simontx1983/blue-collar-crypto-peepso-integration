<?php
/**
 * Plugin Uninstall
 * Runs when the plugin is deleted from the WordPress admin.
 * Cleans up all tables, post meta, options, and uploaded gallery files.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Drop custom DB tables (images first — foreign-key order)
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bcc_collection_images");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bcc_collections");

// 2. Delete all shadow CPT posts (batched to avoid memory exhaustion)
$cpt_types = ['validators', 'nft', 'builder', 'dao'];
foreach ($cpt_types as $cpt) {
    do {
        $posts = get_posts([
            'post_type'      => $cpt,
            'posts_per_page' => 100,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true);
        }
    } while (!empty($posts));
}

// 3. Remove post meta owned by THIS plugin only.
//
// CRITICAL: earlier versions deleted the ENTIRE `_bcc_*` and `_linked_*`
// lexicographic ranges, which would wipe out sibling BCC plugins'
// data (trust scores, wallet links, dispute flags, onchain bonuses)
// the instant this plugin was uninstalled. The delete set is now
// enumerated to only keys this plugin actually writes.
$bcc_peepso_owned_meta = [
    '_bcc_vis_version',
    '_bcc_integrity_ok',
    '_bcc_integrity_last_check',
    '_linked_cpts',
    '_linked_validators_id',
    '_linked_nft_id',
    '_linked_builder_id',
    '_linked_dao_id',
    '_peepso_page_id',
    '_peepso_cat_id',
];

// Delete exact-match keys (safe, scoped).
foreach ($bcc_peepso_owned_meta as $meta_key) {
    $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key], ['%s']);
}

// Per-field visibility keys (`_bcc_vis_<field>`) — narrow LIKE with
// esc_like() so only this plugin's visibility rows are removed.
$vis_like = $wpdb->esc_like('_bcc_vis_') . '%';
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $vis_like
));

// 4. Delete uploaded gallery files (including subdirectories)
$upload_dir  = wp_upload_dir();
$gallery_dir = trailingslashit($upload_dir['basedir']) . 'bcc-gallery/';

if (is_dir($gallery_dir)) {
    $it = new RecursiveDirectoryIterator($gallery_dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($gallery_dir);
}
