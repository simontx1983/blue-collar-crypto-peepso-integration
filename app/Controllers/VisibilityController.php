<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Security\AjaxSecurity;
use BCC\Core\Security\Throttle;
use BCC\PeepSo\Domain\AbstractPageType;

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

        if (!Throttle::allow('bcc_peepso.visibility', 20, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
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

        AjaxSecurity::require_edit_permission($post_id);

        // Validate field against domain allowlist (mirrors InlineEditController pattern).
        // Contract check delegates to AbstractPageType::assertContract() — see
        // that method for the fail-soft + fail-loud rationale.
        $domain = AbstractPageType::get_domain_for_post($post_id);
        if ($domain === null
            || !AbstractPageType::assertContract($domain, $post_id, $field, __METHOD__)
            || !$domain::is_valid_field($field)
        ) {
            wp_send_json_error(['message' => 'Invalid field']);
        }

        if (!function_exists('bcc_set_field_visibility')) {
            wp_send_json_error([
                'message' => 'Visibility system not available'
            ]);
        }

        // Optimistic locking: reject if another save bumped the version
        // since this client last read the post.
        $clientVersion = isset($_POST['vis_version']) ? (int) $_POST['vis_version'] : null;
        if ($clientVersion !== null && function_exists('bcc_get_visibility_version')) {
            $serverVersion = bcc_get_visibility_version($post_id);
            if ($clientVersion !== $serverVersion) {
                wp_send_json_error([
                    'message'     => 'Someone else changed visibility. Please refresh and try again.',
                    'vis_version' => $serverVersion,
                    'conflict'    => true,
                ], 409);
            }
        }

        $saved = bcc_set_field_visibility($post_id, $field, $visibility);

        if (!$saved) {
            wp_send_json_error([
                'message' => 'Failed to save visibility'
            ]);
        }

        // Return the new version so the client can send it on the next save.
        $newVersion = function_exists('bcc_get_visibility_version')
            ? bcc_get_visibility_version($post_id)
            : 0;

        wp_send_json_success([
            'message'     => 'Visibility updated',
            'post_id'     => $post_id,
            'field'       => $field,
            'visibility'  => $visibility,
            'vis_version' => $newVersion,
        ]);
    }
}
