<?php
if (!defined('ABSPATH')) exit;

/* ======================================================
   NFT CREATOR PROFILE TEMPLATE
====================================================== */

$page_id = isset( $page->id ) ? (int) $page->id : 0;
$nft_id  = $page_id ? \BCC\PeepSo\Domain\NftPageType::get_id_from_page( $page_id ) : 0;
$has_nft = $nft_id > 0;

$can_view = ( $has_nft && function_exists( 'bcc_user_can_view_post' ) ) ? bcc_user_can_view_post( $nft_id ) : false;
$can_edit = ( $has_nft && function_exists( 'bcc_user_can_edit_post' ) ) ? bcc_user_can_edit_post( $nft_id ) : false;

$network_options_str = $can_edit ? bcc_get_network_options_string() : '';
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
        NFT COLLECTIONS (Unified: On-Chain + Self-Reported)
    ====================================================== -->
    <?php
    $viewer_id      = get_current_user_id();
    $onchain_items  = [];
    $onchain_fetched_at = '';
    $owner_id       = 0;

    // ── Load on-chain collection data via ServiceLocator ──
    if ($has_nft && class_exists('\\BCC\\Core\\ServiceLocator')) {
        $onchain = \BCC\Core\ServiceLocator::resolveOnchainDataRead();

        // Page owners see ALL collections (including hidden) so they can
        // toggle visibility back on. Public viewers see only visible ones.
        $all_onchain = $can_edit
            ? $onchain->getCollectionsForProject($nft_id, 1, 999, 'total_volume', true)
            : $onchain->getAllCollectionsForProject($nft_id);
        $onchain_items = $all_onchain['items'] ?? [];

        if (!empty($onchain_items)) {
            $onchain_fetched_at = $onchain_items[0]->fetched_at ?? '';
        }

        if (class_exists('\\BCC\\Core\\PeepSo\\PeepSo')) {
            $owner_id = (int) \BCC\Core\PeepSo\PeepSo::get_page_owner($page_id);
        }

        // Enrich with badge flags
        $onchain_items = $onchain->enrichCollectionsWithBadges(
            $onchain_items,
            $owner_id,
            $viewer_id
        );

        foreach ($onchain_items as $item) {
            $item->can_toggle = $can_edit;
        }
    }

    // ── Load manual ACF collections ──
    $manual_rows = [];
    if ($has_nft && function_exists('get_field')) {
        $manual_rows = get_field('nft_collections', $nft_id) ?: [];
    }

    // ── Merge + Deduplicate ──
    $unified_collections = [];
    if (class_exists('\\BCC\\Core\\ServiceLocator') && isset($onchain)) {
        $unified_collections = $onchain->mergeCollectionsWithManual($onchain_items, $manual_rows);
    } elseif (!empty($manual_rows)) {
        // No on-chain plugin — show all manual rows as self-reported
        foreach ($manual_rows as $row) {
            $unified_collections[] = (object) [
                'id'                 => null,
                'contract_address'   => $row['collection_contract_address'] ?? '',
                'collection_name'    => $row['collection_name'] ?? '',
                'chain_name'         => $row['collection_chain'] ?? '',
                'chain_slug'         => '',
                'explorer_url'       => '',
                'native_token'       => '',
                'token_standard'     => null,
                'total_supply'       => $row['collection_total_supply'] ?? null,
                'floor_price'        => $row['collection_floor_price'] ?? null,
                'floor_currency'     => null,
                'total_volume'       => null,
                'unique_holders'     => $row['collection_unique_holders'] ?? null,
                'show_on_profile'    => 1,
                'fetched_at'         => null,
                'data_source'        => 'self-reported',
                'is_creator'         => true,
                'viewer_holds'       => false,
                'can_toggle'         => false,
            ];
        }
    }

    $has_collections = !empty($unified_collections);

    // Aggregate stats (on-chain only — self-reported data is unverified).
    // Logic lives in bcc_aggregate_collection_stats(); template only renders.
    $coll_agg  = function_exists('bcc_aggregate_collection_stats')
        ? bcc_aggregate_collection_stats($unified_collections)
        : ['count' => 0, 'volume' => 0.0, 'holders' => 0, 'avg_floor' => 0.0, 'native_token' => 'ETH'];
    $agg_count = $coll_agg['count'];
    $native    = $coll_agg['native_token'];
    ?>

    <?php if ($agg_count > 0 && function_exists('bcc_render_onchain_stats')) : ?>
    <section class="bcc-section bcc-section-onchain-stats">
        <?php
        bcc_render_onchain_stats([
            'Verified Collections' => $agg_count,
            'Total Volume'         => bcc_format_number($coll_agg['volume']) . ' ' . $native,
            'Avg Floor'            => $coll_agg['avg_floor'] > 0 ? bcc_format_number($coll_agg['avg_floor']) . ' ' . $native : '—',
            'Total Holders'        => number_format($coll_agg['holders']),
        ], $onchain_fetched_at, [
            'title' => 'On-Chain Collection Metrics',
        ]);
        ?>
    </section>
    <?php endif; ?>

    <?php if ($has_collections && function_exists('bcc_render_onchain_cards')) : ?>
    <section class="bcc-section bcc-section-collections-unified">
        <?php
        $cpage = max(1, absint($_GET['cpage'] ?? 1));
        $per_page = 8;
        $total = count($unified_collections);
        $paged_items = array_slice($unified_collections, ($cpage - 1) * $per_page, $per_page);

        bcc_render_onchain_cards($paged_items, $total, [
            'title'         => 'NFT Collections',
            'type'          => 'collection',
            'per_page'      => $per_page,
            'current_page'  => $cpage,
            'fetched_at'    => $onchain_fetched_at,
            'card_renderer' => 'bcc_render_collection_card',
        ]);
        ?>
    </section>
    <?php elseif (!$has_collections) : ?>
    <section class="bcc-section bcc-section-collections-unified">
        <?php bcc_section_header('NFT Collections', 'user'); ?>
        <p class="bcc-onchain-empty">No collections found. Connect a wallet to auto-fill on-chain data, or add collections manually.</p>
    </section>
    <?php endif; ?>

    <?php if ($can_edit) : ?>
    <!-- ======================================================
        ADD / EDIT COLLECTIONS (Owner Only)
    ====================================================== -->
    <section class="bcc-section bcc-section-collections-edit">
        <?php bcc_section_header('Manage Collections', 'user'); ?>

        <?php
        bcc_render_repeater_slider([
            'post_id' => $nft_id,
            'repeater_key' => 'nft_collections',
            'can_edit' => true,
            'empty' => 'Add your first collection below',
            'fields' => [
                'collection_name' => ['label' => 'Collection Name', 'type' => 'text'],
                'collection_gallery' => ['label' => 'Gallery', 'type' => 'gallery'],
                'collection_description' => ['label' => 'Description', 'type' => 'textarea'],
                'collection_chain' => ['label' => 'Chain', 'type' => 'select', 'options' => $network_options_str],
                'collection_category' => ['label' => 'Category', 'type' => 'select', 'options' => 'pfp:PFP,art:Art,gaming:Gaming,music:Music,utility:Utility,generative:Generative'],
                'collection_status' => ['label' => 'Status', 'type' => 'select', 'options' => 'minting:Minting,sold-out:Sold Out,closed:Closed'],
                'collection_contract_address' => ['label' => 'Contract Address', 'type' => 'text'],
                'collection_total_supply' => ['label' => 'Total Supply', 'type' => 'text'],
                'collection_floor_price' => ['label' => 'Floor Price', 'type' => 'text'],
                'collection_unique_holders' => ['label' => 'Unique Holders', 'type' => 'text'],
                'collection_listed_percentage' => ['label' => 'Listed (%)', 'type' => 'text'],
                'collection_royalty_rate' => ['label' => 'Royalty Rate (%)', 'type' => 'text'],
                'collection_mint_url' => ['label' => 'Mint URL', 'type' => 'url'],
                'collection_marketplace_url' => ['label' => 'Marketplace URL', 'type' => 'url'],
                'collection_x_account' => ['label' => 'X Account', 'type' => 'url'],
            ]
        ]);
        ?>
    </section>
    <?php endif; ?>

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
