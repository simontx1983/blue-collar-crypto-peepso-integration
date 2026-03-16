<?php
/**
 * BCC Setup Checklist
 *
 * Shown on the Dashboard tab for page owners who have a new/incomplete project.
 * Guides them through the steps that most improve their trust score.
 *
 * Expected variables (set by dashboard.php before include):
 *   $page      — PeepSoPage object
 *   $user_id   — int, current user
 *   $active_tab — string
 */

if (!defined('ABSPATH')) exit;

// Only show to the page owner
$ps_page_user = new PeepSoPageUser($page->id);
if (!$ps_page_user->can('manage_page')) {
    return;
}

/* ── Check each step ─────────────────────────────────────── */

// 1. Description (meaningful = more than 30 chars)
$has_description = !empty($page->description) && strlen(trim(strip_tags($page->description))) > 30;

// 2. Cover photo — page-header.php uses stripos($coverUrl, 'peepso/pages/') as indicator
$cover_url   = $page->get_cover_url();
$has_cover   = (false !== stripos($cover_url, 'peepso/pages/'));

// 3. Avatar
$has_avatar = method_exists($page, 'has_avatar') ? $page->has_avatar() : false;

// 4. Project type profile fields — check at least one non-empty ACF field on the CPT
$has_profile_data = false;
$profile_post_id  = 0;

if (function_exists('bcc_get_builder_id'))   $profile_post_id = bcc_get_builder_id($page->id);
if (!$profile_post_id && function_exists('bcc_get_validator_id')) $profile_post_id = bcc_get_validator_id($page->id);
if (!$profile_post_id && function_exists('bcc_get_nft_id'))       $profile_post_id = bcc_get_nft_id($page->id);
if (!$profile_post_id && function_exists('bcc_get_dao_id'))        $profile_post_id = bcc_get_dao_id($page->id);

if ($profile_post_id && function_exists('get_fields')) {
    $fields = get_fields($profile_post_id);
    if (!empty($fields)) {
        foreach ($fields as $val) {
            if (!empty($val)) { $has_profile_data = true; break; }
        }
    }
}

// 5. GitHub connected
global $wpdb;
$vt = class_exists('\\BCC\\Core\\DB\\DB') ? \BCC\Core\DB\DB::table('trust_user_verifications') : $wpdb->prefix . 'bcc_trust_user_verifications';
$has_github = (bool) $wpdb->get_var($wpdb->prepare(
    "SELECT 1 FROM {$vt} WHERE user_id = %d AND type = 'github' AND status = 'active' LIMIT 1",
    $user_id
));

// 6. Wallet connected
$has_wallet = false;
if (class_exists('\\BCCTrust\\Repositories\\WalletRepository')) {
    try {
        $walletRepo  = new \BCCTrust\Repositories\WalletRepository();
        $connections = $walletRepo->getAllConnections($user_id);
        $has_wallet  = !empty($connections);
    } catch (Exception $e) { /* silent */ }
}

// 7. Social links — check at least one network_ field on the CPT
$has_links = false;
if ($profile_post_id) {
    $link_keys = ['network_twitter', 'network_github', 'network_discord', 'network_telegram', 'network_youtube', 'network_linkedin', 'medium', 'reddit'];
    foreach ($link_keys as $k) {
        if (function_exists('get_field') && get_field($k, $profile_post_id)) { $has_links = true; break; }
    }
}

/* ── Build step list ─────────────────────────────────────── */
$steps = [
    [
        'done'    => true,
        'label'   => 'Project created',
        'hint'    => 'Your project page is live.',
        'action'  => null,
    ],
    [
        'done'    => $has_description,
        'label'   => 'Write a description',
        'hint'    => 'Tell people what your project does.',
        'action'  => $page->get_url() . 'settings/',
        'action_label' => 'Edit settings',
    ],
    [
        'done'    => $has_cover,
        'label'   => 'Add a cover photo',
        'hint'    => 'A cover makes your page stand out.',
        'action'  => $page->get_url(),
        'action_label' => 'Go to page',
    ],
    [
        'done'    => $has_avatar,
        'label'   => 'Upload an avatar',
        'hint'    => 'A logo builds instant recognition.',
        'action'  => $page->get_url(),
        'action_label' => 'Go to page',
    ],
    [
        'done'    => $has_profile_data,
        'label'   => 'Complete your profile',
        'hint'    => 'Fill in chains, services, or portfolio items.',
        'action'  => null,
        'action_label' => 'Scroll down',
    ],
    [
        'done'    => $has_github,
        'label'   => 'Verify GitHub account',
        'hint'    => 'Adds a trust boost and proves code ownership.',
        'action'  => null,
        'action_label' => 'See Trust widget',
    ],
    [
        'done'    => $has_wallet,
        'label'   => 'Connect a wallet',
        'hint'    => 'Prove on-chain identity for higher credibility.',
        'action'  => null,
        'action_label' => 'See Trust widget',
    ],
    [
        'done'    => $has_links,
        'label'   => 'Add social links',
        'hint'    => 'Twitter, Discord, GitHub, Telegram…',
        'action'  => null,
        'action_label' => 'Scroll down',
    ],
];

$total_steps     = count($steps);
$completed_steps = count(array_filter($steps, fn($s) => $s['done']));
$pct             = (int) round(($completed_steps / $total_steps) * 100);

// Don't show if fully complete
if ($completed_steps === $total_steps) {
    return;
}

?>

<div class="bcc-setup-checklist">

    <!-- Header -->
    <div class="bcc-setup-checklist__header">
        <div class="bcc-setup-checklist__title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <polyline points="9 11 12 14 22 4"/>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
            Complete your profile
        </div>
        <div class="bcc-setup-checklist__progress-label">
            <?php echo esc_html($completed_steps); ?> / <?php echo esc_html($total_steps); ?> steps
        </div>
    </div>

    <!-- Progress bar -->
    <div class="bcc-setup-checklist__bar-track">
        <div class="bcc-setup-checklist__bar-fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
    </div>

    <p class="bcc-setup-checklist__sub">
        A complete profile builds trust with visitors and improves your credibility score.
    </p>

    <!-- Steps grid -->
    <ul class="bcc-setup-checklist__list">
        <?php foreach ($steps as $step): ?>
        <li class="bcc-setup-step <?php echo $step['done'] ? 'is-done' : 'is-pending'; ?>">
            <span class="bcc-setup-step__icon" aria-hidden="true">
                <?php if ($step['done']): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
                <?php endif; ?>
            </span>
            <span class="bcc-setup-step__body">
                <span class="bcc-setup-step__label"><?php echo esc_html($step['label']); ?></span>
                <span class="bcc-setup-step__hint"><?php echo esc_html($step['hint']); ?></span>
            </span>
            <?php if (!$step['done'] && !empty($step['action'])): ?>
            <a class="bcc-setup-step__action" href="<?php echo esc_url($step['action']); ?>">
                <?php echo esc_html($step['action_label'] ?? 'Go'); ?>
            </a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>

</div><!-- .bcc-setup-checklist -->
