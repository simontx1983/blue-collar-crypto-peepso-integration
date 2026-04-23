<?php
/**
 * BCC PeepSo Segment Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('peepso_page_segment_menu_links', function ($segments) {

    $dashboard = [
        'href'  => 'dashboard',
        'title' => __('Dashboard', 'blue-collar-crypto'),
        'icon'  => 'gsi gsi-home',
    ];

    // Shape defense. PeepSo's contract for this filter is "segments are
    // grouped by numeric index at [0]", but a future version or another
    // plugin upstream of us could normalize to non-numeric keys. If our
    // assumption doesn't hold, fall back to a namespaced key so the
    // dashboard link at least appears somewhere coherent, rather than
    // silently materializing a phantom [0] bucket PeepSo's renderer may
    // ignore.
    if (!is_array($segments)) {
        return ['bcc' => [$dashboard]];
    }
    if (!isset($segments[0]) || !is_array($segments[0])) {
        $segments['bcc'][] = $dashboard;
        return $segments;
    }

    $segments[0][] = $dashboard;
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

    // Validate that the page is a published peepso-page before rendering.
    $post = get_post((int) $page->id);
    if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'peepso-page') {
        return;
    }

    // Pre-fetch category IDs for this page so the template has no DB queries.
    $category_ids = \BCC\PeepSo\Repositories\PeepSoPageRepository::getCategoryIdsForPage((int) $page->id);

    $template = BCC_PEEPSO_TEMPLATES_PATH . 'peepso/dashboard.php';

    if (file_exists($template)) {
        include $template;
    }

}, 10, 2);