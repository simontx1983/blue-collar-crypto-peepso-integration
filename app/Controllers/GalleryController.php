<?php

namespace BCC\PeepSo\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Repositories\GalleryRepository;
use BCC\PeepSo\Security\AjaxSecurity;
use BCC\PeepSo\Security\FieldLock;
use BCC\PeepSo\Domain\AbstractPageType;
use BCC\Core\Security\Throttle;
use BCC\Core\Log\Logger;

/**
 * Gallery AJAX Handler
 * Combines gallery meta and main gallery functionality.
 *
 * @phpstan-import-type CollectionRow from GalleryRepository
 * @phpstan-import-type ImageRow from GalleryRepository
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

    /**
     * @phpstan-return CollectionRow
     */
    private static function getCollectionOrFail(int $post_id, int $row): object
    {
        $collection = GalleryRepository::get_or_create_collection(
            $post_id,
            get_current_user_id(),
            $row
        );

        if ($collection === null) {
            wp_send_json_error(['message' => 'Unable to load collection']);
            // Belt-and-suspenders: wp_send_json_error() normally calls wp_die()
            // which exits, but the `wp_die_handler` filter can replace the
            // handler with one that returns — in that case, we must not fall
            // through to `return $collection` (which would be null).
            // phpstan-wordpress types wp_send_json_error as `never`, so this
            // line is statically unreachable; empirically it guards against
            // hijacked handlers. @phpstan-ignore deadCode.unreachable
            exit;
        }

        return $collection;
    }

    private static function canViewOrFail(int $post_id): void
    {
        AjaxSecurity::require_view_permission($post_id);
    }

    private static function canEditOrFail(int $post_id): void
    {
        // Post-type whitelist — the gallery is only meaningful on shadow
        // CPTs created by this plugin. Without this gate, any user who
        // "owns" an arbitrary peepso-page (or any post_author match)
        // could anchor a gallery to that post: storage-quota abuse,
        // data-pollution, and gallery rows referencing posts that no
        // gallery UI ever renders.
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found.'], 404);
        }
        $shadowTypes = function_exists('bcc_get_shadow_cpt_types')
            ? bcc_get_shadow_cpt_types()
            : ['validators', 'nft', 'builder', 'dao'];
        if (!in_array($post->post_type, $shadowTypes, true)) {
            wp_send_json_error(['message' => 'Galleries are only supported on shadow CPTs.'], 400);
        }

        AjaxSecurity::require_edit_permission($post_id);
    }

    /* ======================================================
       UPLOAD
    ====================================================== */

    public static function upload(): void
    {
        AjaxSecurity::verify_nonce();

        if (!Throttle::allow('bcc_peepso.gallery_upload', 5, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $row     = absint($_POST['row'] ?? 0);

        if (!$post_id || empty($_FILES['files'])) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        // ── Per-post total caps (prevent storage abuse) ──────────────────
        $maxCollections     = (int) apply_filters('bcc_gallery_max_collections', 10);
        $maxImagesPerPost   = (int) apply_filters('bcc_gallery_max_images_per_post', 200);

        $collectionCount = GalleryRepository::count_collections($post_id);
        if ($collectionCount >= $maxCollections && $row >= $maxCollections) {
            wp_send_json_error(['message' => 'Maximum collections reached (' . $maxCollections . ').']);
        }

        $totalImages = GalleryRepository::count_images_for_post($post_id);
        if ($totalImages >= $maxImagesPerPost) {
            wp_send_json_error(['message' => 'Total image limit reached for this page (' . $maxImagesPerPost . ').']);
        }

        $collection = self::getCollectionOrFail($post_id, $row);

        // Enforce per-collection image count cap.
        $max_images = (int) apply_filters('bcc_gallery_max_images', 50);
        $current_count = GalleryRepository::count_images((int) $collection->id);
        if ($current_count >= $max_images) {
            wp_send_json_error(['message' => 'Collection image limit reached (' . $max_images . ')']);
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'bcc-gallery/';
        $base_url   = set_url_scheme(trailingslashit($upload_dir['baseurl']) . 'bcc-gallery/');

        // Upload-dir guard files. An error-suppressed @file_put_contents
        // used to quietly fail here — if that happened, the directory
        // stayed unprotected and a polyglot upload could be served as
        // executable PHP. Refuse the upload (fail-closed) and log when
        // a guard is missing and cannot be written.
        if (!file_exists($base_dir) && !wp_mkdir_p($base_dir)) {
            Logger::error('[bcc-peepso] gallery upload dir missing and unwritable', [
                'dir' => $base_dir,
            ]);
            wp_send_json_error(['message' => 'Upload storage unavailable.']);
        }

        $guards = [
            'index.php' => '<?php // Silence is golden.',
            '.htaccess' => "Options -ExecCGI\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n",
        ];
        foreach ($guards as $name => $contents) {
            $path = $base_dir . $name;
            if (file_exists($path)) {
                continue;
            }
            // Concurrent requests may race to create the same guard;
            // a file_put_contents return of false while the file now
            // exists (peer won) is fine. Only a true failure — file
            // missing AND write returned false — is a deploy-blocker.
            $written = file_put_contents($path, $contents);
            if ($written === false && !file_exists($path)) {
                Logger::error('[bcc-peepso] upload-dir guard write failed', [
                    'file' => $path,
                ]);
                wp_send_json_error(['message' => 'Upload storage misconfigured.']);
            }
        }

        $allowed_mimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ];

        $uploaded = [];

        $names = $_FILES['files']['name'] ?? [];
        $tmp   = $_FILES['files']['tmp_name'] ?? [];
        $errs  = $_FILES['files']['error'] ?? [];

        // Cap files per request to prevent resource exhaustion (GD thumbnail
        // creation is memory-intensive; 1000 files in one request = OOM).
        if (count($names) > 20) {
            wp_send_json_error(['message' => 'Too many files in one upload (max 20).']);
        }

        foreach ($names as $i => $name) {

            if (!isset($errs[$i]) || $errs[$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmp_name = $tmp[$i] ?? '';
            if (!$tmp_name || !file_exists($tmp_name)) {
                continue;
            }

            $file_info = wp_check_filetype_and_ext($tmp_name, $name);
            $mime = $file_info['type'];

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

            // Derive the stored extension from the confirmed MIME, NOT
            // from the attacker-controlled filename. Extension blacklist
            // (.php/.phtml/.phar) is brittle: .pht, .shtml, .html, and
            // polyglot SVGs slip through and Nginx hosts (ignoring
            // .htaccess) would then serve them with attacker-chosen
            // Content-Type. This allowlist maps each accepted MIME to
            // exactly one safe extension.
            $mime_to_ext = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            $ext = $mime_to_ext[$detected_mime] ?? null;
            if ($ext === null) {
                continue;
            }

            $safe = sanitize_file_name(pathinfo($name, PATHINFO_FILENAME));
            $file = $safe . '-' . uniqid('', true) . '.' . $ext;

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
                ],
                $max_images
            );

            if ($image_id === -1) {
                // Atomic cap reached inside transaction — stop uploading further images.
                @unlink($path);
                @unlink($thumb);
                break;
            }

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

        Logger::audit('gallery_upload', ['user_id' => get_current_user_id(), 'post_id' => $post_id, 'files' => count($uploaded)]);

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

        if (!Throttle::allow('bcc_peepso.gallery_delete', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

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

        if (!Throttle::allow('bcc_peepso.gallery_list', 30, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $post_id   = absint($_POST['post_id'] ?? 0);
        $row       = absint($_POST['row'] ?? 0);
        $page      = absint($_POST['page'] ?? 1);
        $per_page  = absint($_POST['per_page'] ?? 12);

        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post']);
        }

        self::canViewOrFail($post_id);

        // Read-only: do NOT create a collection just to list images.
        $collection = GalleryRepository::get_collection($post_id, $row);
        if (!$collection) {
            wp_send_json_success([
                'items'    => [],
                'total'    => 0,
                'page'     => max(1, $page),
                'per_page' => max(1, min(50, $per_page)),
            ]);
        }

        $page = max(1, $page);
        $per_page = max(1, min(50, $per_page));

        $result = GalleryRepository::get_images_paged((int) $collection->id, $page, $per_page);

        $items = [];
        foreach ($result['items'] as $img) {
            $items[] = [
                'id'        => (int) $img->id,
                'url'       => set_url_scheme((string) $img->url),
                'thumbnail' => set_url_scheme((string) ($img->thumbnail ?: $img->url)),
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'total' => $result['total'],
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

        if (!Throttle::allow('bcc_peepso.gallery_reorder', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

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

        if (!Throttle::allow('bcc_peepso.gallery_bulk_delete', 3, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

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

        Logger::audit('gallery_bulk_delete', ['user_id' => get_current_user_id(), 'post_id' => $post_id, 'count' => count($result['deleted'])]);

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

        if (!Throttle::allow('bcc_peepso.repeater_delete', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $field   = sanitize_text_field($_POST['field'] ?? '');
        $row     = absint($_POST['row'] ?? 0);

        if (!$post_id || !$field) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        self::canEditOrFail($post_id);

        $domain = AbstractPageType::get_domain_for_post($post_id);
        if ($domain === null) {
            wp_send_json_error(['message' => 'Unsupported post type']);
        }
        // Contract check delegates to AbstractPageType::assertContract() which
        // handles tagged logging, correlation ID and health-counter increment.
        if (!AbstractPageType::assertContract($domain, $post_id, $field, __METHOD__)
            || !$domain::is_valid_field($field)
        ) {
            wp_send_json_error(['message' => 'Invalid field']);
        }

        // Serialize concurrent repeater-row mutations on the same field
        // with an advisory lock. All repeater read-modify-write paths
        // (add_new, per-row update, reorder, delete) share this lock so
        // none of them can clobber each other's writes.
        $lockKey = FieldLock::acquire($post_id, $field);
        if ($lockKey === null) {
            wp_send_json_error([
                'message' => 'Another repeater edit is in progress — please retry.',
            ], 409);
        }

        try {
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
        } finally {
            FieldLock::release($lockKey);
        }
    }

    /* ======================================================
       REORDER REPEATER ROWS
    ====================================================== */

    public static function reorder_repeater_rows(): void
    {
        AjaxSecurity::verify_nonce();

        if (!Throttle::allow('bcc_peepso.repeater_reorder', 10, 60)) {
            wp_send_json_error(['message' => 'Too many requests.'], 429);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $field   = sanitize_text_field($_POST['field'] ?? '');
        $order   = $_POST['order'] ?? [];

        if (!$post_id || !$field || !is_array($order)) {
            wp_send_json_error(['message' => 'Missing data']);
        }

        $order = array_map('absint', $order);

        self::canEditOrFail($post_id);

        $domain = AbstractPageType::get_domain_for_post($post_id);
        if ($domain === null) {
            wp_send_json_error(['message' => 'Unsupported post type']);
        }
        // Contract check delegates to AbstractPageType::assertContract() which
        // handles tagged logging, correlation ID and health-counter increment.
        if (!AbstractPageType::assertContract($domain, $post_id, $field, __METHOD__)
            || !$domain::is_valid_field($field)
        ) {
            wp_send_json_error(['message' => 'Invalid field']);
        }

        // Share the lock with delete_repeater_row / InlineEditController's
        // repeater paths. Without this, a concurrent delete + reorder
        // races: reorder reads pre-delete rows, the delete commits, then
        // reorder writes the stale pre-delete array back — silently
        // resurrecting the deleted row.
        $lockKey = FieldLock::acquire($post_id, $field);
        if ($lockKey === null) {
            wp_send_json_error([
                'message' => 'Another repeater edit is in progress — please retry.',
            ], 409);
        }

        try {
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
        } finally {
            FieldLock::release($lockKey);
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

        $ok = false;
        switch ($type) {
            case IMAGETYPE_JPEG: $ok = imagejpeg($thumb, $dest, 85); break;
            case IMAGETYPE_PNG:  $ok = imagepng($thumb, $dest, 9);   break;
            case IMAGETYPE_GIF:  $ok = imagegif($thumb, $dest);      break;
            case IMAGETYPE_WEBP: $ok = imagewebp($thumb, $dest, 85); break;
        }

        if (!$ok) {
            if (class_exists(Logger::class)) {
                Logger::error('[Gallery] Failed to write thumbnail', ['dest' => $dest, 'type' => $type]);
            } else {
                error_log('[Gallery] Failed to write thumbnail: ' . $dest);
            }
        }

        imagedestroy($img);
        imagedestroy($thumb);
    }
}
