<?php
if (!defined('ABSPATH')) exit;

/**
 * =====================================================
 * PeepSo Page → Shadow CPT Sync Engine
 * =====================================================
 * - Create shadow CPTs on page save
 * - Page title is source of truth
 * - Auto-repair mismatched titles
 * - Prevent duplicates
 * - Set default visibility
 * - Delete shadows when page deleted
 */

/* ----------------------------------------------------
   Queue pages modified in this request
---------------------------------------------------- */

/**
 * Accessor for the pending-pages queue. Uses a static local variable
 * instead of $GLOBALS to avoid polluting the global namespace.
 *
 * @return array<int, array{title: string, author: int}> Reference to the queue.
 */
function &bcc_pending_peepso_pages(): array {
    static $pending = [];
    return $pending;
}

add_action('save_post_peepso-page', function ($post_id, $post, $update) {

    if (!$post) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    static $queued = [];
    if (isset($queued[$post_id])) return;
    $queued[$post_id] = true;

    $pending = &bcc_pending_peepso_pages();
    $pending[$post_id] = [
        'title'  => $post->post_title,
        'author' => (int) $post->post_author,
    ];

}, 10, 3);





/* ----------------------------------------------------
   Sync Engine (Runs After Request)
---------------------------------------------------- */

add_action('shutdown', function () {

    $pending = &bcc_pending_peepso_pages();
    if (empty($pending)) return;
    if (!function_exists('bcc_get_category_map')) return;

    if (!\BCC\PeepSo\Repositories\PeepSoPageRepository::tableExists()) return;

    $map = bcc_get_category_map();

    foreach ($pending as $page_id => $data) {

        $rows = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryRowsForPage((int) $page_id);

        if (empty($rows)) continue;

        $targets = [];

        foreach ($rows as $row) {

            $cat_id = (int) $row->cat_id;

            if (!isset($map[$cat_id]['cpt'])) continue;

            $targets[$map[$cat_id]['cpt']] = $cat_id;
        }

        if (empty($targets)) continue;

        $linked = [];

        foreach ($targets as $post_type => $cat_id) {

            if (!post_type_exists($post_type)) continue;

            // Existing link?
            $existing = get_post_meta($page_id, '_linked_' . $post_type . '_id', true);

            if ($existing && get_post($existing)) {

                // Repair title if wrong
                if (get_the_title($existing) !== $data['title']) {

                    wp_update_post([
                        'ID'         => $existing,
                        'post_title'=> $data['title'],
                        'post_name' => sanitize_title($data['title'])
                    ]);
                }

                $linked[$post_type] = (int) $existing;
                continue;
            }

            // Create shadow CPT via domain layer
            $cpt_id = \BCC\PeepSo\Domain\AbstractPageType::create_from_page_by_type($page_id, $post_type);

            if (!$cpt_id) continue;

            update_post_meta($cpt_id, '_peepso_cat_id', (int) $cat_id);

            $linked[$post_type] = (int) $cpt_id;
        }

        update_post_meta($page_id, '_linked_cpts', $linked);
    }

});




/* ----------------------------------------------------
   Delete Shadow CPTs When Page Deleted
---------------------------------------------------- */

add_action('before_delete_post', function ($post_id) {

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'peepso-page') return;

    $linked = get_post_meta($post_id, '_linked_cpts', true);

    if (!is_array($linked)) return;

    foreach ($linked as $cpt_id) {

        if (!$cpt_id || !get_post($cpt_id)) continue;

        // Clean up gallery collections and images for this shadow CPT
        // before trashing it, so we don't leave orphaned rows.
        if (class_exists('\\BCC\\PeepSo\\Repositories\\GalleryRepository')) {
            bcc_cleanup_gallery_for_post((int) $cpt_id);
        }

        wp_trash_post($cpt_id);
    }

    delete_post_meta($post_id, '_linked_cpts');

}, 10);

/* ----------------------------------------------------
   Daily Reconciliation Cron
   Detects pages where shadow CPTs are missing or have
   drifted titles, and auto-repairs them using the same
   logic as the manual repair tool.
---------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('bcc_shadow_cpt_reconcile')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bcc_shadow_cpt_reconcile');
    }
});

add_action('bcc_shadow_cpt_reconcile', function () {
    if (!function_exists('bcc_get_category_map')) {
        return;
    }
    if (!class_exists('\\BCC\\PeepSo\\Repositories\\PeepSoPageRepository')) {
        return;
    }
    if (!\BCC\PeepSo\Repositories\PeepSoPageRepository::tableExists()) {
        return;
    }

    $map = bcc_get_category_map();
    if (empty($map)) {
        return;
    }

    // Process in batches to avoid memory issues on large sites.
    $offset  = 0;
    $batch   = 50;
    $repaired = 0;

    do {
        $pages = get_posts([
            'post_type'      => 'peepso-page',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        foreach ($pages as $page) {
            $cat_ids = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryIdsForPage((int) $page->ID);
            if (empty($cat_ids)) {
                continue;
            }

            foreach ($cat_ids as $cat_id) {
                if (!isset($map[(int) $cat_id]['cpt'])) {
                    continue;
                }

                $cpt      = $map[(int) $cat_id]['cpt'];
                $existing = get_post_meta($page->ID, '_linked_' . $cpt . '_id', true);

                // Check: shadow exists and title matches?
                if ($existing) {
                    $shadow = get_post($existing);
                    if ($shadow && $shadow->post_title === $page->post_title) {
                        continue; // All good — skip
                    }
                }

                // Drift detected — repair this page
                if (function_exists('bcc_repair_engine')) {
                    bcc_repair_engine($page->ID, true);
                    $repaired++;
                }
                break; // One repair per page is enough (repair_engine handles all CPTs)
            }
        }

        $offset += $batch;
    } while (count($pages) === $batch);

    if ($repaired > 0 && class_exists('\\BCC\\Core\\Log\\Logger')) {
        \BCC\Core\Log\Logger::info('[bcc-peepso] Shadow CPT reconciliation', [
            'repaired' => $repaired,
        ]);
    }
});

/**
 * Remove gallery collections and their images for a given post.
 * Prevents orphaned rows when shadow CPTs are trashed/deleted.
 */
if (!function_exists('bcc_cleanup_gallery_for_post')) {
    function bcc_cleanup_gallery_for_post(int $post_id): void {
        \BCC\PeepSo\Repositories\GalleryRepository::deleteByPostId($post_id);
    }
}
