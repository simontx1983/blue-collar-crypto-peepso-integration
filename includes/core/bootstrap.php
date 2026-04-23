<?php
if (!defined('ABSPATH')) exit;

if (defined('BCC_PEEPSO_BOOTSTRAP_LOADED')) {
    return;
}
define('BCC_PEEPSO_BOOTSTRAP_LOADED', true);

/* ======================================================
   CATEGORY → CPT MAP (single source of truth)
====================================================== */

if (!function_exists('bcc_get_slug_to_cpt_map')) {
    /**
     * Canonical slug → CPT definition.  Filterable so themes/other plugins
     * can extend. Used by bcc_get_category_map() and bcc_category_map_is_fresh().
     *
     * @return array<string, array{cpt: string, label: string}>
     */
    function bcc_get_slug_to_cpt_map(): array {
        return apply_filters('bcc_category_slug_map', [
            'validators'   => ['cpt' => 'validators', 'label' => 'Validators'],
            'vaildators'   => ['cpt' => 'validators', 'label' => 'Validators'], // typo in DB
            'builder'      => ['cpt' => 'builder',    'label' => 'Builder'],
            'builders'     => ['cpt' => 'builder',    'label' => 'Builder'],
            'nft'          => ['cpt' => 'nft',        'label' => 'NFT'],
            'nft-creators' => ['cpt' => 'nft',        'label' => 'NFT'],
            'nft-creator'  => ['cpt' => 'nft',        'label' => 'NFT'],
            'dao'          => ['cpt' => 'dao',        'label' => 'DAO'],
            'daos'         => ['cpt' => 'dao',        'label' => 'DAO'],
        ]);
    }
}

if (!function_exists('bcc_resolve_category_map_from_db')) {
    /**
     * Build the category ID → CPT map from the database (bypasses cache).
     *
     * Paginated so a site with more than 100 peepso-page-cat posts does
     * not silently lose categories from the map (which previously caused
     * shadow CPTs to never be created for affected categories — a
     * rule-10 silent failure). If the hard safety cap is hit we log an
     * error; >1,000 page categories is outside the design envelope.
     *
     * @return array<int, array{cpt: string, label: string}>
     */
    function bcc_resolve_category_map_from_db(): array {
        static $typoLogged = false;

        $slug_to_cpt = bcc_get_slug_to_cpt_map();
        $batch_size  = 100;
        $hard_cap    = 1000;

        $map    = [];
        $offset = 0;
        $typoHits = [];

        do {
            $cats = get_posts([
                'post_type'      => 'peepso-page-cat',
                'post_status'    => 'publish',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'fields'         => 'all',
            ]);

            foreach ($cats as $cat) {
                $slug = $cat->post_name;
                if (isset($slug_to_cpt[$slug])) {
                    $map[$cat->ID] = $slug_to_cpt[$slug];
                    // Track use of the legacy typoed slug so we can retire
                    // it once the DB is cleaned up. One log per request.
                    if ($slug === 'vaildators') {
                        $typoHits[] = (int) $cat->ID;
                    }
                }
            }

            $offset += $batch_size;

            if ($offset >= $hard_cap && count($cats) === $batch_size) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-peepso] category map hit hard cap — categories beyond this point are invisible to the shadow-CPT pipeline',
                        ['cap' => $hard_cap, 'batch_size' => $batch_size]
                    );
                }
                break;
            }
        } while (count($cats) === $batch_size);

        if (!$typoLogged && !empty($typoHits) && class_exists('\\BCC\\Core\\Log\\Logger')) {
            $typoLogged = true;
            \BCC\Core\Log\Logger::warning(
                '[bcc-peepso] legacy typo slug "vaildators" still present in peepso-page-cat — clean up these rows so the alias can be retired',
                ['cat_ids' => $typoHits]
            );
        }

        return apply_filters('bcc_category_map', $map);
    }
}

