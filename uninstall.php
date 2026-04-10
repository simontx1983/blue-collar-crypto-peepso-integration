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

// 2. Delete all shadow CPT posts
$cpt_types = ['validators', 'nft', 'builder', 'dao'];
foreach ($cpt_types as $cpt) {
    $posts = get_posts([
        'post_type'      => $cpt,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_status'    => 'any',
    ]);
    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// 3. Remove all BCC post meta (_bcc_*, _linked_*_id, _linked_cpts, _peepso_page_id, _peepso_cat_id)
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_bcc\_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_linked\_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_peepso_page_id'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_peepso_cat_id'");

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
