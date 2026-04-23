<?php
if (!defined('ABSPATH')) {
    exit;
}

/* ======================================================
   FRONTEND ASSETS
====================================================== */

add_action('wp_enqueue_scripts', 'bcc_enqueue_assets');

function bcc_enqueue_assets() {

    // Never load on admin screens
    if (is_admin()) {
        return;
    }

    // PeepSo pages reach the browser via several routing paths:
    //   1. A regular WP page containing the [peepso_pages] shortcode.
    //   2. `is_singular('peepso-page')` — when the page is requested by
    //      post id / slug directly (our shadow-CPT and dashboard flows).
    //   3. A query var set by PeepSo's own rewrite handler.
    // Gating strictly on the shortcode previously meant the bcc-profile
    // CSS and bcc-all JS silently missed routes (2) and (3), so inline
    // edit / gallery / visibility UI looked broken on real PeepSo pages.
    global $post;
    $hasShortcode = $post && has_shortcode($post->post_content ?? '', 'peepso_pages');
    $isPeepsoPage = is_singular('peepso-page');
    $peepsoQuery  = (bool) get_query_var('peepso_page', false);

    if (!$hasShortcode && !$isPeepsoPage && !$peepsoQuery) {
        return;
    }

    $base_css = plugin_dir_url(__FILE__) . '../../assets/css/';
    $base_js  = plugin_dir_url(__FILE__) . '../../assets/js/';
    
    // Cache busting
    $version = defined('WP_DEBUG') && WP_DEBUG ? time() : '1.0.0';

    /* -----------------------------------------
       STYLES
    ----------------------------------------- */

    wp_enqueue_style(
        'bcc-profile',
        $base_css . 'profile.css',
        [],
        $version
    );

    /* -----------------------------------------
       SCRIPTS - SINGLE CONSOLIDATED FILE
    ----------------------------------------- */

    // Single consolidated JS file
    wp_enqueue_script(
        'bcc-all',
        $base_js . 'bcc-all.js',
        ['jquery'],
        $version,
        true
    );

    // Localize ONCE for all scripts
    wp_localize_script('bcc-all', 'bcc_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('bcc_nonce')
    ]);

    /* -----------------------------------------
       TRUST HEADER (registered by bcc-trust-engine)
       These handles are only available when bcc-trust-engine is active.
    ----------------------------------------- */
    if ( wp_style_is( 'bcc-trust-header', 'registered' ) ) {
        wp_enqueue_style( 'bcc-trust-header' );
    }
    if ( wp_script_is( 'bcc-trust-header', 'registered' ) ) {
        wp_enqueue_script( 'bcc-trust-header' );
    }
}