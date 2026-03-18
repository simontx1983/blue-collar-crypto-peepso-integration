<?php
/**
 * Trust Header Panel -- injected after PeepSo page header.
 *
 * RENDER-ONLY TEMPLATE. All data is prepared by
 * BCC\Trust\Integration\PeepSoIntegration::getTrustHeaderData().
 *
 * Expected in scope:
 *   $page_id (int) -- the PeepSo Page ID.
 *   $mode    (string) -- 'public' or 'dashboard'.
 *
 * @package Blue_Collar_Crypto
 */

if ( ! defined( 'ABSPATH' ) || ! $page_id ) {
    return;
}

if ( ! class_exists( '\\BCC\\Trust\\Plugin' ) ) {
    return;
}

$data = \BCC\Trust\Plugin::instance()->peepSoIntegration()->getTrustHeaderData( $page_id, $mode );

$total            = $data['total'];
$confidence       = $data['confidence'];
$endorsements     = $data['endorsements'];
$grade            = $data['grade'];
$votes_up         = $data['votes_up'];
$votes_down       = $data['votes_down'];
$viewer_vote      = $data['viewer_vote'];
$viewer_endorsed  = $data['viewer_endorsed'];
$show_interactive = $data['show_interactive'];
$context_label    = $data['context_label'];
$page_name        = $data['page_name'];
$logged_in        = $data['logged_in'];
?>
<div class="bcc-trust-header"
     data-page-id="<?php echo esc_attr( (string) $page_id ); ?>"
     data-mode="<?php echo esc_attr( $mode ); ?>"
     data-context-label="<?php echo esc_attr( $context_label ); ?>"
     data-viewer-vote="<?php echo esc_attr( (string) $viewer_vote ); ?>"
     data-viewer-endorsed="<?php echo esc_attr( $viewer_endorsed ? '1' : '0' ); ?>"
     data-page-name="<?php echo esc_attr( $page_name ); ?>">

    <!-- Trust Score -->
    <div class="bcc-trust-header__block bcc-trust-header__rating">
        <span class="bcc-trust-header__label"><?php echo esc_html( $context_label ); ?> Trust Score</span>
        <span class="bcc-trust-header__grade" data-score="<?php echo esc_attr( (string) $total ); ?>"><?php echo esc_html( $grade ); ?></span>
        <span class="bcc-trust-header__confidence">Confidence: <strong class="bcc-trust-header__confidence-val"><?php echo esc_html( (string) $confidence ); ?>%</strong></span>
    </div>

    <!-- Signals -->
    <div class="bcc-trust-header__block bcc-trust-header__signals">
        <span class="bcc-trust-header__label">
            Signals
            <button type="button" class="bcc-trust-header__info-btn" aria-label="How trust signals work">&#9432;</button>
        </span>

        <?php if ( $show_interactive ) : ?>
        <div class="bcc-trust-header__signal-row">
            <button type="button"
                    class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--positive<?php echo $viewer_vote === 1 ? ' is-active' : ''; ?>"
                    data-vote="1"
                    <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
                <span class="bcc-trust-header__arrow">&#9650;</span>
                Positive <strong class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( (string) $votes_up ); ?></strong>
            </button>
            <button type="button"
                    class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--risk<?php echo $viewer_vote === -1 ? ' is-active' : ''; ?>"
                    data-vote="-1"
                    <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
                <span class="bcc-trust-header__arrow">&#9660;</span>
                Risk <strong class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( (string) $votes_down ); ?></strong>
            </button>
        </div>
        <?php else : ?>
        <div class="bcc-trust-header__signal-row">
            <span class="bcc-trust-header__signal-static">
                <span class="bcc-trust-header__arrow">&#9650;</span>
                Positive <strong class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( (string) $votes_up ); ?></strong>
            </span>
            <span class="bcc-trust-header__signal-static">
                <span class="bcc-trust-header__arrow">&#9660;</span>
                Risk <strong class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( (string) $votes_down ); ?></strong>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Endorsements -->
    <div class="bcc-trust-header__block bcc-trust-header__credibility">
        <span class="bcc-trust-header__label">Endorsements</span>
        <?php if ( $show_interactive ) : ?>
        <button type="button"
                class="bcc-trust-header__endorse-btn<?php echo $viewer_endorsed ? ' is-active' : ''; ?>"
                <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
            <span class="bcc-trust-header__shield">&#128737;</span>
            Endorse <?php echo esc_html( $context_label ); ?> <strong class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( (string) $endorsements ); ?></strong>
        </button>
        <?php else : ?>
        <span class="bcc-trust-header__signal-static">
            <span class="bcc-trust-header__shield">&#128737;</span>
            Endorsements <strong class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( (string) $endorsements ); ?></strong>
        </span>
        <?php endif; ?>
    </div>

    <!-- Wallet connect -->
    <div class="bcc-trust-header__wallet">
        <!-- wallet-connect.js handles independently -->
    </div>

    <!-- Tooltip popover (hidden by default) -->
    <div class="bcc-trust-header__popover" role="dialog" aria-label="How Trust Signals Work" hidden>
        <div class="bcc-trust-header__popover-inner">
            <button type="button" class="bcc-trust-header__popover-close" aria-label="Close">&times;</button>
            <h4 class="bcc-trust-header__popover-title">How Trust Signals Work</h4>
            <p>Trust signals measure trustworthiness, not popularity.</p>
            <ul class="bcc-trust-header__popover-list">
                <li><strong>Positive</strong> &mdash; The community believes the page is reliable.</li>
                <li><strong>Risk</strong> &mdash; The community believes there may be concerns.</li>
                <li><strong>Endorse</strong> &mdash; Members vouch for the page.</li>
            </ul>
            <p class="bcc-trust-header__popover-footer">Signals help calculate the Trust Score.</p>
        </div>
    </div>

    <!-- Status message -->
    <div class="bcc-trust-header__status" aria-live="polite"></div>
</div>
