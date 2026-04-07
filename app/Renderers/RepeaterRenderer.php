<?php

namespace BCC\PeepSo\Renderers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repeater Slider Renderer
 */
class RepeaterRenderer
{
    public static function render(array $args = []): void
    {
        if (!function_exists('get_field')) {
            return;
        }

        $a = wp_parse_args($args, [
            'post_id'      => 0,
            'repeater_key' => '',
            'fields'       => [],
            'can_edit'     => false,
            'empty'        => 'No entries yet'
        ]);

        if (empty($a['post_id']) || empty($a['repeater_key'])) {
            return;
        }

        $rows = get_field($a['repeater_key'], $a['post_id']);
        $has_rows = !empty($rows) && is_array($rows);

        self::openWrapper($a['post_id'], $a['repeater_key']);

        if (!$has_rows) {
            self::renderEmptyState($a);
        } else {
            self::renderRows($rows, $a);
        }

        self::closeWrapper();
    }

    private static function openWrapper(int $post_id, string $repeater_key): void
    {
        printf(
            '<div class="bcc-slider-wrap" data-post="%d" data-field="%s">',
            esc_attr($post_id),
            esc_attr($repeater_key)
        );
    }

    private static function renderEmptyState(array $args): void
    {
        echo '<div class="bcc-repeater-empty">' . esc_html($args['empty']) . '</div>';

        if ($args['can_edit']) {
            printf(
                '<button class="bcc-add-repeater button" data-post="%d" data-field="%s">+ Add Item</button>',
                esc_attr($args['post_id']),
                esc_attr($args['repeater_key'])
            );
        }
    }

    private static function renderRows(array $rows, array $args): void
    {
        echo '<div class="bcc-slider">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) continue;

            self::renderSingleRow($row, $index, $args);
        }

        echo '</div>';

        if ($args['can_edit']) {
            printf(
                '<button class="bcc-add-repeater button" data-post="%d" data-field="%s">+ Add Item</button>',
                esc_attr($args['post_id']),
                esc_attr($args['repeater_key'])
            );
        }
    }

    private static function renderSingleRow(array $row, int $index, array $args): void
    {
        $row_classes = ['bcc-slide'];
        if ($args['can_edit']) {
            $row_classes[] = 'bcc-slide-editable';
        }

        printf(
            '<div class="%s" data-row="%d">',
            esc_attr(implode(' ', $row_classes)),
            esc_attr($index)
        );

        if ($args['can_edit']) {
            echo '<span class="bcc-drag-handle" aria-label="Drag to reorder">☰</span>';
            printf(
                '<button class="bcc-delete-repeater" data-post="%d" data-field="%s" data-row="%d" aria-label="Delete item">✕</button>',
                esc_attr($args['post_id']),
                esc_attr($args['repeater_key']),
                esc_attr($index)
            );
        }

        self::renderSubFields($row, $index, $args);

        echo '</div>';
    }

    private static function renderSubFields(array $row, int $index, array $args): void
    {
        foreach ($args['fields'] as $subkey => $config) {
            if (empty($config['label'])) continue;

            $renderer = new FieldRenderer([
                'post_id'  => $args['post_id'],
                'field'    => $args['repeater_key'],
                'label'    => $config['label'],
                'value'    => $row[$subkey] ?? '',
                'type'     => $config['type'] ?? 'text',
                'options'  => $config['options'] ?? '',
                'can_edit' => $args['can_edit'],
                'repeater' => true,
                'sub'      => $subkey,
                'row'      => $index
            ]);

            $renderer->render();
        }
    }

    private static function closeWrapper(): void
    {
        echo '</div>';
    }

}
