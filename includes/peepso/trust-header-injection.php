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

    // Resolve flag data here so the template does not call trust-engine classes directly.
    $flag_count     = 0;
    $viewer_flagged = false;
    if ( class_exists( '\\BCC\\Trust\\Services\\FlagService' ) ) {
        $flag_count = \BCC\Trust\Services\FlagService::getFlagCount( $page_id );
        if ( is_user_logged_in() ) {
            $viewer_flagged = \BCC\Trust\Services\FlagService::hasUserFlagged( $page_id, get_current_user_id() );
        }
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
 * Output buffer injection — DISABLED.
 *
 * The custom page-header.php is now installed as a PeepSo theme override
 * at: themes/peepso-block-theme-child/peepso/pages/page-header.php
 *
 * This means PeepSo uses our custom template for ALL page views (Stream,
 * Followers, Settings, Dashboard) — no more buffer interception needed.
 * The trust header is injected directly in the template itself.
 */
// bcc_trust_header_buffer_start/end removed — replaced by theme template override.
// Trust header is injected directly in includes/partials/page-header.php.

/* ──────────────────────────────────────────────────────────────────────
   Dashboard segment:
   The dashboard uses our own page-header partial (includes/partials/
   page-header.php) which injects the trust header directly before the
   nav menu. No separate hook needed — the template handles it.
   ────────────────────────────────────────────────────────────────────── */
