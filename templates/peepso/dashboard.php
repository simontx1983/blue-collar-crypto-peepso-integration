<?php
if (!defined('ABSPATH')) {
    exit;
}
if (!isset($page) || !is_object($page) || empty($page->id)) {
    return;
}

$user_id = get_current_user_id();
if (!$user_id) {
    if (function_exists('peepso_require_login')) {
        peepso_require_login();
    }
    return;
}

$plugin_header_file = plugin_dir_path(__FILE__) . '../../includes/partials/page-header.php';

if (file_exists($plugin_header_file)) {
    include $plugin_header_file;
}

// $category_ids is pre-fetched by segments.php before including this template.
if (!isset($category_ids) || !is_array($category_ids)) {
    $category_ids = [];
}

$category_map = function_exists('bcc_get_category_map') ? bcc_get_category_map() : [];

$tabs = [];

foreach ($category_ids as $cat_id) {
    if (isset($category_map[(int) $cat_id])) {
        $entry = $category_map[(int) $cat_id];
        $key   = $entry['cpt'];

        if (!isset($tabs[$key])) {
            $tabs[$key] = $entry['label'];
        }
    }
}

if (empty($tabs)) {
    $tabs = ['overview' => 'Overview'];
}

$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : key($tabs);

if (!isset($tabs[$active_tab])) {
    $active_tab = key($tabs);
}
?>
<div class="bcc-dashboard-tabs ps-focus__menu ps-js-focus__menu">
    <div class="bcc-dashboard-tabs-inner ps-focus__menu-inner ps-js-focus__menu-inner">

        <?php foreach ($tabs as $key => $label) : ?>

            <a href="<?php echo esc_url(add_query_arg('tab', $key)); ?>"
               class="bcc-tab ps-focus__menu-item bcc-tab-<?php echo esc_attr($key); ?> <?php echo $active_tab === $key ? 'active' : ''; ?>">

                <span class="bcc-tab-label"><?php echo esc_html($label); ?></span>

            </a>

        <?php endforeach; ?>

    </div>
</div>

<div class="bcc-dashboard-content">

<?php

$tab_files = [
    'overview'   => __DIR__ . '/dashboard/overview.php',
    'validators' => __DIR__ . '/dashboard/validator.php',
    'builder'    => __DIR__ . '/dashboard/builder.php',
    'nft'        => __DIR__ . '/dashboard/nft.php',
    'dao'        => __DIR__ . '/dashboard/dao.php',
];

if (isset($tab_files[$active_tab]) && file_exists($tab_files[$active_tab])) {
    include $tab_files[$active_tab];
}

?>
</div>

<div id="bcc-visibility-popover" style="display:none;">
  <div class="bcc-vis-option" data-value="public">
    <span>🌍</span> Public
  </div>
  <div class="bcc-vis-option" data-value="members">
    <span>👥</span> Members
  </div>
  <div class="bcc-vis-option" data-value="private">
    <span>🔒</span> Private
  </div>
</div>