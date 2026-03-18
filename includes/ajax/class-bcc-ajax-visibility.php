<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Controllers\VisibilityController instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Ajax_Visibility extends \BCC\PeepSo\Controllers\VisibilityController {}

// Boot
BCC_Ajax_Visibility::register();
