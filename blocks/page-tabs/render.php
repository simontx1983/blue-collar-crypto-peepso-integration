<?php
/**
 * Page Tabs Navigation Block – server-side render.
 *
 * Renders tab navigation based on the page's PeepSo categories.
 * Maps categories → CPT slugs via bcc_get_category_map() and builds
 * tab links identical to the existing dashboard.php template.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Resolve page ID ─────────────────────────────────────── */

$page_id = (int) ( $attributes['pageId'] ?? 0 );
if ( ! $page_id ) {
    $page_id = get_the_ID();
}
if ( ! $page_id ) {
    return;
}

/* ── Build tabs from category map ────────────────────────── */

global $wpdb;

$category_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT pm_cat_id FROM {$wpdb->prefix}peepso_page_categories WHERE pm_page_id = %d",
        $page_id
    )
);

$category_map = function_exists( 'bcc_get_category_map' ) ? bcc_get_category_map() : [];

$tabs = [];
foreach ( $category_ids as $cat_id ) {
    if ( isset( $category_map[ (int) $cat_id ] ) ) {
        $entry = $category_map[ (int) $cat_id ];
        $key   = $entry['cpt'];
        if ( ! isset( $tabs[ $key ] ) ) {
            $tabs[ $key ] = $entry['label'];
        }
    }
}

if ( empty( $tabs ) ) {
    $tabs = [ 'overview' => 'Overview' ];
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : key( $tabs );
if ( ! isset( $tabs[ $active_tab ] ) ) {
    $active_tab = key( $tabs );
}

/* ── Render ──────────────────────────────────────────────── */

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'        => 'bcc-page-tabs',
    'data-page-id' => esc_attr( (string) $page_id ),
] );

?>
<nav <?php echo $wrapper_attributes; ?> aria-label="<?php esc_attr_e( 'Profile tabs', 'blue-collar-crypto' ); ?>">
    <div class="bcc-page-tabs__inner">
        <?php foreach ( $tabs as $key => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', $key ) ); ?>"
               class="bcc-page-tabs__item <?php echo $active_tab === $key ? 'bcc-page-tabs__item--active' : ''; ?>"
               <?php echo $active_tab === $key ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
