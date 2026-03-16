<?php
/**
 * Tab: About Block – server-side render.
 *
 * Displays the About / Overview content for a PeepSo Page.
 * Shows page description, categories, and basic project info
 * pulled from the REST endpoint data.
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

/* ── Load PeepSo Page ────────────────────────────────────── */

$peepso_page = PeepSoPage::get_instance()->get_page( $page_id );
if ( ! $peepso_page || empty( $peepso_page->id ) ) {
    return;
}

$description = $peepso_page->description ?? '';
$description = stripslashes( $description );

if ( PeepSo::get_option_new( 'md_pages_about', 0 ) ) {
    $description = PeepSo::do_parsedown( $description );
} else {
    $description = nl2br( esc_html( $description ) );
}

/* ── Categories ──────────────────────────────────────────── */

$page_categories = [];
if ( class_exists( 'PeepSoPageCategoriesPages' ) ) {
    $page_categories = PeepSoPageCategoriesPages::get_categories_for_page( $peepso_page->id );
}

/* ── Project data from REST endpoint ─────────────────────── */

$project_data = null;
if ( class_exists( '\\BCCTrust\\Services\\ProjectDataLoader' ) ) {
    $project_data = \BCCTrust\Services\ProjectDataLoader::get( $page_id );
}

$project  = $project_data['project']    ?? [];
$builder  = $project_data['builder']    ?? [];
$reputation = $project_data['reputation'] ?? [];

/* ── Render ──────────────────────────────────────────────── */

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'        => 'bcc-tab-about',
    'data-page-id' => esc_attr( (string) $page_id ),
] );

?>
<div <?php echo $wrapper_attributes; ?>>

    <?php if ( $description ) : ?>
    <section class="bcc-section bcc-section-description">
        <h3 class="bcc-section-title"><?php esc_html_e( 'About', 'blue-collar-crypto' ); ?></h3>
        <div class="bcc-tab-about__description">
            <?php echo wp_kses_post( $description ); ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ( ! empty( $page_categories ) ) : ?>
    <section class="bcc-section bcc-section-categories">
        <h3 class="bcc-section-title"><?php esc_html_e( 'Categories', 'blue-collar-crypto' ); ?></h3>
        <div class="bcc-tab-about__categories">
            <?php foreach ( $page_categories as $cat ) : ?>
                <a href="<?php echo esc_url( $cat->get_url() ); ?>" class="bcc-tab-about__category-pill">
                    <?php echo esc_html( $cat->name ); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ( $project_data ) : ?>
    <section class="bcc-section bcc-section-details">
        <h3 class="bcc-section-title"><?php esc_html_e( 'Details', 'blue-collar-crypto' ); ?></h3>
        <dl class="bcc-tab-about__details">
            <?php if ( ! empty( $builder['username'] ) ) : ?>
                <div class="bcc-tab-about__detail-row">
                    <dt><?php esc_html_e( 'Owner', 'blue-collar-crypto' ); ?></dt>
                    <dd><?php echo esc_html( $builder['username'] ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $reputation['project_age'] ) ) : ?>
                <div class="bcc-tab-about__detail-row">
                    <dt><?php esc_html_e( 'Active Since', 'blue-collar-crypto' ); ?></dt>
                    <dd><?php
                        $start_year = (int) date( 'Y' ) - (int) floor( (float) $reputation['project_age'] );
                        echo esc_html( (string) $start_year );
                    ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( isset( $reputation['followers'] ) ) : ?>
                <div class="bcc-tab-about__detail-row">
                    <dt><?php esc_html_e( 'Followers', 'blue-collar-crypto' ); ?></dt>
                    <dd><?php echo esc_html( number_format_i18n( (int) $reputation['followers'] ) ); ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>
    <?php endif; ?>

</div>
