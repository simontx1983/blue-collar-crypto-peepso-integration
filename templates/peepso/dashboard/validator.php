<?php
if (!defined('ABSPATH')) exit;

/* ======================================================
   VALIDATOR PROFILE TEMPLATE — 3-ZONE LAYOUT

   Zone 1: Manual fields (ACF, editable)
   Zone 2: On-chain stats (read-only summary)
   Zone 3: On-chain chain cards (paginated, read-only)
   Zone 4: Manual repeaters (ACF, editable)
====================================================== */

$page_id       = isset( $page->id ) ? (int) $page->id : 0;
$validator_id  = ( $page_id && function_exists( 'bcc_get_validator_id' ) ) ? bcc_get_validator_id( $page_id ) : 0;
$has_validator = $validator_id > 0;

$can_view = ( $has_validator && function_exists( 'bcc_user_can_view_post' ) ) ? bcc_user_can_view_post( $validator_id ) : false;
$can_edit = ( $has_validator && function_exists( 'bcc_user_can_edit_post' ) ) ? bcc_user_can_edit_post( $validator_id ) : false;

// On-chain data: get wallet links + validator rows for this page
$has_onchain = false;
$onchain_validators = ['items' => [], 'total' => 0];
$onchain_stats = [];
$onchain_fetched_at = '';

if ($has_validator && function_exists('bcc_onchain_get_validators_for_project')) {
    $current_page = max( 1, absint( $_GET['vpage'] ?? 1 ) );
    $onchain_validators = bcc_onchain_get_validators_for_project($validator_id, $current_page, 8, 'total_stake');
    $has_onchain = $onchain_validators['total'] > 0;

    if ($has_onchain) {
        // Build aggregate stats from all validator rows
        $first = $onchain_validators['items'][0] ?? null;
        $onchain_fetched_at = $first->fetched_at ?? '';

        // Aggregate across all chains
        $all_validators = bcc_onchain_get_validators_for_project($validator_id, 1, 999);
        $total_stake = 0;
        $total_delegators = 0;
        $active_count = 0;
        $chains_count = count($all_validators['items']);

        foreach ($all_validators['items'] as $v) {
            $total_stake     += (float) ($v->total_stake ?? 0);
            $total_delegators += (int) ($v->delegator_count ?? 0);
            if ($v->status === 'active') $active_count++;
        }

        $onchain_stats = [
            'Active Chains'     => $active_count . ' / ' . $chains_count,
            'Total Stake'       => function_exists('bcc_format_number') ? bcc_format_number($total_stake) : number_format($total_stake),
            'Total Delegators'  => number_format($total_delegators),
        ];

        // Add top-chain-specific stats
        if ($first) {
            if ($first->voting_power_rank) {
                $onchain_stats['Top Rank'] = '#' . $first->voting_power_rank . ' on ' . $first->chain_name;
            }
            if ($first->uptime_30d !== null) {
                $onchain_stats['Best Uptime'] = number_format($first->uptime_30d, 1) . '%';
            }
            if ($first->governance_participation !== null) {
                $onchain_stats['Gov. Participation'] = number_format($first->governance_participation, 1) . '%';
            }
        }
    }
}
?>

<div class="ps-validator-profile bcc-validator-profile">

<?php if (!is_user_logged_in()): ?>

    <p>Please log in to view this validator profile.</p>

<?php elseif (!$has_validator): ?>

    <p>No validator profile found for this page.</p>

<?php elseif (!$can_view): ?>

    <p>This validator profile is private.</p>

