<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Renderers\GalleryRenderer instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Gallery_Renderer extends \BCC\PeepSo\Renderers\GalleryRenderer {}
