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
 *   1. Normal page views (stream, about, followers, settings) — PeepSo
 *      renders its own page-header.php, so we use output buffer
 *      interception to inject before .ps-focus__menu.
 *   2. Dashboard segment — uses our own page-header partial
 *      (includes/partials/page-header.php) which injects the trust
 *      header directly before the nav menu in the template itself.
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

    include BCC_PLUGIN_PATH . 'templates/peepso/trust-header-panel.php';
}

/* ──────────────────────────────────────────────────────────────────────
   Output buffer strategy:
   1. Before page-header renders → start output buffer
   2. After page-header renders  → capture output, inject trust header
      HTML before .ps-focus__menu, echo the modified output.
   ────────────────────────────────────────────────────────────────────── */

/**
 * Output buffer injection — DISABLED.
 *
 * The custom page-header.php is now installed as a PeepSo theme override
 * at: themes/peepso-block-theme-child/peepso/pages/page-header.php
 *
 * This means PeepSo uses our custom template for ALL page views (Stream,
 * Followers, Settings, Dashboard) — no more buffer interception needed.
 * The trust header is injected directly in the template itself.
 */
// add_action( 'peepso_action_before_exec_template', 'bcc_trust_header_buffer_start', 10, 4 );

function bcc_trust_header_buffer_start( $section, $template, $data, $return_output ) {
    if ( 'pages' !== $section || 'page-header' !== $template ) {
        return;
    }

    // Don't buffer if the template itself is returning output (used in AJAX)
    if ( $return_output ) {
        return;
    }

    // Skip on the dashboard segment — our custom page-header partial
    // (includes/partials/page-header.php) handles injection directly.
    // Without this, both paths fire and produce two different headers.
    if ( class_exists( 'PeepSoPagesShortcode' ) ) {
        $sc = PeepSoPagesShortcode::get_instance();
        if ( ! empty( $sc->page_segment_id ) && $sc->page_segment_id === 'dashboard' ) {
            return;
        }
    }

    ob_start();
}

/**
 * DISABLED — see comment above.
 *
 * Capture the page-header output and inject trust header above the nav menu.
 */
// add_action( 'peepso_action_after_exec_template', 'bcc_trust_header_buffer_end', 10, 4 );

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
   Dashboard segment:
   The dashboard uses our own page-header partial (includes/partials/
   page-header.php) which injects the trust header directly before the
   nav menu. No separate hook needed — the template handles it.
   ────────────────────────────────────────────────────────────────────── */
