<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX – Field Visibility Controller
 */
class VisibilityController
{
    public static function register(): void
    {
        add_action('wp_ajax_bcc_save_field_visibility', [__CLASS__, 'handle']);
    }

    public static function handle(): void
    {
        if (!check_ajax_referer('bcc_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => 'Security check failed'
            ]);
        }

        $post_id    = absint($_POST['post_id'] ?? 0);
        $field      = sanitize_text_field($_POST['field'] ?? '');
        $visibility = sanitize_text_field($_POST['visibility'] ?? '');

        if (!$post_id || !$field || !$visibility) {
            wp_send_json_error([
                'message' => 'Missing required data'
            ]);
        }

        $allowed = ['public', 'members', 'private'];

        if (!in_array($visibility, $allowed, true)) {
            wp_send_json_error([
                'message' => 'Invalid visibility value'
            ]);
        }

        if (function_exists('bcc_user_can_edit_post')) {
            if (!bcc_user_can_edit_post($post_id)) {
                wp_send_json_error([
                    'message' => 'Permission denied'
                ]);
            }
        } else {
            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error([
                    'message' => 'Permission denied'
                ]);
            }
        }

        // Validate field against domain allowlist (mirrors InlineEditController pattern).
        if (class_exists('\\BCC\\PeepSo\\Domain\\AbstractPageType')) {
            $domain = \BCC\PeepSo\Domain\AbstractPageType::get_domain_for_post($post_id);
            if ($domain && !call_user_func([$domain, 'is_valid_field'], $field)) {
                wp_send_json_error(['message' => 'Invalid field']);
            }
        }

        if (!function_exists('bcc_set_field_visibility')) {
            wp_send_json_error([
                'message' => 'Visibility system not available'
            ]);
        }

        $saved = bcc_set_field_visibility($post_id, $field, $visibility);

        if (!$saved) {
            wp_send_json_error([
                'message' => 'Failed to save visibility'
            ]);
        }

        wp_send_json_success([
            'message'    => 'Visibility updated',
            'post_id'    => $post_id,
            'field'      => $field,
            'visibility' => $visibility
        ]);
    }
}
