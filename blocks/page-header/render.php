<?php
/**
 * Page Header Block – server-side render.
 *
 * Displays the PeepSo Page header: cover image, avatar, project name,
 * owner, trust score, and follower count.
 *
 * Uses PeepSoPage for page data (not PeepSoUser) and ProjectDataLoader
 * for trust data via the shared Redis cache.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content (empty for dynamic blocks).
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

$show_trust_score = (bool) ( $attributes['showTrustScore'] ?? true );
$show_followers   = (bool) ( $attributes['showFollowers'] ?? true );

/* ── Load PeepSoPage ─────────────────────────────────────── */

if ( ! class_exists( 'PeepSoPage' ) ) {
    return;
}

$peepso_page = PeepSoPage::get_instance()->get_page( $page_id );
if ( ! $peepso_page || empty( $peepso_page->id ) ) {
    return;
}

$page_user = new PeepSoPageUser( $peepso_page->id );

/* ── Page identity data ──────────────────────────────────── */

$project_name = esc_html( $peepso_page->name ?? '' );
$description  = esc_html( $peepso_page->description ?? '' );
$cover_url    = esc_url( $peepso_page->get_cover_url() );
$avatar_url   = esc_url( $peepso_page->get_avatar_url_full() );
$followers    = (int) ( $peepso_page->members_count ?? 0 );

// Owner info.
$owner_id   = (int) ( $peepso_page->owner_id ?? $peepso_page->author_id ?? 0 );
$owner_name = '';
if ( $owner_id ) {
    $owner_user = get_userdata( $owner_id );
    $owner_name = $owner_user ? esc_html( $owner_user->display_name ) : '';
}

/* ── Trust data (optional, from bcc-trust-engine) ────────── */

$trust_score = null;
$tier        = 'neutral';

if ( $show_trust_score && class_exists( '\\BCCTrust\\Services\\ProjectDataLoader' ) ) {
    $data = \BCCTrust\Services\ProjectDataLoader::get( $page_id );
    if ( $data ) {
        $trust       = $data['trust'] ?? [];
        $trust_score = isset( $trust['score'] ) ? (int) $trust['score'] : null;
        $tier        = sanitize_key( $trust['tier'] ?? 'neutral' );
    }
}

/* ── Format followers ────────────────────────────────────── */

$followers_fmt = $followers;
if ( $followers >= 1000000 ) {
    $followers_fmt = round( $followers / 1000000, 1 ) . 'M';
} elseif ( $followers >= 1000 ) {
    $followers_fmt = round( $followers / 1000, 1 ) . 'K';
}

/* ── Wrapper attributes ──────────────────────────────────── */

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'        => 'bcc-page-header',
    'data-page-id' => esc_attr( (string) $page_id ),
] );

?>
<div <?php echo $wrapper_attributes; ?>>

    <!-- Cover Image -->
    <div class="bcc-page-header__cover" role="img" aria-label="<?php echo esc_attr( $project_name . ' cover image' ); ?>">
        <?php if ( $cover_url ) : ?>
            <img class="bcc-page-header__cover-img"
                 src="<?php echo $cover_url; ?>"
                 alt=""
                 loading="lazy"
                 decoding="async" />
        <?php else : ?>
            <div class="bcc-page-header__cover-placeholder"></div>
        <?php endif; ?>

        <!-- Avatar (overlapping cover) -->
        <div class="bcc-page-header__avatar">
            <?php if ( $avatar_url ) : ?>
                <img src="<?php echo $avatar_url; ?>"
                     alt="<?php echo esc_attr( $project_name ); ?>"
                     width="96" height="96"
                     loading="lazy"
                     decoding="async" />
            <?php else : ?>
                <div class="bcc-page-header__avatar-placeholder" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Identity Row -->
    <div class="bcc-page-header__identity">
        <div class="bcc-page-header__info">
            <h2 class="bcc-page-header__name"><?php echo $project_name; ?></h2>

            <div class="bcc-page-header__meta">
                <?php if ( $owner_name ) : ?>
                    <span class="bcc-page-header__owner" aria-label="<?php esc_attr_e( 'Project owner', 'blue-collar-crypto' ); ?>">
                        @<?php echo $owner_name; ?>
                    </span>
                <?php endif; ?>

                <?php if ( $show_followers ) : ?>
                    <span class="bcc-page-header__followers" aria-label="<?php echo esc_attr( $followers . ' followers' ); ?>">
                        <?php echo esc_html( (string) $followers_fmt ); ?> <?php echo _n( 'follower', 'followers', $followers, 'blue-collar-crypto' ); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $show_trust_score && $trust_score !== null ) : ?>
            <div class="bcc-page-header__trust bcc-tier-color--<?php echo esc_attr( $tier ); ?>"
                 aria-label="<?php echo esc_attr( 'Trust score: ' . $trust_score . ' out of 100' ); ?>"
                 role="meter"
                 aria-valuenow="<?php echo esc_attr( (string) $trust_score ); ?>"
                 aria-valuemin="0"
                 aria-valuemax="100">
                <span class="bcc-page-header__trust-label"><?php esc_html_e( 'Trust', 'blue-collar-crypto' ); ?></span>
                <span class="bcc-page-header__trust-value"><?php echo esc_html( (string) $trust_score ); ?></span>
            </div>
        <?php endif; ?>
    </div>

</div>
