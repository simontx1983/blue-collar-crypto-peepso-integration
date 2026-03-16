<?php
if (!defined('ABSPATH')) exit;

class BCC_Domain_NFT extends BCC_Domain_Abstract {

    public static function post_type(): string {
        return 'nft';
    }

    public static function fields(): array {
        return [
            'artist_name',
            'artist_short_bio',
            'nft_team_size',
            'nft_total_volume',
            'nft_total_sales',
            'holderscollectors',
            'nft_collections',
            // Trust signal fields
            'nft_team_identity',
            'nft_years_active',
            'nft_royalty_rate',
            'nft_contract_audit_status',
            'nft_auditor',
            'nft_audit_url',
            // New fields
            'metadata_storage',
            'token_standard',
            // Social links
            'network_docs',
            'network_github',
            'network_twitter',
            'network_discord',
            'network_telegram',
            'network_youtube',
            'network_linkedin',
            'medium',
            'reddit',
        ];
    }

    public static function repeater_subfields(string $repeater): array {

        if ($repeater === 'nft_collections') {
            return [
                'collection_name',
                'collection_gallery',
                'collection_description',
                'post_type', // Legacy chain field — superseded by collection_chain, kept for existing data
                'collection_mint_url',
                'collection_marketplace_url',
                'collection_x_account',
                // Trust fields added 2026-03
                'collection_status',
                'collection_contract_address',
                'collection_total_supply',
                'collection_royalty_rate',
                // New subfields
                'collection_chain',
                'collection_floor_price',
                'collection_unique_holders',
                'collection_listed_percentage',
                'collection_category',
            ];
        }

        return [];
    }
}

/* Backwards compatibility */

function bcc_get_nft_id($page_id) {
    return BCC_Domain_NFT::get_or_create_from_page((int) $page_id);
}
