<?php
if (!defined('ABSPATH')) exit;

if (defined('BCC_PEEPSO_BOOTSTRAP_LOADED')) {
    return;
}
define('BCC_PEEPSO_BOOTSTRAP_LOADED', true);

/* ======================================================
   CATEGORY → CPT MAP (single source of truth)
====================================================== */

if (!function_exists('bcc_get_category_map')) {
    /**
     * Maps PeepSo page category IDs to shadow CPT slugs.
     * Used by: sync engine, repair tool, dashboard tabs.
     *
     * Filterable so themes/other plugins can extend.
     */
    function bcc_get_category_map(): array {
        // Resolve category IDs from slugs at runtime so the map is
        // portable across environments (dev / staging / production).
        // Results are cached for the duration of the request.
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $slug_to_cpt = apply_filters('bcc_category_slug_map', [
            'validators' => ['cpt' => 'validators', 'label' => 'Validators'],
            'builder'    => ['cpt' => 'builder',    'label' => 'Builder'],
            'builders'   => ['cpt' => 'builder',    'label' => 'Builder'],
            'nft'        => ['cpt' => 'nft',        'label' => 'NFT'],
            'dao'        => ['cpt' => 'dao',        'label' => 'DAO'],
        ]);

        $map = [];
        $cats = get_posts([
            'post_type'      => 'peepso-page-cat',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'fields'         => 'all',
        ]);

        foreach ($cats as $cat) {
            $slug = $cat->post_name;
            if (isset($slug_to_cpt[$slug])) {
                $map[$cat->ID] = $slug_to_cpt[$slug];
            }
        }

        $resolved = apply_filters('bcc_category_map', $map);
        return $resolved;
    }
}

/* ======================================================
   CORE
====================================================== */

require_once BCC_INCLUDES_PATH . 'core/visibility.php';
require_once BCC_INCLUDES_PATH . 'core/permissions.php';

/* ======================================================
   DOMAIN (ABSTRACT FIRST)
====================================================== */

// Domain types loaded via Composer PSR-4 autoload (app/Domain/)

/* ======================================================
   SYNC
====================================================== */

require_once BCC_INCLUDES_PATH . 'sync/page-to-cpt-sync.php';

/* ======================================================
   AJAX CONTROLLERS
====================================================== */

\BCC\PeepSo\Controllers\InlineEditController::register();
\BCC\PeepSo\Controllers\VisibilityController::register();
\BCC\PeepSo\Controllers\GalleryController::register();


/* ======================================================
   RENDERERS (Generic / Reusable)
====================================================== */

require_once BCC_INCLUDES_PATH . 'renderers/template-functions.php';

/* ======================================================
   HELPERS
====================================================== */
require_once BCC_INCLUDES_PATH . 'helpers/sync-repair.php';

require_once BCC_INCLUDES_PATH . 'helpers/data-integrity.php';
require_once BCC_INCLUDES_PATH . 'helpers/page-context.php';

require_once BCC_INCLUDES_PATH . 'peepso/segments.php';
require_once BCC_INCLUDES_PATH . 'peepso/trust-header-injection.php';

/* ======================================================
   UI
====================================================== */

require_once BCC_INCLUDES_PATH . 'ui/enqueue.php';


