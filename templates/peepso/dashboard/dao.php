<?php
if (!defined('ABSPATH')) exit;

/* ======================================================
   DAO PROFILE TEMPLATE
====================================================== */

$page_id = isset( $page->id ) ? (int) $page->id : 0;
$dao_id  = $page_id ? \BCC\PeepSo\Domain\DaoPageType::get_id_from_page( $page_id ) : 0;
$has_dao = $dao_id > 0;

$can_view = ( $has_dao && function_exists( 'bcc_user_can_view_post' ) ) ? bcc_user_can_view_post( $dao_id ) : false;
$can_edit = ( $has_dao && function_exists( 'bcc_user_can_edit_post' ) ) ? bcc_user_can_edit_post( $dao_id ) : false;

$network_options = [];
$network_options_str = '';

if ($can_edit) {
    $networks = get_posts([
        'post_type'      => 'network',
        'posts_per_page' => 100,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    foreach ($networks as $n) {
        $safe_title = str_replace([',', ':'], [' ', '-'], $n->post_title);
        $network_options[] = $n->ID . ':' . $safe_title;
    }
    $network_options_str = implode(',', $network_options);
}
?>

<div class="ps-dao-profile bcc-dao-profile">

<?php if (!is_user_logged_in()): ?>

    <p>Please log in to view this DAO profile.</p>

<?php elseif (!$has_dao): ?>

    <p>No DAO profile found for this page.</p>

<?php elseif (!$can_view): ?>

    <p>This DAO profile is private.</p>

<?php else: ?>

    <!-- ======================================================
        BASIC INFORMATION
    ====================================================== -->
    <section class="bcc-section bcc-section-basic">
        <?php bcc_section_header('Basic Information', 'user'); ?>

        <?php
        bcc_render_rows($dao_id, [
            'dao_name'          => ['label' => 'DAO Name', 'type' => 'text'],
            'dao_description'   => ['label' => 'Description', 'type' => 'textarea'],
            'dao_chain'         => ['label' => 'Chain', 'type' => 'select', 'options' => $network_options_str],
            'dao_token'         => ['label' => 'Governance Token', 'type' => 'text'],
            'dao_token_contract' => ['label' => 'Token Contract', 'type' => 'text'],
            'dao_treasury_size' => ['label' => 'Treasury Size (USD)', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        GOVERNANCE
    ====================================================== -->
    <section class="bcc-section bcc-section-governance">
        <?php bcc_section_header('Governance', 'user'); ?>

        <?php
        bcc_render_rows($dao_id, [
            'dao_governance_type'     => ['label' => 'Governance Type', 'type' => 'select', 'options' => 'token-voting:Token Voting,multisig:Multisig,hybrid:Hybrid,conviction:Conviction,moloch:Moloch'],
            'dao_governance_platform' => ['label' => 'Governance Platform', 'type' => 'select', 'options' => 'snapshot:Snapshot,tally:Tally,daohaus:DAOhaus,aragon:Aragon,custom:Custom,other:Other'],
            'dao_governance_link'     => ['label' => 'Governance Link', 'type' => 'url'],
            'dao_quorum_threshold'    => ['label' => 'Quorum Threshold', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        GOVERNANCE METRICS
    ====================================================== -->
    <section class="bcc-section bcc-section-metrics">
        <?php bcc_section_header('Governance Metrics', 'user'); ?>

        <?php
        bcc_render_rows($dao_id, [
            'dao_member_count'             => ['label' => 'Total Members', 'type' => 'text'],
            'dao_active_voter_count'       => ['label' => 'Active Voters', 'type' => 'text'],
            'dao_voter_participation_rate' => ['label' => 'Voter Participation (%)', 'type' => 'text'],
            'dao_total_proposals'          => ['label' => 'Total Proposals', 'type' => 'text'],
            'dao_proposal_success_rate'    => ['label' => 'Proposal Success Rate (%)', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        TREASURY BREAKDOWN
    ====================================================== -->
    <section class="bcc-section bcc-section-treasury">
        <?php bcc_section_header('Treasury Breakdown', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $dao_id,
            'repeater_key' => 'dao_treasury_tokens',
            'can_edit'     => $can_edit,
            'empty'        => 'No treasury breakdown added yet',
            'fields'       => [
                'token_name'       => ['label' => 'Token', 'type' => 'text'],
                'token_amount'     => ['label' => 'Amount', 'type' => 'text'],
                'token_percentage' => ['label' => 'Percentage (%)', 'type' => 'text'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        MEMBERS
    ====================================================== -->
    <section class="bcc-section bcc-section-members">
        <?php bcc_section_header('Members', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $dao_id,
            'repeater_key' => 'dao_members',
            'can_edit'     => $can_edit,
            'empty'        => 'No members added yet',
            'fields'       => [
                'member_name'   => ['label' => 'Name', 'type' => 'text'],
                'member_role'   => ['label' => 'Role', 'type' => 'text'],
                'member_wallet' => ['label' => 'Wallet', 'type' => 'text'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        WORKING GROUPS
    ====================================================== -->
    <section class="bcc-section bcc-section-working-groups">
        <?php bcc_section_header('Working Groups', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $dao_id,
            'repeater_key' => 'dao_working_groups',
            'can_edit'     => $can_edit,
            'empty'        => 'No working groups added yet',
            'fields'       => [
                'group_name'    => ['label' => 'Name', 'type' => 'text'],
                'group_lead'    => ['label' => 'Lead', 'type' => 'text'],
                'group_mandate' => ['label' => 'Mandate', 'type' => 'textarea'],
                'group_budget'  => ['label' => 'Budget', 'type' => 'text'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        DOCUMENTS
    ====================================================== -->
    <section class="bcc-section bcc-section-documents">
        <?php bcc_section_header('Documents', 'user'); ?>

        <?php
        bcc_render_rows($dao_id, [
            'dao_constitution_url' => ['label' => 'Constitution / Charter', 'type' => 'url'],
            'dao_forum_url'        => ['label' => 'Forum', 'type' => 'url'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        SOCIAL LINKS
    ====================================================== -->
    <section class="bcc-section bcc-section-social">
        <?php bcc_section_header('Links', 'user'); ?>

        <?php
        bcc_render_rows($dao_id, [
            'network_docs'      => ['label' => 'Documentation', 'type' => 'url'],
            'network_github'    => ['label' => 'GitHub', 'type' => 'url'],
            'network_twitter'   => ['label' => 'Twitter / X', 'type' => 'url'],
            'network_discord'   => ['label' => 'Discord', 'type' => 'url'],
            'network_telegram'  => ['label' => 'Telegram', 'type' => 'url'],
            'network_youtube'   => ['label' => 'YouTube', 'type' => 'url'],
            'network_linkedin'  => ['label' => 'LinkedIn', 'type' => 'url'],
        ], $can_edit);
        ?>
    </section>

<?php endif; ?>

</div> <!-- end dao-profile -->