if (!function_exists('bcc_get_category_map')) {
    /**
     * Maps PeepSo page category IDs to shadow CPT slugs.
     * Used by: sync engine, repair tool, dashboard tabs.
     *
     * Filterable so themes/other plugins can extend.
     */
    function bcc_get_category_map(): array {
        static $resolved = null;
        static $generation = 0;

        // Check if cache was invalidated since last static resolution.
        $current_gen = (int) wp_cache_get('bcc_category_map_gen', 'bcc_peepso');
        if ($resolved !== null && $generation === $current_gen) {
            return $resolved;
        }

        $cache_key   = 'bcc_category_map';
        $cache_group = 'bcc_peepso';
        $cache_ttl   = 3600;

        $cached = wp_cache_get($cache_key, $cache_group);
        if (is_array($cached)) {
            $resolved = $cached;
            return $resolved;
        }

        $resolved = bcc_resolve_category_map_from_db();
        $generation = $current_gen;
        wp_cache_set($cache_key, $resolved, $cache_group, $cache_ttl);

        $checksum = md5(serialize($resolved));
        wp_cache_set('bcc_category_map_checksum', $checksum, $cache_group, $cache_ttl);

        return $resolved;
    }
}

if (!function_exists('bcc_category_map_is_fresh')) {
    /**
     * Verify that the cached category map matches the current DB state.
     *
     * Returns true if the cache is fresh, false if stale or missing.
     * Call from a cron job or health endpoint to detect silent drift.
     */
    function bcc_category_map_is_fresh(): bool {
        $cache_group = 'bcc_peepso';
        $cachedChecksum = wp_cache_get('bcc_category_map_checksum', $cache_group);
        if ($cachedChecksum === false) {
            return false;
        }

        $freshMap      = bcc_resolve_category_map_from_db();
        $freshChecksum = md5(serialize($freshMap));

        if ($cachedChecksum !== $freshChecksum) {
            wp_cache_delete('bcc_category_map', $cache_group);
            wp_cache_delete('bcc_category_map_checksum', $cache_group);
            // Bump generation so the static $resolved cache is invalidated
            // for any same-request callers.
            wp_cache_incr('bcc_category_map_gen', 1, $cache_group)
                || wp_cache_set('bcc_category_map_gen', 1, $cache_group, 0);

            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-peepso] Category map cache drift detected and auto-repaired', [
                    'cached_checksum' => $cachedChecksum,
                    'fresh_checksum'  => $freshChecksum,
                ]);
            }
            return false;
        }

        return true;
    }
}

/* ======================================================
   CORE
====================================================== */

require_once BCC_PEEPSO_INCLUDES_PATH . 'core/visibility.php';
require_once BCC_PEEPSO_INCLUDES_PATH . 'core/permissions.php';

/* ======================================================
   DOMAIN (ABSTRACT FIRST)
====================================================== */

// Domain types loaded via Composer PSR-4 autoload (app/Domain/)

/* ======================================================
   SERVICES (shadow CPT sync, integrity, repair)
====================================================== */

\BCC\PeepSo\Services\ShadowPageSyncService::register();
\BCC\PeepSo\Services\PageIntegrityService::register();
\BCC\PeepSo\Services\PageRepairService::register();

/* ======================================================
   AJAX CONTROLLERS
====================================================== */

\BCC\PeepSo\Controllers\InlineEditController::register();
\BCC\PeepSo\Controllers\VisibilityController::register();
\BCC\PeepSo\Controllers\GalleryController::register();


/* ======================================================
   RENDERERS (Generic / Reusable)
====================================================== */

require_once BCC_PEEPSO_INCLUDES_PATH . 'renderers/template-functions.php';

/* ======================================================
   HELPERS
====================================================== */
require_once BCC_PEEPSO_INCLUDES_PATH . 'helpers/page-context.php';

require_once BCC_PEEPSO_INCLUDES_PATH . 'peepso/segments.php';
require_once BCC_PEEPSO_INCLUDES_PATH . 'peepso/trust-header-injection.php';

/* ======================================================
   UI
====================================================== */

require_once BCC_PEEPSO_INCLUDES_PATH . 'ui/enqueue.php';


