<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Repositories\GalleryRepository;
use BCC\PeepSo\Security\AjaxSecurity;
use BCC\PeepSo\Domain\AbstractPageType;

/**
 * Gallery AJAX Handler
 * Combines gallery meta and main gallery functionality.
 */
class GalleryController
{
    public static function register(): void
    {
        add_action('wp_ajax_bcc_upload_gallery_images', [__CLASS__, 'upload']);
        add_action('wp_ajax_bcc_delete_gallery_image', [__CLASS__, 'delete']);
        add_action('wp_ajax_bcc_gallery_list_images', [__CLASS__, 'list_images']);
        add_action('wp_ajax_bcc_gallery_reorder_images', [__CLASS__, 'reorder_images']);
        add_action('wp_ajax_bcc_bulk_delete_gallery_images', [__CLASS__, 'bulk_delete']);
        add_action('wp_ajax_bcc_delete_repeater_row', [__CLASS__, 'delete_repeater_row']);
        add_action('wp_ajax_bcc_repeater_reorder_rows', [__CLASS__, 'reorder_repeater_rows']);
    }

    /* ======================================================
       HELPERS
    ====================================================== */

    private static function getCollectionOrFail(int $post_id, int $row)
    {
        $collection = GalleryRepository::get_or_create_collection(
            $post_id,
            get_current_user_id(),
            $row
        );

        if (!$collection) {
            wp_send_json_error(['message' => 'Unable to load collection']);
        }

        return $collection;
    }

    private static function canViewOrFail(int $post_id): void
    {
        AjaxSecurity::require_view_permission($post_id);
    }

    private static function canEditOrFail(int $post_id): void
    {
        AjaxSecurity::require_edit_permission($post_id);
    }

    /* ======================================================
       UPLOAD
    ====================================================== */

    public static function upload(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id = absint($_POST['post_id'] ?? 0);
        $row     = absint($_POST['row'] ?? 0);

        if (!$post_id || empty($_FILES['files'])) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        $collection = self::getCollectionOrFail($post_id, $row);

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'bcc-gallery/';
        $base_url   = set_url_scheme(trailingslashit($upload_dir['baseurl']) . 'bcc-gallery/');

        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
            // Prevent direct PHP execution in the upload directory.
            @file_put_contents($base_dir . 'index.php', '<?php // Silence is golden.');
            @file_put_contents($base_dir . '.htaccess', "Options -ExecCGI\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
        }

        $allowed_mimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif'
        ];

        $uploaded = [];

        $names = $_FILES['files']['name'] ?? [];
        $tmp   = $_FILES['files']['tmp_name'] ?? [];
        $errs  = $_FILES['files']['error'] ?? [];

