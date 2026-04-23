<?php

namespace BCC\PeepSo\Services;

use BCC\PeepSo\Domain\AbstractPageType;
use BCC\PeepSo\Repositories\LockRepository;
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
    private const CRON_CONTINUE_HOOK = 'bcc_shadow_cpt_reconcile_continue';
    private const CRON_OFFSET_TRANSIENT = 'bcc_shadow_cpt_reconcile_offset';
    private const CRON_OFFSET_TTL = 86400; // 24h — a stuck offset self-heals at daily tick.
    private const BATCH_SIZE    = 50;
    private const POST_ACTION   = 'bcc_peepso_rebuild_shadows';
    private const NONCE_ACTION  = 'bcc_peepso_rebuild_shadows';
    private const RESULTS_TRANSIENT = 'bcc_trust_repair_results';
    private const BATCH_HOOK     = 'bcc_peepso_repair_batch';
    private const JOB_TRANSIENT  = 'bcc_peepso_repair_job_';
    private const JOB_TTL        = 3600;

    public static function register(): void
    {
        add_action('bcc_trust_repair_tab_extra_tools', [self::class, 'renderRepairTile']);
        add_action('admin_post_' . self::POST_ACTION, [self::class, 'handleRepairPost']);
        add_action('init', [self::class, 'scheduleCron']);
        add_action(self::CRON_HOOK, [self::class, 'runReconcileCron']);
        add_action(self::CRON_CONTINUE_HOOK, [self::class, 'runReconcileCron']);
        add_action(self::BATCH_HOOK, [self::class, 'runBatch'], 10, 1);
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

        $post_url = admin_url('admin-post.php');
        $running  = self::getRunningJob();
        ?>
        <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
            <h3 style="margin-top:0;">Rebuild Shadow CPTs</h3>
            <p style="font-size:13px;color:#666;">Scans every PeepSo page and creates any missing shadow CPT (validators / builder / nft / dao), repairing drifted titles along the way. Runs asynchronously in batches — safe to leave the page.</p>
            <?php if ($running !== null) : ?>
                <p style="font-size:13px;color:#0073aa;">
                    ⚙️ Job in progress:
                    <strong><?php echo (int) $running['scanned']; ?></strong> pages scanned,
                    <strong><?php echo (int) $running['created']; ?></strong> shadows created,
                    <strong><?php echo (int) $running['titles_fixed']; ?></strong> titles fixed.
                    Refresh to update.
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::POST_ACTION); ?>">
                <button type="submit" class="button button-primary"
                        <?php echo $running !== null ? 'disabled' : ''; ?>
                        onclick="return confirm('Rebuild shadow CPTs for all PeepSo pages?');">
                    <?php echo $running !== null ? 'Running…' : 'Rebuild Shadows'; ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Admin-post handler: queues an asynchronous repair job and redirects
     * back to the repair tab. The previous synchronous implementation
     * iterated every peepso-page under one admin request and timed out on
     * larger sites (~3k+ pages) under default PHP max_execution_time.
     *
     * Work happens in {@see self::runBatch()}, invoked via
     * wp_schedule_single_event so the HTTP response returns immediately.
     */
    public static function handleRepairPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        // Preflight: if required dependencies are missing, surface the
        // error synchronously so the admin sees it immediately rather
        // than waiting for a batch that can never run.
        if (!function_exists('bcc_get_category_map')) {
            set_transient(self::RESULTS_TRANSIENT, [
                'action' => 'peepso_rebuild_shadows',
                'error'  => 'bcc_get_category_map() missing',
            ], 120);
            wp_safe_redirect(admin_url('admin.php?page=bcc-trust-dashboard&tab=repair'));
            exit;
        }
        if (!PeepSoPageRepository::tableExists()) {
            set_transient(self::RESULTS_TRANSIENT, [
                'action' => 'peepso_rebuild_shadows',
                'error'  => 'PeepSo Pages relation table not found. Is the PeepSo Pages plugin active?',
            ], 120);
            wp_safe_redirect(admin_url('admin.php?page=bcc-trust-dashboard&tab=repair'));
            exit;
        }

        // If a job is already running, do not start a second one — reuse
        // the existing job id so the UI continues to poll the same record.
        $existing = self::getRunningJob();
        if ($existing !== null) {
            wp_safe_redirect(admin_url('admin.php?page=bcc-trust-dashboard&tab=repair'));
            exit;
        }

        try {
            $jobId = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $jobId = 'j' . (string) mt_rand() . (string) time();
        }

        set_transient(self::JOB_TRANSIENT . $jobId, [
            'status'       => 'running',
            'offset'       => 0,
            'scanned'      => 0,
            'created'      => 0,
            'titles_fixed' => 0,
            'errors'       => [],
            'started_at'   => time(),
        ], self::JOB_TTL);

        set_transient(self::RESULTS_TRANSIENT, [
            'action' => 'peepso_rebuild_shadows',
            'queued' => true,
            'job_id' => $jobId,
        ], 300);

        wp_schedule_single_event(time() + 1, self::BATCH_HOOK, [$jobId]);

        wp_safe_redirect(admin_url('admin.php?page=bcc-trust-dashboard&tab=repair'));
        exit;
    }

    /**
     * Process one batch of the repair job, then either reschedule for
     * the next batch or mark the job complete. Idempotent — if the
     * transient is gone (TTL expired, explicit cancel) the batch is a
     * no-op so a delayed cron re-fire cannot resurrect a cancelled job.
     *
     * @param string $jobId
     */
    public static function runBatch($jobId): void
    {
        if (!is_string($jobId) || $jobId === '') {
            return;
        }

        $key = self::JOB_TRANSIENT . $jobId;
        $job = get_transient($key);
        if (!is_array($job) || ($job['status'] ?? '') !== 'running') {
            return;
        }

        if (!function_exists('bcc_get_category_map') || !PeepSoPageRepository::tableExists()) {
            $job['status']       = 'error';
            $job['error']        = 'Dependencies missing at batch execution time';
            $job['completed_at'] = time();
            set_transient($key, $job, self::JOB_TTL);
            return;
        }

        $offset = (int) ($job['offset'] ?? 0);
        $pages  = get_posts([
            'post_type'      => 'peepso-page',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $offset,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        foreach ($pages as $page) {
            $log = self::repair((int) $page->ID, true);
            if (isset($log['error'])) {
                $job['errors'][] = 'Page ' . (int) $page->ID . ': ' . $log['error'];
                $job['scanned']++;
                continue;
            }
            /** @var array<int, string> $log */
            $job['scanned']++;
            foreach ($log as $line) {
                if (!is_string($line)) {
                    continue;
                }
                if (str_contains($line, '✅ Created')) {
                    $job['created']++;
                } elseif (str_contains($line, '🔧 Fixed title')) {
                    $job['titles_fixed']++;
                }
            }
        }

        $job['offset'] = $offset + self::BATCH_SIZE;

        if (count($pages) === self::BATCH_SIZE) {
            set_transient($key, $job, self::JOB_TTL);
            wp_schedule_single_event(time() + 1, self::BATCH_HOOK, [$jobId]);
            return;
        }

        // No more pages — finalize.
        $job['status']       = 'complete';
        $job['completed_at'] = time();
        set_transient($key, $job, self::JOB_TTL);

        set_transient(self::RESULTS_TRANSIENT, [
            'action'          => 'peepso_rebuild_shadows',
            'pages_scanned'   => (int) $job['scanned'],
            'shadows_created' => (int) $job['created'],
            'titles_fixed'    => (int) $job['titles_fixed'],
            'errors'          => $job['errors'],
            'completed_at'    => $job['completed_at'],
        ], 600);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-peepso] repair job complete', [
                'job_id'        => $jobId,
                'scanned'       => (int) $job['scanned'],
                'created'       => (int) $job['created'],
                'titles_fixed'  => (int) $job['titles_fixed'],
                'error_count'   => count($job['errors']),
                'duration_s'    => $job['completed_at'] - (int) $job['started_at'],
            ]);
        }
    }

    /**
     * Return the currently-running job record if the queued job id in
     * the results transient maps to a transient still marked running.
     * Used by {@see self::renderRepairTile()} and by handleRepairPost()
     * to prevent double-queues.
     *
     * @return array{status: string, offset: int, scanned: int, created: int, titles_fixed: int, errors: list<string>, started_at: int}|null
     */
    private static function getRunningJob(): ?array
    {
        $results = get_transient(self::RESULTS_TRANSIENT);
        if (!is_array($results) || empty($results['job_id']) || !is_string($results['job_id'])) {
            return null;
        }
        $job = get_transient(self::JOB_TRANSIENT . $results['job_id']);
        if (!is_array($job) || ($job['status'] ?? '') !== 'running') {
            return null;
        }
        /** @var array{status: string, offset: int, scanned: int, created: int, titles_fixed: int, errors: list<string>, started_at: int} $job */
        return $job;
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

        if ($pageId !== null) {
            $single = self::loadSinglePage($pageId);
            if ($single === null) {
                return ['error' => 'Invalid PeepSo Page'];
            }
            $pages = [$single];
        } else {
            $pages = self::loadAllPages();
        }

        foreach ($pages as $page) {
            $log[] = "🔍 Page {$page->ID}: {$page->post_title}";

            // Serialize with flushQueue / cascadeDelete — same lock key namespace
            // as ShadowPageSyncService so all three writers of `_linked_cpts`
            // are single-writer per page. Skipping on acquire-fail is safe:
            // the next cron or admin-click picks the page up again.
            $lockKey     = 'integrity_' . (int) $page->ID;
            $lockAcquired = LockRepository::tryAcquire($lockKey, 30);
            if (!$lockAcquired) {
                $log[] = '  ⏭ Skipped (integrity lock held by concurrent writer)';
                continue;
            }

            try {
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

                    // NUMERIC meta compare so repair doesn't miss a
                    // shadow whose _peepso_page_id was written as an int
                    // rather than a string — a miss here would trigger a
                    // spurious create, producing a duplicate shadow.
                    $existing = get_posts([
                        'post_type'      => $cpt,
                        'meta_query'     => [[
                            'key'     => '_peepso_page_id',
                            'value'   => (int) $page->ID,
                            'compare' => '=',
                            'type'    => 'NUMERIC',
                        ]],
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
            } finally {
                LockRepository::release($lockKey);
            }
        }

        return $log;
    }

    /**
     * Daily cron entry: processes ONE batch and, if more pages remain,
     * schedules a continuation event rather than looping until PHP
     * times out. The prior single-invocation do-while would iterate
     * every peepso-page in one shot — realistic >10k-page sites
     * exceeded max_execution_time, the cron silently died mid-run,
     * and no continuation checkpoint was kept so the next day started
     * over and hit the same wall at the same prefix. Pages past that
     * prefix were never reconciled.
     *
     * State is an offset kept in a transient with a 24h TTL — if a
     * continuation event is dropped (queue flushed, object cache
     * reset), the next daily tick resets to 0 and tries again.
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

        $offset   = (int) get_transient(self::CRON_OFFSET_TRANSIENT);
        if ($offset < 0) {
            $offset = 0;
        }

        $pages = get_posts([
            'post_type'        => 'peepso-page',
            'posts_per_page'   => self::BATCH_SIZE,
            'offset'           => $offset,
            'post_status'      => 'publish',
            'no_found_rows'    => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            // Determinism: reconciliation must see every page, not a
            // filtered subset another plugin narrows via posts_where.
            'suppress_filters' => true,
        ]);

        $repaired = 0;
        foreach ($pages as $page) {
            if (self::pageNeedsRepair($page, $map)) {
                self::repair((int) $page->ID, true);
                $repaired++;
            }
        }

        // Persist the incremented offset. If a full batch came back,
        // schedule the next batch as a single event and let the WP
        // cron runner pick it up in a fresh PHP invocation with a
        // fresh max_execution_time budget.
        if (count($pages) === self::BATCH_SIZE) {
            set_transient(self::CRON_OFFSET_TRANSIENT, $offset + self::BATCH_SIZE, self::CRON_OFFSET_TTL);
            if (!wp_next_scheduled(self::CRON_CONTINUE_HOOK)) {
                wp_schedule_single_event(time() + 60, self::CRON_CONTINUE_HOOK);
            }
        } else {
            // Reconciliation completed a full pass — clear the offset
            // so tomorrow's daily tick starts from the top.
            delete_transient(self::CRON_OFFSET_TRANSIENT);
        }

        if ($repaired > 0 && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-peepso] Shadow CPT reconciliation batch', [
                'offset'   => $offset,
                'repaired' => $repaired,
                'more'     => count($pages) === self::BATCH_SIZE,
            ]);
        }
    }

    private static function loadSinglePage(int $pageId): ?\WP_Post
    {
        $page = get_post($pageId);
        if (!$page instanceof \WP_Post || $page->post_type !== 'peepso-page') {
            return null;
        }
        return $page;
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
