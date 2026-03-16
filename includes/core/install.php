<?php
if (!defined('ABSPATH')) exit;

/**
 * ======================================================
 *  BCC Database Installer
 *  Creates custom tables on plugin activation.
 *  Safe to call multiple times (uses dbDelta).
 * ======================================================
 */
function bcc_create_tables(): void {

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql_collections = "CREATE TABLE {$wpdb->prefix}bcc_collections (
        id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id     bigint(20) unsigned NOT NULL,
        user_id     bigint(20) unsigned NOT NULL DEFAULT 0,
        name        varchar(255)        NOT NULL DEFAULT '',
        sort_order  int(11)             NOT NULL DEFAULT 0,
        image_count int(11)             NOT NULL DEFAULT 0,
        created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_sort (post_id, sort_order),
        KEY post_id (post_id)
    ) $charset_collate;";

    $sql_images = "CREATE TABLE {$wpdb->prefix}bcc_collection_images (
        id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        collection_id bigint(20) unsigned NOT NULL,
        file          varchar(255)        NOT NULL DEFAULT '',
        url           varchar(2048)       NOT NULL DEFAULT '',
        thumbnail     varchar(2048)       NOT NULL DEFAULT '',
        size          bigint(20)          NOT NULL DEFAULT 0,
        sort_order    int(11)             NOT NULL DEFAULT 0,
        created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY collection_id (collection_id),
        KEY coll_sort (collection_id, sort_order)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_collections);
    dbDelta($sql_images);

    update_option('bcc_db_version', BCC_VERSION);
}
