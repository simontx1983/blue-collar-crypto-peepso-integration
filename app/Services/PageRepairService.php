<?php

namespace BCC\PeepSo\Services;

use BCC\PeepSo\Domain\AbstractPageType;
use BCC\PeepSo\Repositories\PeepSoPageRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page ↔ shadow CPT repair — on-demand and scheduled.
 *
 * Responsibilities:
 *   - Render a "Rebuild Shadow CPTs" tile inside the Trust Dashboard →
 *     Repair tab via the `bcc_trust_repair_tab_extra_tools` action.
 *   - Handle the POST from that tile and run a full repair pass.
 *   - Run a daily cron that detects missing / title-drifted shadows
 *     and repairs them in batches of 50.
 */
final class PageRepairService
{
    private const CRON_HOOK     = 'bcc_shadow_cpt_reconcile';
    private const BATCH_SIZE    = 50;
    private const POST_ACTION   = 'bcc_peepso_rebuild_shadows';
    private const NONCE_ACTION  = 'bcc_peepso_rebuild_shadows';
    private const RESULTS_TRANSIENT = 'bcc_trust_repair_results';

    public static function register(): void
    {
        add_action('bcc_trust_repair_tab_extra_tools', [self::class, 'renderRepairTile']);
        add_action('admin_post_' . self::POST_ACTION, [self::class, 'handleRepairPost']);
        add_action('init', [self::class, 'scheduleCron']);
        add_action(self::CRON_HOOK, [self::class, 'runReconcileCron']);
    }

    /**
     * Render the repair tile inside the Trust Dashboard → Repair tab grid.
     * Called by the `bcc_trust_repair_tab_extra_tools` action.
     */
    public static function renderRepairTile(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $post_url = esc_url(admin_url('admin-post.php'));
        ?>
        <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
            <h3 style="margin-top:0;">Rebuild Shadow CPTs</h3>
            <p style="font-size:13px;color:#666;">Scans every PeepSo page and creates any missing shadow CPT (validators / builder / nft / dao), repairing drifted titles along the way.</p>
            <form method="post" action="<?php echo $post_url; ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::POST_ACTION); ?>">
                <button type="submit" class="button button-primary"
                        onclick="return confirm('Rebuild shadow CPTs for all PeepSo pages?');">
                    Rebuild Shadows
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Admin-post handler: runs the full repair, stores a rich result dict
     * in the shared transient, and redirects back to the repair tab.
     */
    public static function handleRepairPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $log = self::repair(null, true);

        $results = [
            'action'  => 'peepso_rebuild_shadows',
            'details' => is_array($log) ? $log : [],
        ];
        if (isset($log['error'])) {
            $results['error']   = $log['error'];
            $results['details'] = [];
        } else {
            $results['pages_scanned'] = count(array_filter(
                (array) $log,
                static fn($line) => is_string($line) && str_starts_with($line, '🔍')
            ));
            $results['shadows_created'] = count(array_filter(
                (array) $log,
                static fn($line) => is_string($line) && str_contains($line, '✅ Created')
            ));
            $results['titles_fixed'] = count(array_filter(
                (array) $log,
                static fn($line) => is_string($line) && str_contains($line, '🔧 Fixed title')
            ));
        }

        set_transient(self::RESULTS_TRANSIENT, $results, 120);

        wp_safe_redirect(admin_url('admin.php?page=bcc-trust-dashboard&tab=repair'));
        exit;
    }

