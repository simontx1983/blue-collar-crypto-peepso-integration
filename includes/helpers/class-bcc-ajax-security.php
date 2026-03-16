<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared AJAX security helpers.
 * Centralises nonce verification, permission checks, and file validation
 * so they aren't duplicated across every AJAX handler.
 */
class BCC_Ajax_Security {

    /**
     * Verify the standard BCC nonce or die.
     */
    public static function verify_nonce(): void {
        check_ajax_referer('bcc_nonce', 'nonce');
    }

    /**
     * Verify the current user can edit the given post, or send a JSON error and die.
     */
    public static function require_edit_permission(int $post_id): void {
        if (function_exists('bcc_user_can_edit_post')) {
            if (!bcc_user_can_edit_post($post_id)) {
                wp_send_json_error(['message' => 'Permission denied']);
            }
        } elseif (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
    }

    /**
     * Verify the current user can view the given post, or send a JSON error and die.
     */
    public static function require_view_permission(int $post_id): void {
        if (function_exists('bcc_user_can_view_post')) {
            if (!bcc_user_can_view_post($post_id)) {
                wp_send_json_error(['message' => 'Not allowed']);
            }
        } elseif (!current_user_can('read_post', $post_id)) {
            wp_send_json_error(['message' => 'Not allowed']);
        }
    }

    /**
     * Validate an uploaded file's MIME type using finfo (magic bytes).
     * Returns the detected MIME type string, or false on mismatch / failure.
     */
    public static function verify_file_mime(string $file_path, array $allowed_mimes): string|false {
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