        foreach ($names as $i => $name) {

            if (!isset($errs[$i]) || $errs[$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmp_name = $tmp[$i] ?? '';
            if (!$tmp_name || !file_exists($tmp_name)) {
                continue;
            }

            $file_info = wp_check_filetype_and_ext($tmp_name, $name);
            $mime = $file_info['type'] ?? '';

            if (!$mime || !in_array($mime, $allowed_mimes, true)) {
                continue;
            }

            $detected_mime = AjaxSecurity::verify_file_mime($tmp_name, $allowed_mimes);
            if (!$detected_mime) {
                continue;
            }

            $file_size = $_FILES['files']['size'][$i] ?? 0;
            if ($file_size > 10 * 1024 * 1024) {
                continue;
            }

            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $safe = sanitize_file_name(pathinfo($name, PATHINFO_FILENAME));
            $file = $safe . '-' . uniqid('', true) . ($ext ? '.' . $ext : '');

            $path = $base_dir . $file;
            $url  = $base_url . $file;

            if (!move_uploaded_file($tmp_name, $path)) {
                continue;
            }

            $thumb     = $base_dir . 'thumb-' . $file;
            $thumb_url = $base_url . 'thumb-' . $file;

            self::createThumbnail($path, $thumb);

            $image_id = GalleryRepository::insert_image(
                (int) $collection->id,
                [
                    'file'      => $file,
                    'url'       => $url,
                    'thumbnail' => file_exists($thumb) ? $thumb_url : $url,
                    'size'      => @filesize($path) ?: 0,
                ]
            );

            if (!$image_id) {
                continue;
            }

            $uploaded[] = [
                'id'        => (int) $image_id,
                'url'       => $url,
                'thumbnail' => file_exists($thumb) ? $thumb_url : $url
            ];
        }

        if (!$uploaded) {
            wp_send_json_error(['message' => 'No valid images uploaded']);
        }

        $total = GalleryRepository::count_images((int) $collection->id);

        wp_send_json_success([
            'images' => $uploaded,
            'total'  => (int) $total
        ]);
    }

    /* ======================================================
       DELETE
    ====================================================== */

    public static function delete(): void
    {
        AjaxSecurity::verify_nonce();

        $image_id = absint($_POST['image_id'] ?? 0);
        $post_id  = absint($_POST['post_id'] ?? 0);
        $row      = absint($_POST['row'] ?? 0);

        if (!$image_id || !$post_id) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        $collection = self::getCollectionOrFail($post_id, $row);

        $image = GalleryRepository::delete_image($image_id, (int) $collection->id);
        if (!$image) {
            wp_send_json_error(['message' => 'Image not found or does not belong to this collection']);
        }

        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . 'bcc-gallery/';

        @unlink($base_dir . basename($image->file));
        @unlink($base_dir . 'thumb-' . basename($image->file));

        $total = GalleryRepository::count_images((int) $collection->id);

        wp_send_json_success([
            'message' => 'Deleted',
            'total'   => (int) $total
        ]);
    }

    /* ======================================================
       LIST (PAGED)
    ====================================================== */

    public static function list_images(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id   = absint($_POST['post_id'] ?? 0);
        $row       = absint($_POST['row'] ?? 0);
        $page      = absint($_POST['page'] ?? 1);
        $per_page  = absint($_POST['per_page'] ?? 12);

        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post']);
        }

        self::canViewOrFail($post_id);

        $collection = self::getCollectionOrFail($post_id, $row);

        $page = max(1, $page);
        $per_page = max(1, min(50, $per_page));

        $result = GalleryRepository::get_images_paged((int) $collection->id, $page, $per_page);

        $items = [];
        foreach (($result['items'] ?? []) as $img) {
            $items[] = [
                'id'        => (int) $img->id,
                'url'       => set_url_scheme((string) $img->url),
                'thumbnail' => set_url_scheme((string) ($img->thumbnail ?: $img->url)),
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'total' => (int) ($result['total'] ?? 0),
            'page'  => (int) $page,
            'per_page' => (int) $per_page,
        ]);
    }

    /* ======================================================
       REORDER
    ====================================================== */

    public static function reorder_images(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id = absint($_POST['post_id'] ?? 0);
        $row     = absint($_POST['row'] ?? 0);
        $order   = $_POST['order'] ?? [];

        if (!$post_id || !is_array($order)) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        $order = array_map('absint', $order);

        self::canEditOrFail($post_id);

        $collection = self::getCollectionOrFail($post_id, $row);

        $ok = GalleryRepository::update_sort_orders((int) $collection->id, $order);

        if (!$ok) {
            wp_send_json_error(['message' => 'Invalid order']);
        }

        wp_send_json_success(['message' => 'Reordered']);
    }

    /* ======================================================
       BULK DELETE
    ====================================================== */

    public static function bulk_delete(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id   = absint($_POST['post_id'] ?? 0);
        $row       = absint($_POST['row'] ?? 0);
        $image_ids = array_filter(array_map('absint', (array) ($_POST['image_ids'] ?? [])));

        if (!$post_id || empty($image_ids)) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        $collection = self::getCollectionOrFail($post_id, $row);

        $result = GalleryRepository::delete_images_bulk($image_ids, (int) $collection->id);

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'bcc-gallery/';

        foreach ($result['deleted'] as $image) {
            @unlink($base_dir . basename($image->file));
            @unlink($base_dir . 'thumb-' . basename($image->file));
        }

        $deleted = count($result['deleted']);
        $failed  = $result['failed'];

        $total = GalleryRepository::count_images((int) $collection->id);

        wp_send_json_success([
            'deleted' => $deleted,
            'failed'  => $failed,
            'total'   => (int) $total,
        ]);
    }

