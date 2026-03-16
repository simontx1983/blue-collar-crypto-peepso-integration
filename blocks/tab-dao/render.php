<?php
/**
 * Tab: DAO Block – server-side render.
 *
 * Reuses the existing DAO dashboard template within a block wrapper.
 * The template expects $page (PeepSoPage object) to be in scope.
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
if ( ! $page_id || ! class_exists( 'PeepSoPage' ) ) {
    return;
}

/* ── Load PeepSo Page (template expects $page) ───────────── */

$page = PeepSoPage::get_instance()->get_page( $page_id );
if ( ! $page || empty( $page->id ) ) {
    return;
}

/* ── Permission check ────────────────────────────────────── */

if ( ! is_user_logged_in() ) {
    echo '<p>' . esc_html__( 'Please log in to view this DAO profile.', 'blue-collar-crypto' ) . '</p>';
    return;
}

/* ── Render ──────────────────────────────────────────────── */

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'        => 'bcc-tab-dao',
    'data-page-id' => esc_attr( (string) $page_id ),
] );

$template = BCC_TEMPLATES_PATH . 'peepso/dashboard/dao.php';

?>
<div <?php echo $wrapper_attributes; ?>>
    <?php
    if ( file_exists( $template ) ) {
        include $template;
    }
    ?>
</div>
