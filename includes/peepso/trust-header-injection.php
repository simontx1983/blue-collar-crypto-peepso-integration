<?php
/**
 * Injects the BCC Trust Header Panel into PeepSo page views.
 *
 * Placement: DIRECTLY ABOVE the navigation menu, after the page info
 * (name, description, followers). This is achieved by capturing the
 * page-header template output and injecting trust header HTML before
 * the `.ps-focus__menu` div.
 *
 * Two code paths handle this:
 *   1. Normal page views (stream, about, followers, settings) — the
 *      page-header template renders, so we use output buffer interception
 *      to inject before .ps-focus__menu.
 *   2. Dashboard segment — PeepSo skips page-header entirely and fires
 *      peepso_page_segment_dashboard directly, so we hook that at
 *      priority 5 (before dashboard content).
 *
 * The static $rendered_for guard in bcc_render_trust_header_panel()
 * prevents duplicate rendering if both paths fire for the same page.
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
    if ( ! class_exists( '\\BCC\\Trust\\Plugin' ) ) {
        return;
    }

    include BCC_PLUGIN_PATH . 'templates/peepso/trust-header-panel.php';
}

/* ──────────────────────────────────────────────────────────────────────
   Output buffer strategy:
   1. Before page-header renders → start output buffer
   2. After page-header renders  → capture output, inject trust header
      HTML before .ps-focus__menu, echo the modified output.
   ────────────────────────────────────────────────────────────────────── */

/**
 * Start capturing the page-header template output.
 */
add_action( 'peepso_action_before_exec_template', 'bcc_trust_header_buffer_start', 10, 4 );

function bcc_trust_header_buffer_start( $section, $template, $data, $return_output ) {
    if ( 'pages' !== $section || 'page-header' !== $template ) {
        return;
    }

    // Don't buffer if the template itself is returning output (used in AJAX)
    if ( $return_output ) {
        return;
    }

    ob_start();
}

/**
 * Capture the page-header output and inject trust header above the nav menu.
 */
add_action( 'peepso_action_after_exec_template', 'bcc_trust_header_buffer_end', 10, 4 );

function bcc_trust_header_buffer_end( $section, $template, $data, $return_output ) {
    if ( 'pages' !== $section || 'page-header' !== $template ) {
        return;
    }

    if ( $return_output ) {
        return;
    }

    $output = ob_get_clean();

    if ( $output === false ) {
        // Buffer wasn't started (shouldn't happen, but guard against it)
        return;
    }

    $page = $data['page'] ?? null;
    if ( ! $page || empty( $page->id ) ) {
        echo $output;
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

    // Render the trust header into a string.
    ob_start();
    bcc_render_trust_header_panel( (int) $page->id, $mode );
    $trust_header_html = ob_get_clean();

    if ( empty( $trust_header_html ) ) {
        // No trust header to inject (engine not active, or already rendered).
        echo $output;
        return;
    }

    // Inject BEFORE the navigation menu.
    // The nav menu starts with: <div class="ps-focus__menu
    $injection_marker = '<div class="ps-focus__menu ';

    $pos = strpos( $output, $injection_marker );
    if ( $pos !== false ) {
        // Insert trust header right before the nav menu div.
        $output = substr( $output, 0, $pos )
                . $trust_header_html
                . substr( $output, $pos );
    } else {
        // Fallback: append after the entire header if marker not found.
        $output .= $trust_header_html;
    }

    echo $output;
}

/* ──────────────────────────────────────────────────────────────────────
   Dashboard segment fallback:
   PeepSo does NOT render page-header.php for segment views (dashboard,
   settings, etc. loaded via AJAX). The buffer strategy above won't fire.
   This hook ensures the trust header still appears at priority 5
   (before dashboard content).
   The static $rendered_for guard prevents duplicates if both paths fire.
   ────────────────────────────────────────────────────────────────────── */

add_action( 'peepso_page_segment_dashboard', 'bcc_inject_trust_header_in_dashboard', 5, 2 );

function bcc_inject_trust_header_in_dashboard( $args, $url ) {
    $page = $args['page'] ?? null;
    if ( ! $page || empty( $page->id ) ) {
        return;
    }

    bcc_render_trust_header_panel( (int) $page->id, 'dashboard' );
}
