<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Domain\AbstractPageType;

/**
 * AJAX – Inline Field Save Controller (Domain Aware)
 */
class InlineEditController
{
    public static function register(): void
    {
        add_action('wp_ajax_bcc_inline_save', [__CLASS__, 'handle']);
    }

    private static function getDomainClass(int $post_id): string
    {
        return AbstractPageType::get_domain_for_post($post_id) ?? '';
    }

    public static function handle(): void
    {
        check_ajax_referer('bcc_nonce', 'nonce');

        if (!function_exists('update_field')) {
            wp_send_json_error('ACF not active');
        }

        $post_id  = absint($_POST['post_id'] ?? 0);
        $field    = sanitize_text_field($_POST['field'] ?? '');
        $value    = wp_unslash($_POST['value'] ?? '');
        $type     = sanitize_text_field($_POST['type'] ?? 'text');

        switch ($type) {
            case 'wysiwyg':
                $value = wp_kses_post($value);
                break;
            case 'url':
                $value = esc_url_raw($value);
                break;
            case 'text':
            case 'select':
            default:
                $value = sanitize_text_field($value);
                break;
        }

        $repeater = absint($_POST['repeater'] ?? 0);
        $row      = absint($_POST['row'] ?? 0);
        $sub      = sanitize_text_field($_POST['sub'] ?? '');

        if (!$post_id || !$field) {
            wp_send_json_error('Missing required data');
        }

        if (function_exists('bcc_user_can_edit_post')) {
            if (!bcc_user_can_edit_post($post_id)) {
                wp_send_json_error('Permission denied');
            }
        } else {
            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error('Permission denied');
            }
        }

        $domain = self::getDomainClass($post_id);

        if (!$domain || !class_exists($domain)) {
            wp_send_json_error('Unsupported post type');
        }

        if (!call_user_func([$domain, 'is_valid_field'], $field)) {
            wp_send_json_error('Invalid field');
        }

        if ($repeater && $sub && $sub !== 'add_new') {
            if (!call_user_func([$domain, 'is_valid_subfield'], $field, $sub)) {
                wp_send_json_error('Invalid sub field');
            }
        }

        if ($repeater && $sub === 'add_new') {
            $rows = get_field($field, $post_id);
            if (!is_array($rows)) {
                $rows = [];
            }
            $rows[] = [];
            update_field($field, $rows, $post_id);
            wp_send_json_success([
                'message' => 'New item added',
                'rows'    => count($rows)
            ]);
        }

        if ($type === 'gallery') {
            if (is_string($value) && !empty($value)) {
                $value = array_map('intval', explode(',', $value));
            } else {
                $value = [];
            }
        }

        if (!$repeater) {
            update_field($field, $value, $post_id);
            wp_send_json_success([
                'value' => $value
            ]);
        }

        if (!$sub) {
            wp_send_json_error('Missing sub field');
        }

        $rows = get_field($field, $post_id);
        if (!is_array($rows)) {
            $rows = [];
        }
        if (!isset($rows[$row])) {
            $rows[$row] = [];
        }

        $rows[$row][$sub] = $value;
        update_field($field, $rows, $post_id);

        wp_send_json_success([
            'value' => $value
        ]);
    }
}
