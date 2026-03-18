<?php

namespace BCC\PeepSo\Domain;

if (!defined('ABSPATH')) {
    exit;
}

class ValidatorPageType extends AbstractPageType
{
    public static function post_type(): string
    {
        return 'validators';
    }

    public static function fields(): array
    {
        return [
            'validator_moniker',
            'node_name',
            'validator_description',
            'validator_status',
            'chains_you_validate_for',
            'hardware__infrastructure',
            'monitoring_tools',
            'redundancy_setup',
            'validator_delegation_link',
            'average_uptime',
            'validator_commission_rate',
            'validator_self_stake',
            'validator_chains',
            'delegator_count',
            'total_stake',
            'governance_participation_rate',
            'data_center_location',
            'infrastructure_type',
            'years_operating',
            'security_practices',
            'slashing_history',
            'network_docs',
            'network_github',
            'network_twitter',
            'network_discord',
            'network_telegram',
        ];
    }

    public static function repeater_subfields(string $repeater): array
    {
        if ($repeater === 'chains_you_validate_for') {
            return [
                'networks',
                'validators_cosmos',
                'average_uptime',
                'validator_commission_rate',
                'validator_self_stake',
                'validator_address',
                'commission',
            ];
        }

        if ($repeater === 'validator_chains') {
            return [
                'chain_name',
                'rpc_url',
                'rest_url',
                'snapshot_url',
                'addr_prefix',
                'staking_token',
                'commission',
                'validator_address',
            ];
        }

        if ($repeater === 'slashing_history') {
            return [
                'slashing_date',
                'slashing_chain',
                'slashing_details',
                'slashing_severity',
            ];
        }

        return [];
    }
}
