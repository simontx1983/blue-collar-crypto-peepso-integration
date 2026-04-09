<?php
if (!defined('ABSPATH')) exit;

/**
 * Legacy template functions for backward compatibility
 */

if (!function_exists('bcc_render_divider')) {
    function bcc_render_divider(): void {
        echo '<hr class="bcc-divider">';
    }
}

if (!function_exists('bcc_render_row')) {
    function bcc_render_row(array $args = []): void {
        $renderer = new \BCC\PeepSo\Renderers\FieldRenderer($args);
        $renderer->render();
    }
}

if (!function_exists('bcc_render_rows')) {
    function bcc_render_rows(int $post_id, array $fields, bool $can_edit = false): void {
        foreach ($fields as $field => $args) {
            if (empty($args['label'])) continue;
            
            bcc_render_row([
                'post_id'  => $post_id,
                'field'    => $field,
                'label'    => $args['label'],
                'type'     => $args['type'] ?? 'text',
                'options'  => $args['options'] ?? '',
                'can_edit' => $can_edit
            ]);
        }
    }
}

if ( ! function_exists( 'bcc_get_source_registry' ) ) {
    /**
     * Source registry for data-source badges.
     *
     * Each key maps to an icon (HTML entity), a human-readable label,
     * and a CSS modifier class. New verification sources (api, manual,
     * kyc, etc.) are added here — no template changes needed.
     *
     * @return array<string, array{icon: string, label: string, class: string}>
     */
    function bcc_get_source_registry(): array {
        static $registry = null;

        if ( null === $registry ) {
            $registry = [
                'user' => [
                    'icon'  => '&#x270F;&#xFE0E;',
                    'label' => 'Self-Reported (Unverified)',
                    'class' => 'bcc-source-badge--user',
                ],
                'onchain' => [
                    'icon'  => '&#x1f517;',
                    'label' => 'Verified &middot; On-chain',
                    'class' => 'bcc-source-badge--onchain',
                ],
                'api' => [
                    'icon'  => '&#x1F310;',
                    'label' => 'Verified &middot; API',
                    'class' => 'bcc-source-badge--api',
                ],
                'manual' => [
                    'icon'  => '&#x2714;&#xFE0E;',
                    'label' => 'Manually Verified',
                    'class' => 'bcc-source-badge--manual',
                ],
            ];
        }

        return $registry;
    }
}

if ( ! function_exists( 'bcc_render_source_badge' ) ) {
    /**
     * Render a data-source badge pill.
     *
     * Uses the central source registry so every badge is consistent.
     * Falls back silently if the source type is unknown.
     *
     * @param string $source Registry key: 'user', 'onchain', 'api', 'manual', etc.
     */
    function bcc_render_source_badge( string $source = 'user' ): void {
        $registry = bcc_get_source_registry();

        if ( ! isset( $registry[ $source ] ) ) {
            return; // Unknown source — fail silently.
        }

        $entry = $registry[ $source ];

        $allowed_html = [
            'span' => [ 'class' => [] ],
        ];

        printf(
            '<span class="bcc-source-badge %s"><span class="bcc-source-badge__icon">%s</span> %s</span>',
            esc_attr( $entry['class'] ),
            wp_kses( $entry['icon'], $allowed_html ),
            wp_kses( $entry['label'], $allowed_html )
        );
    }
}

if (!function_exists('bcc_section_header')) {
    /**
     * Render a section header with a data-source badge.
     *
     * Outputs the .bcc-section-head wrapper, the <h3>, and the badge
     * in one call so templates stay clean.
     *
     * @param string $title  Section title (will be escaped).
     * @param string $source Registry key: 'user', 'onchain', 'api', 'manual'.
     */
    function bcc_section_header(string $title, string $source = 'user'): void {
        echo '<div class="bcc-section-head">';
        printf('<h3 class="bcc-section-title">%s</h3>', esc_html($title));
        bcc_render_source_badge($source);
        echo '</div>';
    }
}

if (!function_exists('bcc_render_repeater_slider')) {
    function bcc_render_repeater_slider(array $args = []): void {
        if (class_exists('\\BCC\\PeepSo\\Renderers\\RepeaterRenderer')) {
            \BCC\PeepSo\Renderers\RepeaterRenderer::render($args);
        } else {
            echo '<p>Repeater renderer not available.</p>';
        }
    }
}

/**
 * Aggregate on-chain collection stats from a merged collection list.
 *
 * Extracts volume, holder count, and average floor price from items
 * tagged as 'onchain' (self-reported items are excluded). Keeps
 * aggregation logic out of templates.
 *
 * @param array $collections Merged collection list (objects with data_source, total_volume, etc.).
 * @return array{count: int, volume: float, holders: int, avg_floor: float, native_token: string}
 */
if (!function_exists('bcc_aggregate_collection_stats')) {
    function bcc_aggregate_collection_stats(array $collections): array {
        $volume  = 0.0;
        $holders = 0;
        $floors  = [];
        $count   = 0;
        $native  = 'ETH';

        foreach ($collections as $c) {
            if (($c->data_source ?? '') !== 'onchain') {
                continue;
            }
            $count++;
            $volume  += (float) ($c->total_volume ?? 0);
            $holders += (int) ($c->unique_holders ?? 0);
            if ($c->floor_price !== null && (float) $c->floor_price > 0) {
                $floors[] = (float) $c->floor_price;
            }
            if (!empty($c->native_token)) {
                $native = $c->native_token;
            }
        }

        return [
            'count'        => $count,
            'volume'       => $volume,
            'holders'      => $holders,
            'avg_floor'    => !empty($floors) ? array_sum($floors) / count($floors) : 0.0,
            'native_token' => $native,
        ];
    }
}