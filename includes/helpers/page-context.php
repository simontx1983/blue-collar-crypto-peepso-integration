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