<?php else: ?>

    <!-- ======================================================
        ZONE 1: MANUAL FIELDS (ACF — Editable)
    ====================================================== -->

    <section class="bcc-section bcc-section-basic">
        <?php bcc_section_header('Basic Information', 'user'); ?>

        <?php
        bcc_render_rows($validator_id, [
            'validator_moniker'     => ['label' => 'Validator Moniker'],
            'node_name'             => ['label' => 'Node Name'],
            'validator_description' => ['label' => 'Description', 'type' => 'textarea'],
            'validator_status'      => ['label' => 'Status', 'type' => 'select', 'options' => 'active:Active,inactive:Inactive,jailed:Jailed'],
            'years_operating'       => ['label' => 'Years Operating', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <?php bcc_render_divider(); ?>

    <!-- ======================================================
        ZONE 2: ON-CHAIN STATS (Read-Only Summary)
    ====================================================== -->

    <?php if ($has_onchain && function_exists('bcc_render_onchain_stats')): ?>

        <?php
        bcc_render_onchain_stats($onchain_stats, $onchain_fetched_at, [
            'title'        => 'On-Chain Metrics',
            'show_refresh' => $can_edit,
            'post_id'      => $validator_id,
        ]);
        ?>

        <?php bcc_render_divider(); ?>

    <?php elseif ($can_edit && function_exists('bcc_render_wallet_connect_cta')): ?>

        <?php bcc_render_wallet_connect_cta('validator', $validator_id); ?>
        <?php bcc_render_divider(); ?>

    <?php endif; ?>

    <!-- ======================================================
        ZONE 3: ON-CHAIN CHAIN CARDS (Paginated, Read-Only)
    ====================================================== -->

    <?php if ($has_onchain && function_exists('bcc_render_onchain_cards')): ?>

        <?php
        bcc_render_onchain_cards($onchain_validators['items'], $onchain_validators['total'], [
            'title'         => 'Chains Validated (On-Chain)',
            'type'          => 'validator',
            'per_page'      => 8,
            'current_page'  => $current_page,
            'fetched_at'    => $onchain_fetched_at,
            'card_renderer' => 'bcc_render_validator_chain_card',
        ]);
        ?>

        <?php bcc_render_divider(); ?>

    <?php endif; ?>

    <!-- ======================================================
        ZONE 4: INFRASTRUCTURE & LINKS
        Key Metrics, Chains, and Slashing History are now
        driven entirely by on-chain wallet data (Zones 2 & 3).
    ====================================================== -->

    <!-- Infrastructure (shown only if data exists or user can edit) -->
    <?php
    $infra_fields = [
        'infrastructure_type' => [
            'label' => 'Infrastructure Type',
            'type'  => 'select',
            'options' => 'bare-metal:Bare Metal,cloud:Cloud,hybrid:Hybrid'
        ],
        'data_center_location' => [
            'label' => 'Data Center Location',
            'type'  => 'text'
        ],
        'hardware__infrastructure' => [
            'label' => 'Hardware / Infrastructure',
            'type' => 'wysiwyg'
        ],
        'monitoring_tools' => [
            'label' => 'Monitoring Tools'
        ],
        'redundancy_setup' => [
            'label' => 'Redundancy Setup'
        ],
        'security_practices' => [
            'label' => 'Security Practices',
            'type'  => 'textarea'
        ],
    ];

    // Check if any infrastructure field has data
    $has_infra_data = false;
    if (!$can_edit) {
        foreach (array_keys($infra_fields) as $field_key) {
            $val = function_exists('get_field') ? get_field($field_key, $validator_id) : null;
            if (!empty($val)) {
                $has_infra_data = true;
                break;
            }
        }
    }

    if ($can_edit || $has_infra_data): ?>
    <section class="bcc-section bcc-section-infrastructure">
        <?php bcc_section_header('Infrastructure', 'user'); ?>
        <?php bcc_render_rows($validator_id, $infra_fields, $can_edit); ?>
    </section>

    <?php bcc_render_divider(); ?>
    <?php endif; ?>

    <!-- Links (manual — always shown) -->
    <section class="bcc-section bcc-section-social">
        <?php bcc_section_header('Links', 'user'); ?>

        <?php
        bcc_render_rows($validator_id, [
            'validator_delegation_link' => ['label' => 'Delegation Link', 'type' => 'url'],
            'network_docs'     => ['label' => 'Network Docs', 'type' => 'url'],
            'network_github'   => ['label' => 'GitHub', 'type' => 'url'],
            'network_twitter'  => ['label' => 'Twitter / X', 'type' => 'url'],
            'network_discord'  => ['label' => 'Discord', 'type' => 'url'],
            'network_telegram' => ['label' => 'Telegram', 'type' => 'url'],
        ], $can_edit);
        ?>
    </section>

    <!-- Connected Wallets (only shown to page owner in edit mode) -->
    <?php if ($can_edit): ?>
        <?php bcc_render_divider(); ?>
        <section class="bcc-section bcc-section-wallets">
            <?php bcc_section_header('Connected Wallets', 'user'); ?>
            <div class="bcc-wallet-list-panel" data-post-id="<?php echo (int) $validator_id; ?>"></div>
        </section>
    <?php endif; ?>

<?php endif; ?>

</div> <!-- end validator-profile -->
