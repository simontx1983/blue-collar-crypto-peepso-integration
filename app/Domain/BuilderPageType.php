<?php

namespace BCC\PeepSo\Domain;

if (!defined('ABSPATH')) {
    exit;
}

class BuilderPageType extends AbstractPageType
{
    public static function post_type(): string
    {
        return 'builder';
    }

    /** @return array<int, string> */
    public static function fields(): array
    {
        return [
            'builder_services',
            'builder_chains',
            'builder_years_experience',
            'builder_availability',
            'builder_work_type',
            'builder_rate_range',
            'builder_contracts',
            'builder_portfolio',
            'builder_description',
            'builder_type',
            'builder_tech_stack',
            'team_doxxed',
            'team_members',
            'bug_bounty_active',
            'bug_bounty_url',
            'open_source_percentage',
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
        if ($repeater === 'builder_contracts') {
            return [
                'contract_name',
                'contract_chain',
                'contract_address',
                'contract_explorer_url',
                'contract_audit_status',
                'contract_auditor',
                'contract_audit_url',
            ];
        }

        if ($repeater === 'builder_portfolio') {
            return [
                'portfolio_name',
                'portfolio_description',
                'portfolio_chain',
                'portfolio_live_url',
                'portfolio_repo_url',
                'portfolio_year',
            ];
        }

        if ($repeater === 'team_members') {
            return [
                'member_name',
                'member_role',
                'member_github',
                'member_linkedin',
                'member_twitter',
                'member_doxxed_status',
            ];
        }

        return [];
    }
}
