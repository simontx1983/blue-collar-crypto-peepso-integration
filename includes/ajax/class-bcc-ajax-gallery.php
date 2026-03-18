<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Controllers\GalleryController instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Ajax_Gallery extends \BCC\PeepSo\Controllers\GalleryController {}

// Register all AJAX handlers
BCC_Ajax_Gallery::register();
