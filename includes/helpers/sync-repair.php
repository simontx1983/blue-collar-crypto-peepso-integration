<?php
if (!defined('ABSPATH')) exit;


/* ----------------------------------------------------
   CORE REPAIR ENGINE
---------------------------------------------------- */

if (!function_exists('bcc_repair_engine')) {

function bcc_repair_engine($page_id = null) {

    if (!current_user_can('manage_options')) {
        return ['error' => 'Permission denied'];
    }

    if (!function_exists('bcc_get_category_map')) {
        return ['error' => 'bcc_get_category_map() missing'];
    }

    if (!\BCC\PeepSo\Repositories\PeepSoPageRepository::tableExists()) {
        return ['error' => 'PeepSo Pages relation table not found. Is the PeepSo Pages plugin active?'];
    }

    $map = bcc_get_category_map();
    $log = [];

    /* ----------------------------
       Fetch pages
    ---------------------------- */

    if ($page_id) {

        $page = get_post($page_id);

        if (!$page || $page->post_type !== 'peepso-page') {
            return ['error' => 'Invalid PeepSo Page'];
        }

        $pages = [$page];

    } else {

        $pages  = [];
        $offset = 0;
        $batch  = 50;

        do {
            $chunk = get_posts([
                'post_type'      => 'peepso-page',
                'posts_per_page' => $batch,
                'offset'         => $offset,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($chunk as $p) {
                $pages[] = $p;
            }

            $offset += $batch;
        } while (count($chunk) === $batch);
    }

    foreach ($pages as $page) {

        $log[] = "🔍 Page {$page->ID}: {$page->post_title}";

        $cat_ids = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryIdsForPage((int) $page->ID);

        if (!$cat_ids) {
            $log[] = "  ⚠ No categories";
            continue;
        }

        $linked = [];

        foreach ($cat_ids as $cat_id) {

            if (!isset($map[(int) $cat_id]['cpt'])) {
                continue;
            }

            $cpt = $map[(int) $cat_id]['cpt'];

            /* ----------------------------
               Find existing shadow CPT
            ---------------------------- */

            $existing = get_posts([
                'post_type'      => $cpt,
                'meta_key'       => '_peepso_page_id',
                'meta_value'     => $page->ID,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);

            if ($existing) {

                $cpt_id = (int) $existing[0];

                // Repair title
                if (get_the_title($cpt_id) !== $page->post_title) {

                    wp_update_post([
                        'ID'         => $cpt_id,
                        'post_title'=> $page->post_title,
                        'post_name' => sanitize_title($page->post_title)
                    ]);

                    $log[] = "  🔧 Fixed title for {$cpt} ({$cpt_id})";
                }

            } else {

                /* ----------------------------
                   Create missing CPT
                ---------------------------- */

                $cpt_id = \BCC\PeepSo\Domain\AbstractPageType::create_from_page_by_type($page->ID, $cpt);

                if (!$cpt_id) {
                    $log[] = "  ❌ Failed creating {$cpt}";
                    continue;
                }

                update_post_meta($cpt_id, '_peepso_cat_id', $cat_id);

                $log[] = "  ✅ Created {$cpt} ({$cpt_id})";
            }

            update_post_meta($page->ID, '_linked_' . $cpt . '_id', $cpt_id);
            $linked[$cpt] = $cpt_id;
        }

        update_post_meta($page->ID, '_linked_cpts', $linked);
        $log[] = "  ✔ Page repaired";
        $log[] = "";
    }

    return $log;
}

}



/* ----------------------------------------------------
   ADMIN UI
---------------------------------------------------- */

function bcc_render_repair_page() {

    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap"><h1>BCC Repair Tool</h1>';

    if (!function_exists('bcc_get_category_map')) {
        echo '<div class="notice notice-error" style="padding:12px;">'
           . '<strong>Repair tool unavailable:</strong> <code>bcc_get_category_map()</code> is not defined. '
           . 'This function must be implemented in <code>includes/helpers/sync-repair.php</code> (or included from another file) '
           . 'before the repair tool can run. It should return an array mapping PeepSo category IDs to CPT slugs, e.g.:<br><br>'
           . '<code>[ 254 => [\'cpt\' => \'validators\'], 268 => [\'cpt\' => \'builder\'], ... ]</code>'
           . '</div></div>';
        return;
    }

    if (isset($_POST['bcc_run_repair'])) {

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bcc_run_repair')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div></div>';
            return;
        }

        $page_id = !empty($_POST['bcc_page_id'])
            ? absint($_POST['bcc_page_id'])
            : null;

        $result = bcc_repair_engine($page_id);

        if (isset($result['error'])) {
            echo '<div class="notice notice-error" style="padding:12px;"><strong>Error:</strong> '
               . esc_html($result['error']) . '</div>';
        } else {
            echo '<pre style="background:#111;color:#0f0;padding:15px;">';
            foreach ($result as $line) {
                echo esc_html($line) . "\n";
            }
            echo "</pre>";
        }

    } else {

        echo '<form method="post">';
        wp_nonce_field('bcc_run_repair');
        echo '  <p>Optional: Repair single PeepSo Page ID</p>
                <input type="number" name="bcc_page_id" />
                <p>
                    <button class="button button-primary" name="bcc_run_repair">
                        Run Repair
                    </button>
                </p>
              </form>';
    }

    echo '</div>';
}

add_action('admin_menu', function () {

    add_submenu_page(
        'tools.php',
        'BCC Repair Tool',
        'BCC Repair Tool',
        'manage_options',
        'bcc-repair-tool',
        'bcc_render_repair_page'
    );
});
