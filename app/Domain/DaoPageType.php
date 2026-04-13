<?php

namespace BCC\PeepSo\Domain;

if (!defined('ABSPATH')) {
    exit;
}

class DaoPageType extends AbstractPageType
{
    public static function post_type(): string
    {
        return 'dao';
    }

    /** @return array<int, string> */
    public static function fields(): array
    {
        return [
            'dao_name',
            'dao_description',
            'dao_governance_link',
            'dao_token',
            'dao_treasury_size',
            'dao_chain',
            'dao_governance_type',
            'dao_governance_platform',
            'dao_token_contract',
            'dao_member_count',
            'dao_active_voter_count',
            'dao_voter_participation_rate',
            'dao_total_proposals',
            'dao_proposal_success_rate',
            'dao_quorum_threshold',
            'dao_constitution_url',
            'dao_forum_url',
            'dao_members',
            'dao_treasury_tokens',
            'dao_working_groups',
            'network_docs',
            'network_github',
            'network_twitter',
            'network_discord',
            'network_telegram',
            'network_youtube',
            'network_linkedin',
        ];
    }

    /** @return array<int, string> */
    public static function repeater_subfields(string $repeater): array
    {
        if ($repeater === 'dao_members') {
            return [
                'member_name',
                'member_role',
                'member_wallet',
            ];
        }

        if ($repeater === 'dao_treasury_tokens') {
            return [
                'token_name',
                'token_amount',
                'token_percentage',
            ];
        }

        if ($repeater === 'dao_working_groups') {
            return [
                'group_name',
                'group_lead',
                'group_mandate',
                'group_budget',
            ];
        }

        return [];
    }
}
