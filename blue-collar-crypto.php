<?php
/**
 * Plugin Name: Blue Collar Crypto – PeepSo Integration
 * Description: Core integration layer between Blue Collar Crypto and the PeepSo social platform.
 * Version: 1.0.0
 * Author: Blue Collar Labs LLC
 * License: GPL v2 or later
 * Requires Plugins: peepso, bcc-core
 */

if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * Constants — plugin-scoped to avoid collisions with other
 * BCC plugins (bcc-core defines BCC_CORE_VERSION, etc.)
 * ==========================================================
 */
define('BCC_PEEPSO_VERSION', '1.0.0');
define('BCC_PEEPSO_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BCC_PEEPSO_INCLUDES_PATH', BCC_PEEPSO_PLUGIN_PATH . 'includes/');
define('BCC_PEEPSO_TEMPLATES_PATH', BCC_PEEPSO_PLUGIN_PATH . 'templates/');
define('BCC_PEEPSO_URL', plugin_dir_url(__FILE__));

// ── PSR-4 autoloader ────────────────────────────────────────────────────────
$bcc_peepso_autoloader = BCC_PEEPSO_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($bcc_peepso_autoloader)) {
    require_once $bcc_peepso_autoloader;
}

/**
 * ==========================================================
 * Activation Hook — create DB tables
 * ==========================================================
 */
register_activation_hook(__FILE__, function () {
    require_once BCC_PEEPSO_INCLUDES_PATH . 'core/install.php';
    bcc_create_tables();
});

/**
 * ==========================================================
 * Initialize Plugin (deferred to plugins_loaded)
 * ==========================================================
 * Bootstrap is loaded INSIDE the dependency guard so that
 * controller classes (which import bcc-core classes via `use`)
 * are never parsed when bcc-core is inactive.
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

    if (!defined('BCC_CORE_VERSION')) {

        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Blue Collar Crypto – PeepSo Integration requires BCC Core to be installed and activated.', 'blue-collar-crypto');
            echo '</p></div>';
        });

        return;
    }

    // Dependencies confirmed — load bootstrap (registers controllers, hooks, renderers).
    $bootstrap = BCC_PEEPSO_INCLUDES_PATH . 'core/bootstrap.php';

    if (file_exists($bootstrap)) {
        require_once $bootstrap;
    } else {
        add_action('admin_notices', function () use ($bootstrap) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html('BCC Bootstrap missing: ' . $bootstrap);
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


