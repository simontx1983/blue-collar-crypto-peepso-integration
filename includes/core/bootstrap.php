<?php
if (!defined('ABSPATH')) exit;

if (defined('BCC_BOOTSTRAP_LOADED')) {
    return;
}
define('BCC_BOOTSTRAP_LOADED', true);

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
        return apply_filters('bcc_category_map', [
            254 => ['cpt' => 'validators', 'label' => 'Validators'],
            268 => ['cpt' => 'builder',    'label' => 'Builder'],
            269 => ['cpt' => 'builder',    'label' => 'Builder'],
            253 => ['cpt' => 'nft',        'label' => 'NFT'],
            1901 => ['cpt' => 'dao',        'label' => 'DAO'],
        ]);
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

require_once BCC_INCLUDES_PATH . 'domain/class-bcc-domain-abstract.php';

require_once BCC_INCLUDES_PATH . 'domain/validator.php';
require_once BCC_INCLUDES_PATH . 'domain/builder.php';
require_once BCC_INCLUDES_PATH . 'domain/dao.php';
require_once BCC_INCLUDES_PATH . 'domain/nft.php';

/* ======================================================
   SYNC
====================================================== */

require_once BCC_INCLUDES_PATH . 'sync/page-to-cpt-sync.php';

/* ======================================================
   AJAX CONTROLLERS
====================================================== */

require_once BCC_INCLUDES_PATH . 'ajax/class-bcc-ajax-inline.php';
require_once BCC_INCLUDES_PATH . 'ajax/class-bcc-ajax-visibility.php';
require_once BCC_INCLUDES_PATH . 'ajax/class-bcc-ajax-gallery.php';


/* ======================================================
   RENDERERS (Generic / Reusable)
====================================================== */

require_once BCC_INCLUDES_PATH . 'renderers/class-bcc-field-renderer.php';
require_once BCC_INCLUDES_PATH . 'renderers/class-bcc-repeater-renderer.php';
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
   GUTENBERG BLOCKS
====================================================== */

require_once BCC_INCLUDES_PATH . 'blocks.php';

/* ======================================================
   UI
====================================================== */

require_once BCC_INCLUDES_PATH . 'ui/enqueue.php';


