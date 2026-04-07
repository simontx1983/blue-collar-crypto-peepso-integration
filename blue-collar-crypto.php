<?php
/**
 * Plugin Name: Blue Collar Crypto – PeepSo Integration
 * Description: Core integration layer between Blue Collar Crypto and the PeepSo social platform.
 * Version: 1.1.0
 * Author: Blue Collar Labs LLC
 * License: GPL v2 or later
 * Requires Plugins: peepso
 */

if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Constants
 * ==========================================================
 */
define('BCC_VERSION', '1.1.0');
define('BCC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BCC_INCLUDES_PATH', BCC_PLUGIN_PATH . 'includes/');
define('BCC_TEMPLATES_PATH', BCC_PLUGIN_PATH . 'templates/');
define('BCC_URL', plugin_dir_url(__FILE__));

// ── PSR-4 autoloader ────────────────────────────────────────────────────────
$bcc_peepso_autoloader = BCC_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($bcc_peepso_autoloader)) {
    require_once $bcc_peepso_autoloader;
}

/**
 * ==========================================================
 * Activation Hook — create DB tables
 * ==========================================================
 */
register_activation_hook(__FILE__, function () {
    require_once BCC_INCLUDES_PATH . 'core/install.php';
    bcc_create_tables();
});

/**
 * ==========================================================
 * Bootstrap
 * ==========================================================
 */
$bootstrap = BCC_INCLUDES_PATH . 'core/bootstrap.php';

if (file_exists($bootstrap)) {
    require_once $bootstrap;
} else {
    // fallback: do not fatal the site if file missing
    add_action('admin_notices', function () use ($bootstrap) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html('BCC Bootstrap missing: ' . $bootstrap);
        echo '</p></div>';
    });
    return;
}

/**
 * ==========================================================
 * Initialize Plugin
 * ==========================================================
 */
add_action('plugins_loaded', 'bcc_init', 20);

function bcc_init() {

    if (!class_exists('PeepSo')) {

        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Blue Collar Crypto – PeepSo Integration requires PeepSo to be installed and activated.', 'blue-collar-crypto');
            echo '</p></div>';
        });

        return;
    }

    // Translations
    load_plugin_textdomain(
        'blue-collar-crypto',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}


