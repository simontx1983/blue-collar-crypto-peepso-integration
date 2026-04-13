<?php

namespace BCC\PeepSo\Domain;

if (!defined('ABSPATH')) {
    exit;
}

class NftPageType extends AbstractPageType
{
    public static function post_type(): string
    {
        return 'nft';
    }

    /** @return array<int, string> */
    public static function fields(): array
    {
        return [
            'artist_name',
            'artist_short_bio',
            'nft_team_size',
            'nft_total_volume',
            'nft_total_sales',
            'holderscollectors',
            'nft_collections',
            'nft_team_identity',
            'nft_years_active',
            'nft_royalty_rate',
            'nft_contract_audit_status',
            'nft_auditor',
            'nft_audit_url',
            'metadata_storage',
            'token_standard',
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

    /** @return array<int, string> */
    public static function repeater_subfields(string $repeater): array
    {
        if ($repeater === 'nft_collections') {
            return [
                'collection_name',
                'collection_gallery',
                'collection_description',
                'collection_mint_url',
                'collection_marketplace_url',
                'collection_x_account',
                'collection_status',
                'collection_contract_address',
                'collection_total_supply',
                'collection_royalty_rate',
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
