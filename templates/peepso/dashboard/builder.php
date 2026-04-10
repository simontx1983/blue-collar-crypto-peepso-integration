<?php
if (!defined('ABSPATH')) exit;

/* ======================================================
   BUILDER PROFILE TEMPLATE
====================================================== */

$page_id    = isset( $page->id ) ? (int) $page->id : 0;
$builder_id = $page_id ? \BCC\PeepSo\Domain\BuilderPageType::get_id_from_page( $page_id ) : 0;
$has_builder = $builder_id > 0;

$can_view = ( $has_builder && function_exists( 'bcc_user_can_view_post' ) ) ? bcc_user_can_view_post( $builder_id ) : false;
$can_edit = ( $has_builder && function_exists( 'bcc_user_can_edit_post' ) ) ? bcc_user_can_edit_post( $builder_id ) : false;
?>

<div class="ps-builder-profile bcc-builder-profile">

<?php if (!is_user_logged_in()): ?>

    <p>Please log in to view this builder profile.</p>

<?php elseif (!$has_builder): ?>

    <p>No builder profile found for this page.</p>

<?php elseif (!$can_view): ?>

    <p>This builder profile is private.</p>

<?php else: ?>

    <!-- ======================================================
        BASIC INFORMATION
    ====================================================== -->
    <section class="bcc-section bcc-section-basic">
        <?php bcc_section_header('Basic Information', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'builder_type'             => ['label' => 'Builder Type', 'type' => 'select', 'options' => 'solo-dev:Solo Dev,team:Team,company:Company,dao:DAO'],
            'builder_description'      => ['label' => 'Description', 'type' => 'textarea'],
            'builder_years_experience' => ['label' => 'Years Experience', 'type' => 'text'],
            'team_doxxed'              => ['label' => 'Team Identity', 'type' => 'select', 'options' => 'fully:Fully Doxxed,partially:Partially Doxxed,anonymous:Anonymous'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        CAPABILITIES
    ====================================================== -->
    <section class="bcc-section bcc-section-capabilities">
        <?php bcc_section_header('Capabilities', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'builder_services'   => ['label' => 'Services', 'type' => 'text'],
            'builder_chains'     => ['label' => 'Chains', 'type' => 'text'],
            'builder_tech_stack' => ['label' => 'Tech Stack', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        AVAILABILITY
    ====================================================== -->
    <section class="bcc-section bcc-section-availability">
        <?php bcc_section_header('Availability', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'builder_availability' => ['label' => 'Availability', 'type' => 'select', 'options' => 'available:Available,booked:Booked,limited:Limited'],
            'builder_work_type'    => ['label' => 'Work Type', 'type' => 'select', 'options' => 'full-time:Full-Time,contract:Contract,advisory:Advisory'],
            'builder_rate_range'   => ['label' => 'Rate Range', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        SECURITY
    ====================================================== -->
    <section class="bcc-section bcc-section-security">
        <?php bcc_section_header('Security', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'bug_bounty_active'      => ['label' => 'Bug Bounty Active', 'type' => 'select', 'options' => '1:Yes,0:No'],
            'bug_bounty_url'         => ['label' => 'Bug Bounty URL', 'type' => 'url'],
            'open_source_percentage' => ['label' => 'Open Source (%)', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        TEAM MEMBERS
    ====================================================== -->
    <section class="bcc-section bcc-section-team">
        <?php bcc_section_header('Team Members', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $builder_id,
            'repeater_key' => 'team_members',
            'can_edit'     => $can_edit,
            'empty'        => 'No team members added yet',
            'fields'       => [
                'member_name'          => ['label' => 'Name', 'type' => 'text'],
                'member_role'          => ['label' => 'Role', 'type' => 'text'],
                'member_github'        => ['label' => 'GitHub', 'type' => 'url'],
                'member_linkedin'      => ['label' => 'LinkedIn', 'type' => 'url'],
                'member_twitter'       => ['label' => 'Twitter / X', 'type' => 'url'],
                'member_doxxed_status' => ['label' => 'Identity', 'type' => 'select', 'options' => 'doxxed:Doxxed,pseudonymous:Pseudonymous,anonymous:Anonymous'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        PORTFOLIO
    ====================================================== -->
    <section class="bcc-section bcc-section-portfolio">
        <?php bcc_section_header('Portfolio', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $builder_id,
            'repeater_key' => 'builder_portfolio',
            'can_edit'     => $can_edit,
            'empty'        => 'No portfolio projects added yet',
            'fields'       => [
                'portfolio_name'        => ['label' => 'Project Name', 'type' => 'text'],
                'portfolio_description' => ['label' => 'Description', 'type' => 'textarea'],
                'portfolio_chain'       => ['label' => 'Chain', 'type' => 'text'],
                'portfolio_live_url'    => ['label' => 'Live URL', 'type' => 'url'],
                'portfolio_repo_url'    => ['label' => 'Repo URL', 'type' => 'url'],
                'portfolio_year'        => ['label' => 'Year', 'type' => 'text'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        DEPLOYED CONTRACTS
    ====================================================== -->
    <section class="bcc-section bcc-section-contracts">
        <?php bcc_section_header('Deployed Contracts', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id'      => $builder_id,
            'repeater_key' => 'builder_contracts',
            'can_edit'     => $can_edit,
            'empty'        => 'No contracts added yet',
            'fields'       => [
                'contract_name'         => ['label' => 'Contract Name', 'type' => 'text'],
                'contract_chain'        => ['label' => 'Chain', 'type' => 'text'],
                'contract_address'      => ['label' => 'Address', 'type' => 'text'],
                'contract_explorer_url' => ['label' => 'Explorer URL', 'type' => 'url'],
                'contract_audit_status' => ['label' => 'Audit Status', 'type' => 'select', 'options' => 'audited:Audited,not-audited:Not Audited,in-progress:In Progress'],
                'contract_auditor'      => ['label' => 'Auditor', 'type' => 'text'],
                'contract_audit_url'    => ['label' => 'Audit URL', 'type' => 'url'],
            ],
        ]);
        ?>
    </section>

    <!-- ======================================================
        PRIMARY NETWORK
    ====================================================== -->
    <?php
    $network_ids = function_exists('get_field') ? get_field('network', $builder_id) : null;
    if ($network_ids && !empty($network_ids)):
    ?>
    <section class="bcc-section bcc-section-network">
        <?php bcc_section_header('Primary Network', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'network' => ['label' => 'Network', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>
    <?php endif; ?>

    <!-- ======================================================
        ORGANIZATION ASSOCIATION
    ====================================================== -->
    <?php
    $org_ids = function_exists('get_field') ? get_field('associated_organization', $builder_id) : null;
    if ($org_ids && !empty($org_ids)):
    ?>
    <section class="bcc-section bcc-section-organization">
        <?php bcc_section_header('Organization Association', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'associated_organization' => ['label' => 'Organization', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>
    <?php endif; ?>

    <!-- ======================================================
        SOCIAL LINKS
    ====================================================== -->
    <section class="bcc-section bcc-section-social">
        <?php bcc_section_header('Links', 'user'); ?>

        <?php
        bcc_render_rows($builder_id, [
            'network_docs'     => ['label' => 'Documentation', 'type' => 'url'],
            'network_github'   => ['label' => 'GitHub', 'type' => 'url'],
            'network_twitter'  => ['label' => 'Twitter / X', 'type' => 'url'],
            'network_discord'  => ['label' => 'Discord', 'type' => 'url'],
            'network_telegram' => ['label' => 'Telegram', 'type' => 'url'],
            'network_youtube'  => ['label' => 'YouTube', 'type' => 'url'],
            'network_linkedin' => ['label' => 'LinkedIn', 'type' => 'url'],
            'medium'           => ['label' => 'Medium', 'type' => 'url'],
            'reddit'           => ['label' => 'Reddit', 'type' => 'url'],
        ], $can_edit);
        ?>
    </section>

<?php endif; ?>

</div> <!-- end builder-profile -->
