<?php
/**
 * BCC PeepSo Integration – Gutenberg Block Registration
 *
 * Registers all dynamic blocks that replace the monolithic
 * [peepso_pages] shortcode with composable Gutenberg blocks.
 *
 * @package Blue_Collar_Crypto
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'bcc_register_page_blocks' );

function bcc_register_page_blocks() {
    // Shared editor script for ServerSideRender previews.
    wp_register_script(
        'bcc-page-blocks-editor',
        BCC_URL . 'assets/js/page-blocks-editor.js',
        [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
        BCC_VERSION,
        true
    );

    // Auto-discover blocks: any subdirectory containing a block.json.
    $blocks_dir = BCC_PLUGIN_PATH . 'blocks';
    foreach ( glob( $blocks_dir . '/*/block.json' ) as $block_json ) {
        register_block_type( dirname( $block_json ) );
    }
}

/**
 * Register a custom block category for BCC Page blocks.
 */
add_filter( 'block_categories_all', 'bcc_page_block_category', 10, 2 );

function bcc_page_block_category( $categories, $context ) {
    // Avoid duplicate if bcc-trust already registered a BCC category.
    foreach ( $categories as $cat ) {
        if ( $cat['slug'] === 'bcc-pages' ) {
            return $categories;
        }
    }

    return array_merge(
        [
            [
                'slug'  => 'bcc-pages',
                'title' => 'BCC Pages',
                'icon'  => 'admin-page',
            ],
        ],
        $categories
    );
}
