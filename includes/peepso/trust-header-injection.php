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
    static $flag_cache = [];

    // Prevent double-rendering on the same page in the same request.
    if ( isset( $rendered_for[ $page_id ] ) ) {
        return;
    }
    $rendered_for[ $page_id ] = true;

    // Resolve flag data here so the template does not call trust-engine
    // classes directly. Per-request memoisation prevents redundant
    // FlagService calls when the same page header is rendered under
    // multiple modes (public + dashboard) in one request.
    $flag_count     = 0;
    $viewer_flagged = false;
    $viewer_id      = is_user_logged_in() ? (int) get_current_user_id() : 0;
    $cache_key      = $page_id . ':' . $viewer_id;

    if ( isset( $flag_cache[ $cache_key ] ) ) {
        [ $flag_count, $viewer_flagged ] = $flag_cache[ $cache_key ];
    } elseif ( class_exists( '\\BCC\\Trust\\Services\\FlagService' ) ) {
        $flag_count = \BCC\Trust\Services\FlagService::getFlagCount( $page_id );
        if ( $viewer_id > 0 ) {
            $viewer_flagged = \BCC\Trust\Services\FlagService::hasUserFlagged( $page_id, $viewer_id );
        }
        $flag_cache[ $cache_key ] = [ $flag_count, $viewer_flagged ];
    }

    include BCC_PEEPSO_PLUGIN_PATH . 'templates/peepso/trust-header-panel.php';
}
