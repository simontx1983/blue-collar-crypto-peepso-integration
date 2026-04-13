<?php
/**
 * Trust Header Panel
 *
 * Composes the trust header from:
 *   1. Score gauge + grade badge (inline)
 *   2. Vote/endorse interactive signals (inline)
 *   3. bcc-trust/trust-signals block (embedded via render_block)
 *   4. bcc-trust/wallet-verification block (embedded via render_block, compact)
 *   5. Info popover + status toast
 *
 * Required JS selectors (trust-header.js):
 *   .bcc-trust-header[data-page-id][data-mode][data-context-label]
 *   .bcc-trust-header__grade[data-score]
 *   .bcc-trust-header__confidence-val
 *   .bcc-trust-header__count--positive / --risk / --endorsements
 *   .bcc-trust-header__supporters-count
 *   .bcc-trust-header__vote-btn[data-vote]
 *   .bcc-trust-header__endorse-btn
 *   .bcc-trust-header__info-btn
 *   .bcc-trust-header__popover[hidden]
 *   .bcc-trust-header__popover-close
 *   .bcc-trust-header__status
 */

if ( ! defined( 'ABSPATH' ) || ! $page_id ) {
    return;
}

if ( ! class_exists( '\\BCC\\Core\\ServiceLocator' )
    || ! \BCC\Core\ServiceLocator::hasRealService( \BCC\Core\Contracts\TrustHeaderDataInterface::class )
) {
    return;
}

$service = \BCC\Core\ServiceLocator::resolveTrustHeaderData();

$data = $service->getTrustHeaderData( $page_id, $mode );

$total            = $data['total'];
$confidence       = $data['confidence'];
$endorsements     = $data['endorsements'];
$grade            = $data['grade'];
$votes_up         = $data['votes_up'];
$votes_down       = $data['votes_down'];
$unique_voters    = $data['unique_voters'] ?? 0;
$viewer_vote      = $data['viewer_vote'];
$viewer_endorsed  = $data['viewer_endorsed'];
$show_interactive = $data['show_interactive'];
$context_label    = $data['context_label'];
$page_name        = $data['page_name'];
$logged_in        = $data['logged_in'];

// Score hue: amber (30) → green (120) based on score
$score_hue = 30 + ( $total / 100 ) * 90;

// Viewer permissions
$perms = $data['viewer_perms'] ?? [];
$can_upvote   = ! empty( $perms['can_upvote'] );
$can_downvote = ! empty( $perms['can_downvote'] );
$can_endorse  = ! empty( $perms['can_endorse'] );

// Tooltip text for disabled buttons
$upvote_tip   = $perms['upvote_reason'] ?? '';
$downvote_tip = $perms['downvote_reason'] ?? '';
$endorse_tip  = $perms['endorse_reason'] ?? '';

// Active tooltips for enabled buttons — explain what the action DOES, not how it feels
$upvote_active_tip   = 'Vote that this project is trustworthy. Your vote raises its Trust Score, which helps the community identify reliable projects.';
$downvote_active_tip = 'Vote that this project has trust concerns. Your vote lowers its Trust Score, warning the community about potential risks.';
$endorse_active_tip  = 'Stake your reputation on this project. Endorsements carry more weight than votes because your name is attached.';
$flag_active_tip     = 'Alert the community that something looks suspicious. Flags are visible to other users but do not change the Trust Score.';
$report_active_tip   = 'Confidentially report this user to admins for investigation. Reports are private and reviewed by the moderation team.';

// Login gate attribute for interactive buttons
$login_gate = ! $logged_in ? ' data-requires-login="1" disabled' : '';

// Flag data (signal only — no score impact).
// $flag_count and $viewer_flagged are resolved by the injection layer
// (trust-header-injection.php) and passed into this template's scope.
$flag_count     = $flag_count ?? 0;
$viewer_flagged = $viewer_flagged ?? false;
$page_owner_id  = $data['page_owner_id'] ?? 0;
$is_own_page = $logged_in && (int) get_current_user_id() === (int) $page_owner_id;
$system_degraded = !empty( $data['system_degraded'] );
?>

