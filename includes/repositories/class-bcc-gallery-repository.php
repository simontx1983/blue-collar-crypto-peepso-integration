<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Repositories\GalleryRepository instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Gallery_Repository extends \BCC\PeepSo\Repositories\GalleryRepository {}