    /* ======================================================
       DELETE REPEATER ROW
    ====================================================== */

    public static function delete_repeater_row(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id = absint($_POST['post_id'] ?? 0);
        $field   = sanitize_text_field($_POST['field'] ?? '');
        $row     = absint($_POST['row'] ?? 0);

        if (!$post_id || !$field) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        $domain = AbstractPageType::get_domain_for_post($post_id);
        if ($domain && !call_user_func([$domain, 'is_valid_field'], $field)) {
            wp_send_json_error(['message' => 'Invalid field']);
        }

        $rows = get_field($field, $post_id);

        if (!is_array($rows)) {
            wp_send_json_error(['message' => 'No rows found']);
        }

        if (isset($rows[$row])) {
            array_splice($rows, $row, 1);
            $updated = update_field($field, $rows, $post_id);

            if ($updated) {
                wp_send_json_success([
                    'message' => 'Row deleted',
                    'rows' => count($rows)
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to update field']);
            }
        } else {
            wp_send_json_error(['message' => 'Row not found at index ' . $row]);
        }
    }

    /* ======================================================
       REORDER REPEATER ROWS
    ====================================================== */

    public static function reorder_repeater_rows(): void
    {
        AjaxSecurity::verify_nonce();

        $post_id = absint($_POST['post_id'] ?? 0);
        $field   = sanitize_text_field($_POST['field'] ?? '');
        $order   = $_POST['order'] ?? [];

        if (!$post_id || !$field || !is_array($order)) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        $order = array_map('absint', $order);

        self::canEditOrFail($post_id);

        $domain = AbstractPageType::get_domain_for_post($post_id);
        if ($domain && !call_user_func([$domain, 'is_valid_field'], $field)) {
            wp_send_json_error(['message' => 'Invalid field']);
        }

        $rows = get_field($field, $post_id);

        if (!is_array($rows)) {
            wp_send_json_error(['message' => 'No rows found']);
        }

        $reordered_rows = [];
        foreach ($order as $old_index) {
            if (isset($rows[$old_index])) {
                $reordered_rows[] = $rows[$old_index];
            }
        }

        if (count($reordered_rows) === count($rows)) {
            $updated = update_field($field, $reordered_rows, $post_id);

            if ($updated) {
                wp_send_json_success(['message' => 'Rows reordered']);
            } else {
                wp_send_json_error(['message' => 'Failed to update field']);
            }
        } else {
            wp_send_json_error(['message' => 'Invalid order data']);
        }
    }

    /* ======================================================
       THUMBNAIL CREATION
    ====================================================== */

    private static function createThumbnail(string $src, string $dest): void
    {
        if (!file_exists($src)) return;

        $info = @getimagesize($src);
        if (!$info) return;

        [$w, $h, $type] = $info;

        switch ($type) {
            case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src);  break;
            case IMAGETYPE_GIF:  $img = @imagecreatefromgif($src);  break;
            case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($src); break;
            default: return;
        }

        if (!$img) return;

        $size  = 200;
        $thumb = imagecreatetruecolor($size, $size);

        if (!$thumb) {
            imagedestroy($img);
            return;
        }

        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $size, $size, $w, $h);

        switch ($type) {
            case IMAGETYPE_JPEG: imagejpeg($thumb, $dest, 85); break;
            case IMAGETYPE_PNG:  imagepng($thumb, $dest, 9);   break;
            case IMAGETYPE_GIF:  imagegif($thumb, $dest);      break;
            case IMAGETYPE_WEBP: imagewebp($thumb, $dest, 85); break;
        }

        imagedestroy($img);
        imagedestroy($thumb);
    }
}
