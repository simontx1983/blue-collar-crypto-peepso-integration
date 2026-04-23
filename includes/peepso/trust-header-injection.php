<?php
/**
 * Trust Header Panel renderer.
 *
 * The trust header is injected directly by the page-header partial
 * (`includes/partials/page-header.php`, installed as a PeepSo theme
 * override) which calls `bcc_render_trust_header_panel()` above the
 * navigation menu. No output-buffer interception is used.
 *
 * @package Blue_Collar_Crypto
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the trust header panel for a PeepSo page.
 *
 * @param int    $page_id PeepSo Page ID.
 * @param string $mode    'public' or 'dashboard'.
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

    include BCC_PEEPSO_PLUGIN_PATH . 'templates/peepso/trust-header-panel.php';
}
