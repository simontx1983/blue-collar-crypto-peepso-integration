<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Security\AjaxSecurity;
use BCC\PeepSo\Repositories\VisibilityRepository;
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
        // Delegate to AjaxSecurity so nonce handling matches the other
        // two AJAX controllers (InlineEdit/Gallery) — all three now
        // surface the failure mode the same way (wp_die via
        // check_ajax_referer), so retries from the client can assume
        // uniform semantics.
        AjaxSecurity::verify_nonce();

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

        $metaKey       = '_bcc_vis_' . sanitize_key($field);
        $clientVersion = isset($_POST['vis_version']) ? (int) $_POST['vis_version'] : null;

        // No-op shortcut: if the incoming value already matches what is
        // stored, there is nothing to race on and no reason to bump the
        // version. Also lets stale-client retries succeed idempotently.
        $currentValue = get_post_meta($post_id, $metaKey, true);
        if ($currentValue === $visibility) {
            $currentVersion = function_exists('bcc_get_visibility_version')
                ? bcc_get_visibility_version($post_id)
                : 0;
            wp_send_json_success([
                'message'     => 'Visibility unchanged',
                'post_id'     => $post_id,
                'field'       => $field,
                'visibility'  => $visibility,
                'vis_version' => $currentVersion,
            ]);
        }

        // Real CAS path. The previous implementation read the version in
        // PHP, compared equal, then wrote — two concurrent writers could
        // both pass the PHP check and both write, with the loser silently
        // clobbering the winner. Here the bump is the compare-and-swap:
        // whichever writer flips meta_value first wins; the other gets
        // affected=0 and is rejected with 409 before writing the field.
        if ($clientVersion !== null) {
            // Make sure a counter row exists so CAS can target it. The
            // insert is $unique=true — safe to repeat, safe under races
            // (at most one row per post_id + meta_key combination lands).
            VisibilityRepository::seedVersion($post_id);

            if (!VisibilityRepository::compareAndBumpVersion($post_id, $clientVersion)) {
                $serverVersion = function_exists('bcc_get_visibility_version')
                    ? bcc_get_visibility_version($post_id)
                    : 0;
                wp_send_json_error([
                    'message'     => 'Someone else changed visibility. Please refresh and try again.',
                    'vis_version' => $serverVersion,
                    'conflict'    => true,
                ], 409);
            }

            // CAS won — commit the field write. If update_post_meta
            // fails (DB error, not a no-op; the no-op case was handled
            // above) we must undo the version bump so the next save
            // from this client succeeds against the right version.
            $metaResult = update_post_meta($post_id, $metaKey, $visibility);
            if ($metaResult === false) {
                VisibilityRepository::decrementVersion($post_id);
                wp_send_json_error([
                    'message' => 'Failed to save visibility'
                ]);
            }

            if (function_exists('bcc_clear_visibility_cache')) {
                bcc_clear_visibility_cache($post_id, $field);
            }

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

        // Legacy / non-browser callers (CLI, server-to-server scripts)
        // that do not track vis_version fall through to the unconditional
        // write. These do not race with browser clients in practice, and
        // the existing semantics are preserved for backward compatibility.
        $saved = bcc_set_field_visibility($post_id, $field, $visibility);

        if (!$saved) {
            wp_send_json_error([
                'message' => 'Failed to save visibility'
            ]);
        }

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
