<?php

namespace BCC\PeepSo\Renderers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Repositories\GalleryRepository;

/**
 * Gallery Renderer (View + Edit)
 */
class GalleryRenderer
{
    /* ======================================================
       VIEW MODE
    ====================================================== */

    public static function render_view(int $post_id, int $row = 0): void
    {
        $collection = GalleryRepository::get_collection($post_id, $row);

        if (!$collection) {
            echo '<div class="bcc-gallery-empty">—</div>';
            return;
        }

        $result = GalleryRepository::get_images_paged((int) $collection->id, 1, 12);
        $images = $result['items'] ?? [];

        if (!$images) {
            echo '<div class="bcc-gallery-empty">—</div>';
            return;
        }

        self::renderMainSlider($images);
    }

    /* ======================================================
       EDIT MODE
    ====================================================== */

    public static function render_edit(int $post_id, string $data_attrs, int $row = 0): void
    {
        $collection = GalleryRepository::get_or_create_collection(
            $post_id,
            get_current_user_id(),
            $row
        );

        if (!$collection) {
            echo '<div class="bcc-gallery-empty">Unable to load gallery</div>';
            return;
        }

        $result = GalleryRepository::get_images_paged((int) $collection->id, 1, 12);

        $images = $result['items'] ?? [];
        $total  = (int) ($result['total'] ?? 0);

        echo '<div class="bcc-gallery-container" ' . $data_attrs .
             ' data-post="' . esc_attr($post_id) . '"' .
             ' data-row="' . esc_attr($row) . '">';

        echo '<input type="file"
                    class="bcc-gallery-file-input"
                    accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,image/heic,image/heif"
                    multiple
                    style="display: none;">';

        echo '<div class="bcc-gallery-slider-section">';
        self::renderMainSlider($images);
        echo '</div>';

        echo '<div class="bcc-gallery-thumb-slider">';

        echo '<button type="button" class="bcc-thumb-arrow bcc-thumb-prev" aria-label="Scroll thumbnails left">‹</button>';

        echo '<div class="bcc-gallery-thumbnails" data-total="' . esc_attr($total) . '" data-page="1">';

        foreach ($images as $img) {
            self::renderThumbnail($img, $row);
        }

        echo '</div>';

        echo '<button type="button" class="bcc-thumb-arrow bcc-thumb-next" aria-label="Scroll thumbnails right">›</button>';

        echo '</div>';

        echo '<div class="bcc-gallery-actions-wrapper">';
        echo '<div class="bcc-gallery-action-buttons">';
        echo '<button type="button" class="button button-primary bcc-gallery-upload">';
        echo '<span class="dashicons dashicons-upload"></span> Upload Images';
        echo '</button>';

        echo '<button type="button" class="button bcc-bulk-select">Select All</button>';
        echo '<button type="button" class="button bcc-bulk-delete">Delete Selected</button>';
        echo '</div>';

        echo '<div class="bcc-gallery-count-wrapper">';
        echo '<span class="bcc-gallery-count">' . $total . ' image' . ($total !== 1 ? 's' : '') . '</span>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /* ======================================================
       MAIN IMAGE SLIDER
    ====================================================== */
    private static function renderMainSlider(array $images): void
    {
        echo '<div class="bcc-gallery-slider-wrapper">';
        echo '<div class="bcc-gallery-slider">';

        $first = true;
        foreach ($images as $img) {
            $active_class = $first ? 'active' : '';
            $first = false;

            echo '<div class="bcc-slider-item ' . $active_class . '">';
            echo '<img src="' . esc_url(set_url_scheme($img->url)) . '" loading="lazy" alt="">';
            echo '</div>';
        }

        echo '</div>';

        if (!empty($images)) {
            echo '<div class="bcc-slider-controls">';
            echo '<button type="button" class="bcc-slider-prev" aria-label="Previous image">';
            echo '<span class="dashicons dashicons-arrow-left-alt2"></span>';
            echo '</button>';

            echo '<div class="bcc-slider-dots">';
            foreach ($images as $index => $img) {
                $dot_active = $index === 0 ? 'active' : '';
                echo '<span class="bcc-slider-dot ' . $dot_active . '" data-index="' . $index . '"></span>';
            }
            echo '</div>';

            echo '<button type="button" class="bcc-slider-next" aria-label="Next image">';
            echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
            echo '</button>';
            echo '</div>';

            echo '<div class="bcc-slider-counter">1 / ' . count($images) . '</div>';
        }

        echo '</div>';
    }

    /* ======================================================
       THUMBNAIL - WITH REPEATER DATA
    ====================================================== */
    private static function renderThumbnail(object $img, int $row = 0): void
    {
        echo '<div class="bcc-gallery-thumb-wrapper" draggable="true" data-id="' . esc_attr($img->id) . '" data-row="' . esc_attr($row) . '">';

        echo '<input type="checkbox" class="bcc-thumb-select">';

        echo '<img src="' . esc_url(set_url_scheme($img->thumbnail ?: $img->url)) . '" loading="lazy" alt="">';

        echo '<span class="bcc-gallery-remove" title="Remove image">×</span>';

        echo '</div>';
    }
}