<?php if ( $system_degraded ) : ?>
<div class="bcc-trust-header__degraded-banner" role="alert"
     style="background:#fef3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;margin-bottom:10px;font-size:13px;color:#856404;text-align:center;">
    Trust data is temporarily limited. Scores may not reflect the latest activity. Voting and endorsements are paused.
</div>
<?php endif; ?>

<div class="bcc-trust-header"
     role="region"
     aria-label="<?php echo esc_attr( 'Trust signals for ' . $page_name ); ?>"
     data-page-id="<?php echo esc_attr( (string) $page_id ); ?>"
     data-mode="<?php echo esc_attr( $mode ); ?>"
     data-context-label="<?php echo esc_attr( $context_label ); ?>">

    <?php /* ── Score Gauge ────────────────────────────────── */ ?>
    <div class="bcc-trust-header__block bcc-trust-header__gauge"
         data-tooltip="Trust Score: <?php echo esc_attr( $total ); ?>/100 (Grade <?php echo esc_attr( $grade ); ?>). Every project starts at 50. Community votes, endorsements, and verified identities move it up or down. Confidence: <?php echo esc_attr( $confidence ); ?>% — how much data backs this score.">
        <div class="bcc-trust-header__ring">
            <svg viewBox="0 0 36 36" class="bcc-trust-header__ring-svg" aria-hidden="true">
                <circle cx="18" cy="18" r="15.9155"
                        fill="none"
                        stroke="rgba(255,255,255,0.06)"
                        stroke-width="2.8" />
                <circle cx="18" cy="18" r="15.9155"
                        fill="none"
                        stroke="hsl(<?php echo esc_attr( $score_hue ); ?>, 85%, 55%)"
                        stroke-width="2.8"
                        stroke-dasharray="<?php echo esc_attr( $total . ' ' . ( 100 - $total ) ); ?>"
                        stroke-dashoffset="25"
                        stroke-linecap="round"
                        class="bcc-trust-header__ring-fill" />
            </svg>
            <span class="bcc-trust-header__score-value" aria-label="<?php echo esc_attr( 'Trust score: ' . $total . ' out of 100' ); ?>"><?php echo esc_html( $total ); ?></span>
        </div>
        <div class="bcc-trust-header__score-meta">
            <span class="bcc-trust-header__grade" data-score="<?php echo esc_attr( $total ); ?>"><?php echo esc_html( $grade ); ?></span>
            <span class="bcc-trust-header__confidence">
                <span class="bcc-trust-header__confidence-val"><?php echo esc_html( $confidence ); ?>%</span> confidence
            </span>
        </div>
    </div>

    <span class="bcc-trust-header__sep" aria-hidden="true"></span>

    <?php /* ── Rating (hidden, used for JS hydration) ───── */ ?>
    <div class="bcc-trust-header__rating" hidden
         data-total="<?php echo esc_attr( $total ); ?>"
         data-confidence="<?php echo esc_attr( $confidence ); ?>"></div>

    <?php /* ── Signals: Votes + Endorsements + Supporters ── */ ?>
    <div class="bcc-trust-header__block bcc-trust-header__signals">
        <div class="bcc-trust-header__signal-row">

            <?php if ( $show_interactive ) : ?>
                <button type="button"
                        class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--positive <?php echo $viewer_vote === 1 ? 'is-active' : ''; ?> <?php echo ! $can_upvote ? 'is-locked' : ''; ?>"
                        data-vote="1"
                        data-tooltip="<?php echo esc_attr( $can_upvote ? $upvote_active_tip : $upvote_tip ); ?>"
                        aria-pressed="<?php echo $viewer_vote === 1 ? 'true' : 'false'; ?>"
                        aria-label="<?php echo esc_attr( 'Upvote, ' . $votes_up . ' votes' ); ?>"
                        <?php echo ! $can_upvote && $logged_in ? 'disabled' : ''; ?>
                        <?php echo $login_gate; ?>>
                    <span class="bcc-trust-header__arrow" aria-hidden="true">&#9650;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( $votes_up ); ?></span>
                </button>

                <button type="button"
                        class="bcc-trust-header__vote-btn bcc-trust-header__vote-btn--risk <?php echo $viewer_vote === -1 ? 'is-active' : ''; ?> <?php echo ! $can_downvote ? 'is-locked' : ''; ?>"
                        data-vote="-1"
                        data-tooltip="<?php echo esc_attr( $can_downvote ? $downvote_active_tip : $downvote_tip ); ?>"
                        aria-pressed="<?php echo $viewer_vote === -1 ? 'true' : 'false'; ?>"
                        aria-label="<?php echo esc_attr( 'Downvote, ' . $votes_down . ' votes' ); ?>"
                        <?php echo ! $can_downvote && $logged_in ? 'disabled' : ''; ?>
                        <?php echo $login_gate; ?>>
                    <span class="bcc-trust-header__arrow" aria-hidden="true">&#9660;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( $votes_down ); ?></span>
                </button>

                <button type="button"
                        class="bcc-trust-header__endorse-btn <?php echo $viewer_endorsed ? 'is-active' : ''; ?> <?php echo ! $can_endorse ? 'is-locked' : ''; ?>"
                        data-tooltip="<?php echo esc_attr( $can_endorse ? $endorse_active_tip : $endorse_tip ); ?>"
                        aria-pressed="<?php echo $viewer_endorsed ? 'true' : 'false'; ?>"
                        aria-label="<?php echo esc_attr( 'Endorse this ' . strtolower( $context_label ) . ', ' . $endorsements . ' endorsements' ); ?>"
                        <?php echo ! $can_endorse && $logged_in ? 'disabled' : ''; ?>
                        <?php echo $login_gate; ?>>
                    <span class="bcc-trust-header__endorse-icon" aria-hidden="true">&#9733;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( $endorsements ); ?></span>
                </button>
            <?php else : ?>
                <span class="bcc-trust-header__signal-static">
                    <span class="bcc-trust-header__arrow" aria-hidden="true">&#9650;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--positive"><?php echo esc_html( $votes_up ); ?></span>
                </span>
                <span class="bcc-trust-header__signal-static">
                    <span class="bcc-trust-header__arrow" aria-hidden="true">&#9660;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--risk"><?php echo esc_html( $votes_down ); ?></span>
                </span>
                <span class="bcc-trust-header__signal-static">
                    <span class="bcc-trust-header__endorse-icon" aria-hidden="true">&#9733;</span>
                    <span class="bcc-trust-header__count bcc-trust-header__count--endorsements"><?php echo esc_html( $endorsements ); ?></span>
                </span>
            <?php endif; ?>

            <span class="bcc-trust-header__signal-static bcc-trust-header__supporters" title="<?php echo esc_attr( $unique_voters . ' unique supporters' ); ?>">
                <span class="bcc-trust-header__supporters-icon" aria-hidden="true">&#9679;&#9679;</span>
                <span class="bcc-trust-header__supporters-count"><?php echo esc_html( $unique_voters ); ?></span>
                <span class="bcc-trust-header__supporters-label">supporters</span>
            </span>
        </div>
    </div>

    <?php /* ── Flag + Report (secondary actions) ────────────── */ ?>
    <?php if ( $show_interactive && ! $is_own_page ) : ?>
        <div class="bcc-trust-header__block bcc-trust-header__secondary-actions">
            <button type="button"
                    class="bcc-trust-header__flag-btn <?php echo $viewer_flagged ? 'is-active' : ''; ?>"
                    data-action="<?php echo esc_attr( $viewer_flagged ? 'unflag' : 'flag' ); ?>"
                    data-tooltip="<?php echo esc_attr( $flag_active_tip ); ?>"
                    aria-pressed="<?php echo esc_attr( $viewer_flagged ? 'true' : 'false' ); ?>"
                    aria-label="<?php echo esc_attr( $viewer_flagged ? 'Remove flag' : 'Flag this page' ); ?>"
                    <?php echo $login_gate; ?>>
                <span aria-hidden="true">&#9873;</span>
                <span class="bcc-trust-header__flag-label"><?php echo esc_html( $viewer_flagged ? 'Flagged' : 'Flag' ); ?></span>
                <?php if ( $flag_count > 0 ) : ?>
                    <span class="bcc-trust-header__flag-count"><?php echo esc_html( $flag_count ); ?></span>
                <?php endif; ?>
            </button>

            <?php if ( $page_owner_id && (int) get_current_user_id() !== (int) $page_owner_id ) : ?>
                <button type="button"
                        class="bcc-trust-header__report-btn"
                        data-tooltip="<?php echo esc_attr( $report_active_tip ); ?>"
                        aria-label="Report this user"
                        data-reported-user-id="<?php echo esc_attr( $page_owner_id ); ?>"
                        <?php echo $login_gate; ?>>
                    <span aria-hidden="true">&#9888;</span>
                    <span>Report</span>
                </button>
            <?php endif; ?>
        </div>
    <?php elseif ( $is_own_page && $flag_count > 0 ) : ?>
        <div class="bcc-trust-header__block bcc-trust-header__secondary-actions">
            <span class="bcc-trust-header__flag-notice" title="Community flags on your page">
                <span aria-hidden="true">&#9873;</span>
                <span><?php echo esc_html( $flag_count ); ?> <?php echo $flag_count === 1 ? 'flag' : 'flags'; ?></span>
            </span>
        </div>
    <?php endif; ?>

    <span class="bcc-trust-header__sep" aria-hidden="true"></span>

    <?php /* ── Trust Signals Block (embedded) ──────────────── */ ?>
    <div class="bcc-trust-header__block-embed bcc-trust-header__block-embed--signals">
        <?php
        echo render_block( [
            'blockName' => 'bcc-trust/trust-signals',
            'attrs'     => [ 'pageId' => $page_id, 'embedded' => true ],
        ] );
        ?>
    </div>

    <span class="bcc-trust-header__sep" aria-hidden="true"></span>

    <?php /* ── Wallet Verification Block (embedded, compact) ─ */ ?>
    <div class="bcc-trust-header__block-embed bcc-trust-header__block-embed--wallet">
        <?php
        echo render_block( [
            'blockName' => 'bcc-trust/wallet-verification',
            'attrs'     => [
                'pageId'  => $page_id,
                'compact' => true,
            ],
        ] );
        ?>
    </div>

    <span class="bcc-trust-header__sep" aria-hidden="true"></span>

    <?php /* ── Info Toggle ─────────────────────────────────── */ ?>
    <button type="button"
            class="bcc-trust-header__info-btn"
            aria-label="About trust signals"
            aria-expanded="false">&#9432;</button>

    <?php /* ── Popover ─────────────────────────────────────── */ ?>
    <div class="bcc-trust-header__popover" hidden>
        <div class="bcc-trust-header__popover-inner">
            <button type="button" class="bcc-trust-header__popover-close" aria-label="Close">&times;</button>
            <h4 class="bcc-trust-header__popover-title">How Trust Signals Work</h4>
            <p>Trust signals measure community confidence, not just popularity.</p>
            <ul class="bcc-trust-header__popover-list">
                <li><strong>&#9650; Reputation</strong> &mdash; community members find this reliable</li>
                <li><strong>&#9660; Warnings</strong> &mdash; community members have concerns</li>
                <li><strong>&#9733; Endorsements</strong> &mdash; members actively vouch for this</li>
            </ul>
            <p class="bcc-trust-header__popover-footer">Combined signals produce the <strong>Trust Score</strong>. Verifications raise confidence.</p>
        </div>
    </div>

    <?php /* ── Status Toast ────────────────────────────────── */ ?>
    <div class="bcc-trust-header__status" aria-live="polite"></div>
</div>
