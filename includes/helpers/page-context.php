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
 * Resolve the current PeepSo Page ID from available context sources.
 *
 * Priority: block attribute → PeepSoPagesShortcode singleton → URL segments.
 *
 * @param int $attribute_page_id The pageId block attribute value (0 if unset).
 * @return int The resolved PeepSo Page ID, or 0 if none found.
 */
function bcc_resolve_peepso_page_id( int $attribute_page_id = 0 ): int {
    // 1. Explicit block attribute (manual override in editor).
    if ( $attribute_page_id > 0 ) {
        return $attribute_page_id;
    }

    // 2. PeepSoPagesShortcode singleton (set by PeepSo routing before render).
    //    Note: page_id can be FALSE when page not found / access denied.
    if ( class_exists( 'PeepSoPagesShortcode' ) ) {
        $sc      = PeepSoPagesShortcode::get_instance();
        $page_id = $sc->page_id ?? null;
        if ( is_numeric( $page_id ) && (int) $page_id > 0 ) {
            return (int) $page_id;
        }
    }

    // 3. URL segments fallback (parse page ID/slug directly from URL).
    if ( class_exists( 'PeepSoUrlSegments' ) ) {
        $segment = PeepSoUrlSegments::get_instance()->get( 1 );
        if ( $segment && 'category' !== $segment ) {
            if ( is_numeric( $segment ) ) {
                return (int) $segment;
            }
            // Slug-based lookup.
            if ( class_exists( 'PeepSoPage' ) ) {
                $page = new PeepSoPage( $segment );
                if ( ! empty( $page->id ) ) {
                    return (int) $page->id;
                }
            }
        }
    }

    // 4. No context found.
    return 0;
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
