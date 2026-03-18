<?php

namespace BCC\PeepSo\Renderers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\PeepSo\Helpers\OptionsHelper;

/**
 * Field Renderer (Single Field Row)
 */
class FieldRenderer
{
    private array $args;

    private int $post_id;
    private string $field;
    private string $label;
    private $value;
    private string $type;
    private string $options;
    private bool $can_edit;
    private bool $repeater;
    private string $sub;
    private int $row;
    private $display_value;

    /* ======================================================
       CONSTRUCTOR
    ====================================================== */

    public function __construct(array $args = [])
    {
        $this->args = wp_parse_args($args, [
            'post_id'  => 0,
            'field'    => '',
            'label'    => '',
            'value'    => null,
            'type'     => 'text',
            'options'  => '',
            'can_edit' => false,
            'repeater' => false,
            'sub'      => '',
            'row'      => 0
        ]);

        $this->post_id  = (int) $this->args['post_id'];
        $this->field    = (string) $this->args['field'];
        $this->label    = (string) $this->args['label'];
        $this->value    = $this->args['value'];
        $this->type     = (string) $this->args['type'];
        $this->options  = (string) $this->args['options'];
        $this->can_edit = (bool) $this->args['can_edit'];
        $this->repeater = (bool) $this->args['repeater'];
        $this->sub      = (string) $this->args['sub'];
        $this->row      = (int) $this->args['row'];
    }

    /* ======================================================
       ENTRY
    ====================================================== */

    public function render(): void
    {
        if (!$this->post_id || !$this->field) {
            return;
        }

        if ($this->value === null && function_exists('get_field')) {
            $this->value = get_field($this->field, $this->post_id);
        }

        $this->processValue();

        if (!$this->checkVisibility()) {
            return;
        }

        $can_edit = $this->checkEditPermission();

        $this->openRow();

        if ($can_edit) {
            $this->renderEditMode();
        } else {
            $this->renderViewMode();
        }

        $this->closeRow();
    }

    /* ======================================================
       VALUE PROCESSING
    ====================================================== */

    private function processValue(): void
    {
        if (is_array($this->value) && $this->type !== 'gallery') {
            $this->value = implode(', ', array_filter($this->value));
        }

        if ($this->type === 'select' && !empty($this->options)) {
            $map = OptionsHelper::parse_options_string($this->options);
            $this->display_value = $map[$this->value] ?? $this->value;
        } else {
            $this->display_value = $this->value;
        }
    }

    /* ======================================================
       VISIBILITY
    ====================================================== */

    private function checkVisibility(): bool
    {
        if (!function_exists('bcc_user_can_view_field')) {
            return true;
        }

        return bcc_user_can_view_field($this->post_id, $this->field);
    }

    private function checkEditPermission(): bool
    {
        if (function_exists('bcc_user_can_edit_field')) {
            return bcc_user_can_edit_field($this->post_id, $this->field);
        }

        return $this->can_edit;
    }

    /* ======================================================
       ROW WRAPPERS
    ====================================================== */

    private function openRow(): void
    {
        echo '<div class="bcc-row bcc-row-type-' . esc_attr($this->type) . '">';
        echo '<div class="bcc-row-label">' . esc_html($this->label) . '</div>';
        echo '<div class="bcc-row-value">';
    }

    private function closeRow(): void
    {
        echo '</div></div>';
    }

    /* ======================================================
       VIEW MODE
    ====================================================== */

    private function renderViewMode(): void
    {
        switch ($this->type) {

            case 'gallery':
                GalleryRenderer::render_view(
                    $this->post_id,
                    $this->row
                );
                break;

            case 'wysiwyg':
                echo wp_kses_post($this->display_value ?: '');
                break;

            case 'url':
                if (!empty($this->display_value)) {
                    echo '<a href="' . esc_url($this->display_value) . '" target="_blank" rel="noopener noreferrer">';
                    echo esc_html($this->display_value);
                    echo '</a>';
                    echo $this->copyButtonHtml((string) $this->display_value);
                } else {
                    echo '—';
                }
                break;

            default:
                if (!empty($this->display_value)) {
                    echo esc_html($this->display_value);
                    if ($this->isCopyableField()) {
                        echo $this->copyButtonHtml((string) $this->display_value);
                    }
                } else {
                    echo '—';
                }
                break;
        }
    }

    /* ======================================================
       COPY BUTTON HELPERS
    ====================================================== */

    private function copyButtonHtml(string $value): string
    {
        if (empty($value)) return '';
        return '<button type="button" class="bcc-copy-btn" data-copy="' . esc_attr($value) . '" title="Copy to clipboard">'
             . '<span class="dashicons dashicons-clipboard"></span>'
             . '</button>';
    }

    private function isCopyableField(): bool
    {
        $keywords = ['url', 'link', 'address', 'wallet', 'contract', 'rpc', 'token'];
        $lower    = strtolower($this->field);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    /* ======================================================
       EDIT MODE
    ====================================================== */

    private function renderEditMode(): void
    {
        $data_attrs = $this->buildDataAttributes();

        if ($this->type === 'gallery') {

            GalleryRenderer::render_edit(
                $this->post_id,
                $data_attrs,
                $this->row
            );

        } else {

            $class = ($this->type === 'select')
                ? 'bcc-inline-select'
                : 'bcc-inline-text';

            echo '<span class="' . esc_attr($class) . '" ' . $data_attrs . '>';

            if ($this->type === 'wysiwyg') {
                echo wp_kses_post($this->display_value ?: '');
            } else {
                echo esc_html($this->display_value ?: 'Update Now');
            }

            echo '</span>';

            echo '<button type="button" class="bcc-inline-edit-btn">Edit</button>';
        }

        $this->renderVisibilityPill();
    }

    /* ======================================================
       DATA ATTRIBUTES
    ====================================================== */

    private function buildDataAttributes(): string
    {
        $raw_value = is_array($this->value) ? implode(', ', array_filter($this->value)) : (string) $this->value;

        return sprintf(
            'data-post="%s" data-field="%s" data-type="%s" data-value="%s" data-repeater="%s" data-sub="%s" data-row="%s" data-options="%s"',
            esc_attr($this->post_id),
            esc_attr($this->field),
            esc_attr($this->type),
            esc_attr($raw_value),
            esc_attr($this->repeater ? 1 : 0),
            esc_attr($this->sub),
            esc_attr($this->row),
            esc_attr($this->options)
        );
    }

    /* ======================================================
       VISIBILITY PILL
    ====================================================== */

    private function renderVisibilityPill(): void
    {
        if (!function_exists('bcc_get_field_visibility')) {
            return;
        }

        if (!function_exists('bcc_user_can_edit_post') || !bcc_user_can_edit_post($this->post_id)) {
            return;
        }

        $vis = bcc_get_field_visibility($this->post_id, $this->field);

        $labels = [
            'public'  => '🌍 Public',
            'members' => '👥 Members',
            'private' => '🔒 Private'
        ];

        $label = $labels[$vis] ?? $labels['public'];

        echo '<button class="bcc-visibility-pill ' . esc_attr($vis) . '" ';
        echo 'data-post="' . esc_attr($this->post_id) . '" ';
        echo 'data-field="' . esc_attr($this->field) . '" ';
        echo 'data-current="' . esc_attr($vis) . '">';
        echo esc_html($label);
        echo '</button>';
    }
}