    public static function scheduleCron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Repair a single page, or all pages if $pageId is null.
     *
     * Returns either ['error' => string] or a log array (list of human-
     * readable lines describing what was done).
     *
     * @param int|null $pageId
     * @param bool     $isCron
     * @return array{error?: string}|array<int, string>
     */
    public static function repair(?int $pageId = null, bool $isCron = false): array
    {
        if (!$isCron && !current_user_can('manage_options')) {
            return ['error' => 'Permission denied'];
        }
        if (!function_exists('bcc_get_category_map')) {
            return ['error' => 'bcc_get_category_map() missing'];
        }
        if (!PeepSoPageRepository::tableExists()) {
            return ['error' => 'PeepSo Pages relation table not found. Is the PeepSo Pages plugin active?'];
        }

        $map = bcc_get_category_map();
        $log = [];

        $pages = $pageId !== null
            ? self::loadSinglePage($pageId)
            : self::loadAllPages();

        if (isset($pages['error'])) {
            return $pages;
        }

        foreach ($pages as $page) {
            $log[] = "🔍 Page {$page->ID}: {$page->post_title}";

            $catIds = PeepSoPageRepository::getCategoryIdsForPage((int) $page->ID);
            if (!$catIds) {
                $log[] = '  ⚠ No categories';
                continue;
            }

            $linked = [];
            foreach ($catIds as $catId) {
                if (!isset($map[(int) $catId]['cpt'])) {
                    continue;
                }
                $cpt = $map[(int) $catId]['cpt'];

                $existing = get_posts([
                    'post_type'      => $cpt,
                    'meta_key'       => '_peepso_page_id',
                    'meta_value'     => $page->ID,
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);

                if ($existing) {
                    $cptId = (int) $existing[0];
                    if (get_the_title($cptId) !== $page->post_title) {
                        wp_update_post([
                            'ID'         => $cptId,
                            'post_title' => $page->post_title,
                            'post_name'  => sanitize_title($page->post_title),
                        ]);
                        $log[] = "  🔧 Fixed title for {$cpt} ({$cptId})";
                    }
                } else {
                    $cptId = AbstractPageType::create_from_page_by_type((int) $page->ID, $cpt);
                    if (!$cptId) {
                        $log[] = "  ❌ Failed creating {$cpt}";
                        continue;
                    }
                    update_post_meta($cptId, '_peepso_cat_id', (int) $catId);
                    $log[] = "  ✅ Created {$cpt} ({$cptId})";
                }

                update_post_meta($page->ID, '_linked_' . $cpt . '_id', $cptId);
                $linked[$cpt] = $cptId;
            }

            update_post_meta($page->ID, '_linked_cpts', $linked);
            $log[] = '  ✔ Page repaired';
            $log[] = '';
        }

        return $log;
    }

    /**
     * Daily cron: find drifted pages and repair them.
     */
    public static function runReconcileCron(): void
    {
        if (!function_exists('bcc_get_category_map')) {
            return;
        }
        if (!PeepSoPageRepository::tableExists()) {
            return;
        }

        $map = bcc_get_category_map();
        if (empty($map)) {
            return;
        }

        $offset   = 0;
        $repaired = 0;

        do {
            $pages = get_posts([
                'post_type'      => 'peepso-page',
                'posts_per_page' => self::BATCH_SIZE,
                'offset'         => $offset,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($pages as $page) {
                if (self::pageNeedsRepair($page, $map)) {
                    self::repair((int) $page->ID, true);
                    $repaired++;
                }
            }

            $offset += self::BATCH_SIZE;
        } while (count($pages) === self::BATCH_SIZE);

        if ($repaired > 0 && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-peepso] Shadow CPT reconciliation', [
                'repaired' => $repaired,
            ]);
        }
    }

    /**
     * @return array{error: string}|array<int, \WP_Post>
     */
    private static function loadSinglePage(int $pageId): array
    {
        $page = get_post($pageId);
        if (!$page || $page->post_type !== 'peepso-page') {
            return ['error' => 'Invalid PeepSo Page'];
        }
        return [$page];
    }

    /**
     * @return array<int, \WP_Post>
     */
    private static function loadAllPages(): array
    {
        $pages  = [];
        $offset = 0;

        do {
            $chunk = get_posts([
                'post_type'      => 'peepso-page',
                'posts_per_page' => self::BATCH_SIZE,
                'offset'         => $offset,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($chunk as $p) {
                $pages[] = $p;
            }

            $offset += self::BATCH_SIZE;
        } while (count($chunk) === self::BATCH_SIZE);

        return $pages;
    }

    /**
     * Detect whether any of a page's expected shadow CPTs is missing
     * or has a drifted title.
     *
     * @param array<int, array{cpt: string, label: string}> $map
     */
    private static function pageNeedsRepair(\WP_Post $page, array $map): bool
    {
        $catIds = PeepSoPageRepository::getCategoryIdsForPage((int) $page->ID);
        if (empty($catIds)) {
            return false;
        }

        foreach ($catIds as $catId) {
            if (!isset($map[(int) $catId]['cpt'])) {
                continue;
            }
            $cpt      = $map[(int) $catId]['cpt'];
            $existing = get_post_meta($page->ID, '_linked_' . $cpt . '_id', true);

            if (!$existing) {
                return true;
            }
            $shadow = get_post($existing);
            if (!$shadow || $shadow->post_title !== $page->post_title) {
                return true;
            }
        }

        return false;
    }
}
