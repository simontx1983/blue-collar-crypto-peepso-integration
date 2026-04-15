<?php
/**
 * BCC PeepSo Segment Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('peepso_page_segment_menu_links', function ($segments) {

    $segments[0][] = [
        'href'  => 'dashboard',
        'title' => __('Dashboard', 'blue-collar-crypto'),
        'icon'  => 'gsi gsi-home',
    ];

    return $segments;

});


add_action('peepso_page_segment_dashboard', function ($args, $url) {

    if (
        empty($args['page']) ||
        !is_object($args['page']) ||
        empty($args['page']->id)
    ) {
        return; // fail silently
    }

    $page = $args['page'];

    // Pre-fetch category IDs for this page so the template has no DB queries.
    $category_ids = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryIdsForPage((int) $page->id);

    $template = BCC_PEEPSO_TEMPLATES_PATH . 'peepso/dashboard.php';

    if (file_exists($template)) {
        include $template;
    }

}, 10, 2);