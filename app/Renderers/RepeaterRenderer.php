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
    /** @param array<string, mixed> $args */
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
            '<div class="bcc-slider-wrap" data-post="%s" data-field="%s">',
            esc_attr((string) $post_id),
            esc_attr($repeater_key)
        );
    }

    /** @param array<string, mixed> $args */
    private static function renderEmptyState(array $args): void
    {
        echo '<div class="bcc-repeater-empty">' . esc_html($args['empty']) . '</div>';

        if ($args['can_edit']) {
            printf(
                '<button class="bcc-add-repeater button" data-post="%s" data-field="%s">+ Add Item</button>',
                esc_attr((string) $args['post_id']),
                esc_attr($args['repeater_key'])
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $args
     */
    private static function renderRows(array $rows, array $args): void
    {
        echo '<div class="bcc-slider">';

        foreach ($rows as $index => $row) {

            self::renderSingleRow($row, $index, $args);
        }

        echo '</div>';

        if ($args['can_edit']) {
            printf(
                '<button class="bcc-add-repeater button" data-post="%s" data-field="%s">+ Add Item</button>',
                esc_attr((string) $args['post_id']),
                esc_attr($args['repeater_key'])
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $args
     */
    private static function renderSingleRow(array $row, int $index, array $args): void
    {
        $row_classes = ['bcc-slide'];
        if ($args['can_edit']) {
            $row_classes[] = 'bcc-slide-editable';
        }

        printf(
            '<div class="%s" data-row="%s">',
            esc_attr(implode(' ', $row_classes)),
            esc_attr((string) $index)
        );

        if ($args['can_edit']) {
            echo '<span class="bcc-drag-handle" aria-label="Drag to reorder">☰</span>';
            printf(
                '<button class="bcc-delete-repeater" data-post="%s" data-field="%s" data-row="%s" aria-label="Delete item">✕</button>',
                esc_attr((string) $args['post_id']),
                esc_attr($args['repeater_key']),
                esc_attr((string) $index)
            );
        }

        self::renderSubFields($row, $index, $args);

        echo '</div>';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $args
     */
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
