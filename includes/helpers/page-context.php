<?php
/**
 * PeepSo Page context resolution for Gutenberg blocks.
 *
 * @package Blue_Collar_Crypto
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get network options as a comma-separated string for template dropdowns.
 *
 * Caches the result per-request so dao.php and nft.php templates
 * don't run duplicate get_posts() queries.
 *
 * @return string Format: "id1:Title1,id2:Title2,..."
 */
function bcc_get_network_options_string(): string {
    static $static_cached = null;
    if ( $static_cached !== null ) {
        return $static_cached;
    }

    // Persistent object cache (cross-request) — M-7.
    $persistent = wp_cache_get( 'bcc_network_options', 'bcc_peepso_pages' );
    if ( is_string( $persistent ) ) {
        $static_cached = $persistent;
        return $static_cached;
    }

    $networks = get_posts([
        'post_type'      => 'network',
        'posts_per_page' => 100,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);

    $options = [];
    foreach ( $networks as $network_id ) {
        $title      = get_the_title( $network_id );
        $safe_title = str_replace( [',', ':'], [' ', '-'], $title );
        $options[]  = $network_id . ':' . $safe_title;
    }

    $static_cached = implode( ',', $options );
    wp_cache_set( 'bcc_network_options', $static_cached, 'bcc_peepso_pages', 3600 );
    return $static_cached;
}
