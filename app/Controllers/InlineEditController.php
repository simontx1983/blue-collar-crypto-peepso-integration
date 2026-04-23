<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Domain\AbstractPageType;
use BCC\PeepSo\Security\AjaxSecurity;
use BCC\PeepSo\Security\FieldLock;
use BCC\Core\Security\Throttle;
use BCC\Core\Log\Logger;

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

    private static function auditEdit(int $post_id, string $field, string $action, mixed $newValue, ?string $sub = null): void
    {
        if (!class_exists(Logger::class)) {
            return;
        }
        $ctx = [
            'user_id' => get_current_user_id(),
            'post_id' => $post_id,
            'field'   => $field,
            'action'  => $action,
        ];
        if ($sub !== null) {
            $ctx['sub'] = $sub;
        }
        if (is_scalar($newValue)) {
            $ctx['value'] = mb_substr((string) $newValue, 0, 200);
        }
        Logger::audit('inline_edit', $ctx);
    }

    public static function handle(): void
    {
        check_ajax_referer('bcc_nonce', 'nonce');

        // Check ACF availability before consuming a rate-limit token,
        // so a missing ACF doesn't burn the user's quota.
        if (!function_exists('update_field')) {
            wp_send_json_error('ACF not active');
        }

        if (!Throttle::allow('bcc_peepso.inline_edit', 30, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
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

        AjaxSecurity::require_edit_permission($post_id);

        $domain = self::getDomainClass($post_id);

        if (!$domain || !class_exists($domain)) {
            wp_send_json_error('Unsupported post type');
        }

        if (!method_exists($domain, 'is_valid_field') || !$domain::is_valid_field($field)) {
            wp_send_json_error('Invalid field');
        }

        if ($repeater && $sub && $sub !== 'add_new') {
            if (!method_exists($domain, 'is_valid_subfield') || !$domain::is_valid_subfield($field, $sub)) {
                wp_send_json_error('Invalid sub field');
            }
        }

        if ($repeater && $sub === 'add_new') {
            // Shared lock with GalleryController's delete/reorder and the
            // repeater_update branch below. Without it, concurrent
            // add_new + delete / add_new + add_new races via ACF's
            // read-modify-write pattern silently drop or resurrect rows.
            $lockKey = FieldLock::acquire($post_id, $field);
            if ($lockKey === null) {
                wp_send_json_error([
                    'message' => 'Another repeater edit is in progress — please retry.',
                ], 409);
            }
            try {
                $rows = get_field($field, $post_id);
                if (!is_array($rows)) {
                    $rows = [];
                }
                $rows[] = [];
                self::auditEdit($post_id, $field, 'repeater_add', count($rows));
                update_field($field, $rows, $post_id);
                wp_send_json_success([
                    'message' => 'New item added',
                    'rows'    => count($rows)
                ]);
            } finally {
                FieldLock::release($lockKey);
            }
        }

        if ($type === 'gallery') {
            if (!empty($value)) {
                $value = array_map('intval', explode(',', $value));
            } else {
                $value = [];
            }
        }

        if (!$repeater) {
            // Non-repeater path: single post_meta write is atomic at the
            // UPDATE-statement level. Last-writer-wins is acceptable
            // here — there's no read-modify-write window.
            self::auditEdit($post_id, $field, 'update', $value);
            update_field($field, $value, $post_id);
            wp_send_json_success([
                'value' => $value
            ]);
        }

        if (!$sub) {
            wp_send_json_error('Missing sub field');
        }

        // Repeater per-row update: read-modify-write on the rows array.
        // Must hold the same lock as add_new / delete / reorder, or a
        // concurrent mutation silently overwrites this write.
        $lockKey = FieldLock::acquire($post_id, $field);
        if ($lockKey === null) {
            wp_send_json_error([
                'message' => 'Another repeater edit is in progress — please retry.',
            ], 409);
        }
        try {
            $rows = get_field($field, $post_id);
            if (!is_array($rows)) {
                $rows = [];
            }
            if (!isset($rows[$row])) {
                $rows[$row] = [];
            }

            $rows[$row][$sub] = $value;
            self::auditEdit($post_id, $field, 'repeater_update', $value, $sub);
            update_field($field, $rows, $post_id);

            wp_send_json_success([
                'value' => $value
            ]);
        } finally {
            FieldLock::release($lockKey);
        }
    }
}
