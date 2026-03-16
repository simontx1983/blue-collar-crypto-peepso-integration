<?php
if (!defined('ABSPATH')) exit;

class BCC_Domain_DAO extends BCC_Domain_Abstract {

    public static function post_type(): string {
        return 'dao';
    }

    public static function fields(): array {
        return [
            'dao_name',
            'dao_description',
            'dao_governance_link',
            'dao_token',
            'dao_treasury_size',
            // New governance fields
            'dao_chain',
            'dao_governance_type',
            'dao_governance_platform',
            'dao_token_contract',
            // New metrics fields
            'dao_member_count',
            'dao_active_voter_count',
            'dao_voter_participation_rate',
            'dao_total_proposals',
            'dao_proposal_success_rate',
            'dao_quorum_threshold',
            // New document fields
            'dao_constitution_url',
            'dao_forum_url',
            // Repeaters
            'dao_members',
            'dao_treasury_tokens',
            'dao_working_groups',
            // Social links
            'network_docs',
            'network_github',
            'network_twitter',
            'network_discord',
            'network_telegram',
            'network_youtube',
            'network_linkedin',
        ];
    }

    public static function repeater_subfields(string $repeater): array {

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

function bcc_get_dao_id($page_id) {
    return BCC_Domain_DAO::get_or_create_from_page((int) $page_id);
}
