<?php

namespace BCC\PeepSo\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared AJAX security helpers.
 */
class AjaxSecurity
{
    public static function verify_nonce(): void
    {
        check_ajax_referer('bcc_nonce', 'nonce');
    }

    public static function require_edit_permission(int $post_id): void
    {
        if (function_exists('bcc_user_can_edit_post')) {
            if (!bcc_user_can_edit_post($post_id)) {
                wp_send_json_error(['message' => 'Permission denied']);
            }
        } elseif (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
    }

    public static function require_view_permission(int $post_id): void
    {
        if (function_exists('bcc_user_can_view_post')) {
            if (!bcc_user_can_view_post($post_id)) {
                wp_send_json_error(['message' => 'Not allowed']);
            }
        } elseif (!current_user_can('read_post', $post_id)) {
            wp_send_json_error(['message' => 'Not allowed']);
        }
    }

    public static function verify_file_mime(string $file_path, array $allowed_mimes): string|false
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return false;
        }

        $detected = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        if (!$detected || !in_array($detected, $allowed_mimes, true)) {
            return false;
        }

        return $detected;
    }
}
