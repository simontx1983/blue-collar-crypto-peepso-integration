<?php
if (!defined('ABSPATH')) exit;

/* ======================================================
   NFT CREATOR PROFILE TEMPLATE
====================================================== */

$page_id = isset( $page->id ) ? (int) $page->id : 0;
$nft_id  = ( $page_id && function_exists( 'bcc_get_nft_id' ) ) ? bcc_get_nft_id( $page_id ) : 0;
$has_nft = $nft_id > 0;

$can_view = ( $has_nft && function_exists( 'bcc_user_can_view_post' ) ) ? bcc_user_can_view_post( $nft_id ) : false;
$can_edit = ( $has_nft && function_exists( 'bcc_user_can_edit_post' ) ) ? bcc_user_can_edit_post( $nft_id ) : false;

$networks = get_posts([
    'post_type' => 'network',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
]);

$network_options = [];
foreach ($networks as $n) {
    $network_options[] = $n->ID . ':' . $n->post_title;
}
$network_options_str = implode(',', $network_options);
?>

<div class="ps-nft-profile bcc-nft-profile">

<?php if (!is_user_logged_in()): ?>

    <p>Please log in to view this NFT creator profile.</p>

<?php elseif (!$has_nft): ?>

    <p>No NFT creator profile found for this page.</p>

<?php elseif (!$can_view): ?>

    <p>This NFT creator profile is private.</p>

<?php else: ?>

    <!-- ======================================================
        BASIC INFORMATION
    ====================================================== -->
    <section class="bcc-section bcc-section-basic">
        <?php bcc_section_header('Basic Information', 'user'); ?>

        <?php
        bcc_render_rows($nft_id, [
            'artist_name'       => ['label' => 'Artist Name', 'type' => 'text'],
            'artist_short_bio'  => ['label' => 'Bio', 'type' => 'textarea'],
            'nft_team_size'     => ['label' => 'Team Size', 'type' => 'text'],
            'nft_team_identity' => ['label' => 'Team Identity', 'type' => 'select', 'options' => 'doxxed:Doxxed,pseudonymous:Pseudonymous,anonymous:Anonymous'],
            'nft_years_active'  => ['label' => 'Years Active', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        AGGREGATE STATS
    ====================================================== -->
    <section class="bcc-section bcc-section-stats">
        <?php bcc_section_header('Stats', 'user'); ?>

        <?php
        bcc_render_rows($nft_id, [
            'nft_total_volume'   => ['label' => 'Total Volume', 'type' => 'text'],
            'nft_total_sales'    => ['label' => 'Total Sales', 'type' => 'text'],
            'holderscollectors'  => ['label' => 'Holders / Collectors', 'type' => 'text'],
            'nft_royalty_rate'   => ['label' => 'Royalty Rate (%)', 'type' => 'text'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        TECHNICAL
    ====================================================== -->
    <section class="bcc-section bcc-section-technical">
        <?php bcc_section_header('Technical', 'user'); ?>

        <?php
        bcc_render_rows($nft_id, [
            'metadata_storage' => ['label' => 'Metadata Storage', 'type' => 'select', 'options' => 'ipfs:IPFS,arweave:Arweave,onchain:On-Chain,centralized:Centralized'],
            'token_standard'   => ['label' => 'Token Standard', 'type' => 'select', 'options' => 'erc721:ERC-721,erc1155:ERC-1155,metaplex:Metaplex,other:Other'],
            'nft_contract_audit_status' => ['label' => 'Audit Status', 'type' => 'select', 'options' => 'audited:Audited,not-audited:Not Audited'],
            'nft_auditor'      => ['label' => 'Auditor', 'type' => 'text'],
            'nft_audit_url'    => ['label' => 'Audit Report', 'type' => 'url'],
        ], $can_edit);
        ?>
    </section>

    <!-- ======================================================
        NFT COLLECTIONS
    ====================================================== -->
    <section class="bcc-section bcc-section-collections">
        <?php bcc_section_header('NFT Collections', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id' => $nft_id,
            'repeater_key' => 'nft_collections',
            'can_edit' => $can_edit,
            'empty' => 'No collections created yet',
            'fields' => [
                'collection_name' => [
                    'label' => 'Collection Name',
                    'type' => 'text'
                ],
                'collection_gallery' => [
                    'label' => 'Gallery',
                    'type' => 'gallery'
                ],
                'collection_description' => [
                    'label' => 'Description',
                    'type' => 'textarea'
                ],
                'collection_chain' => [
                    'label' => 'Chain',
                    'type' => 'select',
                    'options' => $network_options_str
                ],
                'collection_category' => [
                    'label' => 'Category',
                    'type' => 'select',
                    'options' => 'pfp:PFP,art:Art,gaming:Gaming,music:Music,utility:Utility,generative:Generative'
                ],
                'collection_status' => [
                    'label' => 'Status',
                    'type' => 'select',
                    'options' => 'minting:Minting,sold-out:Sold Out,closed:Closed'
                ],
                'collection_contract_address' => [
                    'label' => 'Contract Address',
                    'type' => 'text'
                ],
                'collection_total_supply' => [
                    'label' => 'Total Supply',
                    'type' => 'text'
                ],
                'collection_floor_price' => [
                    'label' => 'Floor Price',
                    'type' => 'text'
                ],
                'collection_unique_holders' => [
                    'label' => 'Unique Holders',
                    'type' => 'text'
                ],
                'collection_listed_percentage' => [
                    'label' => 'Listed (%)',
                    'type' => 'text'
                ],
                'collection_royalty_rate' => [
                    'label' => 'Royalty Rate (%)',
                    'type' => 'text'
                ],
                'collection_mint_url' => [
                    'label' => 'Mint URL',
                    'type' => 'url'
                ],
                'collection_marketplace_url' => [
                    'label' => 'Marketplace URL',
                    'type' => 'url'
                ],
                'collection_x_account' => [
                    'label' => 'X Account',
                    'type' => 'url'
                ]
            ]
        ]);
        ?>
    </section>

    <!-- ======================================================
        SOCIAL LINKS
    ====================================================== -->
    <section class="bcc-section bcc-section-social">
        <?php bcc_section_header('Links', 'user'); ?>

        <?php
        bcc_render_rows($nft_id, [
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

</div> <!-- end nft-profile -->
