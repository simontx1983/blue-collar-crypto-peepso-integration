<?php
/**
 * Injects the BCC Trust Header Panel into PeepSo page views.
 *
 * Two injection points:
 *   1. After the page-header template (stream, about, followers, etc.)
 *      → fires via peepso_action_after_exec_template
 *   2. At the top of the dashboard segment (before dashboard content)
 *      → fires via peepso_page_segment_dashboard at priority 5
 *
 * When PeepSo renders a segment, page.php (and its page-header) is NOT
 * rendered — only the segment action fires. So we need both hooks.
 *
 * @package Blue_Collar_Crypto
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared renderer — resolves page context, determines mode, includes template.
 *
 * @param int    $page_id  PeepSo Page ID.
 * @param string $mode     'public' or 'dashboard'.
 */
function bcc_render_trust_header_panel( int $page_id, string $mode ) {
    static $rendered_for = [];

    // Prevent double-rendering on the same page in the same request.
    if ( isset( $rendered_for[ $page_id ] ) ) {
        return;
    }
    $rendered_for[ $page_id ] = true;

    // Bail if bcc-trust-engine is not active.
    if ( ! class_exists( '\\BCCTrust\\Repositories\\ScoreRepository' ) ) {
        return;
    }

    include BCC_PLUGIN_PATH . 'templates/peepso/trust-header-panel.php';
}

/* ──────────────────────────────────────────────────────────────────────
   Hook 1: After page-header template (stream and non-segment views)
   ────────────────────────────────────────────────────────────────────── */

add_action( 'peepso_action_after_exec_template', 'bcc_inject_trust_header_after_page_header', 10, 4 );

function bcc_inject_trust_header_after_page_header( $section, $template, $data, $return_output ) {
    if ( 'pages' !== $section || 'page-header' !== $template ) {
        return;
    }

    $page = $data['page'] ?? null;
    if ( ! $page || empty( $page->id ) ) {
        return;
    }

    // Determine mode from PeepSo segment.
    $mode = 'public';
    if ( class_exists( 'PeepSoPagesShortcode' ) ) {
        $sc = PeepSoPagesShortcode::get_instance();
        if ( ! empty( $sc->page_segment_id ) && $sc->page_segment_id === 'dashboard' ) {
            $mode = 'dashboard';
        }
    }

    bcc_render_trust_header_panel( (int) $page->id, $mode );
}

/* ──────────────────────────────────────────────────────────────────────
   Hook 2: Dashboard segment (runs BEFORE dashboard content at priority 5)
   ────────────────────────────────────────────────────────────────────── */

add_action( 'peepso_page_segment_dashboard', 'bcc_inject_trust_header_in_dashboard', 5, 2 );

function bcc_inject_trust_header_in_dashboard( $args, $url ) {
    $page = $args['page'] ?? null;
    if ( ! $page || empty( $page->id ) ) {
        return;
    }

    bcc_render_trust_header_panel( (int) $page->id, 'dashboard' );
}
