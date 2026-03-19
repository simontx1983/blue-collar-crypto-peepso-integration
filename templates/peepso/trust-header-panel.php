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
$unique_voters    = $data['unique_voters'] ?? 0;
$verification     = $data['verification'] ?? [];
$profiles         = $data['profiles'] ?? [];
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

    <!-- Verification Signals (stacked, left side) -->
    <div class="bcc-trust-header__block bcc-trust-header__verifications">
<?php
    $providers = [
        'email'  => ['label' => 'Email',  'tooltip' => !empty($verification['email']) ? 'Email verified' : 'Not verified'],
        'wallet' => ['label' => 'Wallet', 'tooltip' => !empty($verification['wallet']) ? 'Wallet connected' : 'Not verified'],
        'x'      => ['label' => 'X',      'tooltip' => ''],
        'github' => ['label' => 'GitHub', 'tooltip' => ''],
    ];

    if ( !empty($verification['x']) && !empty($profiles['x']['username']) ) {
        $providers['x']['tooltip'] = 'X: @' . $profiles['x']['username'];
    } elseif ( empty($verification['x']) ) {
        $providers['x']['tooltip'] = 'Not verified';
    } else {
        $providers['x']['tooltip'] = 'X verified';
    }

    if ( !empty($verification['github']) && !empty($profiles['github']['username']) ) {
        $parts = ['GitHub: @' . $profiles['github']['username']];
        if ( ($profiles['github']['followers'] ?? 0) > 0 ) {
            $parts[] = number_format_i18n($profiles['github']['followers']) . ' followers';
        }
        if ( ($profiles['github']['repos'] ?? 0) > 0 ) {
            $parts[] = number_format_i18n($profiles['github']['repos']) . ' repos';
        }
        $providers['github']['tooltip'] = implode(' · ', $parts);
    } elseif ( empty($verification['github']) ) {
        $providers['github']['tooltip'] = 'Not verified';
    } else {
        $providers['github']['tooltip'] = 'GitHub verified';
    }

    foreach ( $providers as $key => $p ) :
        $is_verified = !empty($verification[$key]);
        $cls         = $is_verified ? 'bcc-trust-header__verification--verified' : 'bcc-trust-header__verification--unverified';
        $icon        = $is_verified ? '&#10004;' : '&#10006;';
?>
        <span class="bcc-trust-header__verification <?php echo esc_attr( $cls ); ?>"
              data-provider="<?php echo esc_attr( $key ); ?>"
              title="<?php echo esc_attr( $p['tooltip'] ); ?>"><?php echo $icon; ?> <?php echo esc_html( $p['label'] ); ?></span>
<?php endforeach; ?>
    </div>

    <!-- Hidden: grade + confidence kept in DOM for JS hydration but not displayed -->
    <span class="bcc-trust-header__grade" data-score="<?php echo esc_attr( (string) $total ); ?>" hidden></span>
    <span class="bcc-trust-header__confidence-val" hidden><?php echo esc_html( (string) $confidence ); ?>%</span>

    <!-- Separator -->
    <span class="bcc-trust-header__sep"></span>

    <!-- Signals -->
    <div class="bcc-trust-header__block bcc-trust-header__signals">
        <?php if ( $show_interactive ) : ?>
        <div class="bcc-trust-header__signal-row">
            <button type="button"
                    class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--positive<?php echo $viewer_vote === 1 ? ' is-active' : ''; ?>"
                    data-vote="1"
                    title="This <?php echo esc_attr( strtolower( $context_label ) ); ?> is trustworthy"
                    <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
                <span class="bcc-trust-header__arrow">&#9650;</span>
                Reputation <strong class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( (string) $votes_up ); ?></strong>
            </button>
            <button type="button"
                    class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--risk<?php echo $viewer_vote === -1 ? ' is-active' : ''; ?>"
                    data-vote="-1"
                    title="This <?php echo esc_attr( strtolower( $context_label ) ); ?> has concerns"
                    <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
                <span class="bcc-trust-header__arrow">&#9888;</span>
                Warnings <strong class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( (string) $votes_down ); ?></strong>
            </button>
        </div>
        <?php else : ?>
        <div class="bcc-trust-header__signal-row">
            <span class="bcc-trust-header__signal-static" title="Community reputation signals">
                <span class="bcc-trust-header__arrow">&#9650;</span>
                Reputation <strong class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( (string) $votes_up ); ?></strong>
            </span>
            <span class="bcc-trust-header__signal-static" title="Community warning signals">
                <span class="bcc-trust-header__arrow">&#9888;</span>
                Warnings <strong class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( (string) $votes_down ); ?></strong>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Separator -->
    <span class="bcc-trust-header__sep"></span>

    <!-- Endorsements -->
    <div class="bcc-trust-header__block bcc-trust-header__credibility">
        <?php if ( $show_interactive ) : ?>
        <button type="button"
                class="bcc-trust-header__endorse-btn<?php echo $viewer_endorsed ? ' is-active' : ''; ?>"
                title="Vouch for this <?php echo esc_attr( strtolower( $context_label ) ); ?>"
                <?php echo ! $logged_in ? 'data-requires-login="1"' : ''; ?>>
            Endorse <strong class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( (string) $endorsements ); ?></strong>
        </button>
        <?php else : ?>
        <span class="bcc-trust-header__signal-static" title="Community endorsements">
            Endorsements <strong class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( (string) $endorsements ); ?></strong>
        </span>
        <?php endif; ?>
    </div>

    <!-- Separator -->
    <span class="bcc-trust-header__sep"></span>

    <!-- Unique Supporters -->
    <div class="bcc-trust-header__block bcc-trust-header__supporters" title="<?php echo esc_attr( $unique_voters . ' unique ' . ($unique_voters === 1 ? 'supporter' : 'supporters') ); ?>">
        <span class="bcc-trust-header__supporters-icon">&#128101;</span>
        <span class="bcc-trust-header__supporters-count"><?php echo esc_html( (string) $unique_voters ); ?></span>
        <span class="bcc-trust-header__supporters-label">Supporters</span>
    </div>

    <!-- Info -->
    <button type="button" class="bcc-trust-header__info-btn" aria-label="How trust signals work" title="How trust signals work">&#9432;</button>

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
                <li><strong>Reputation</strong> &mdash; The community believes the page is reliable.</li>
                <li><strong>Warnings</strong> &mdash; The community believes there may be concerns.</li>
                <li><strong>Endorse</strong> &mdash; Members vouch for the page.</li>
            </ul>
            <p class="bcc-trust-header__popover-footer">Signals help calculate the Trust Score.</p>
        </div>
    </div>

    <!-- Status message -->
    <div class="bcc-trust-header__status" aria-live="polite"></div>
</div>
