<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Controllers\InlineEditController instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Ajax_Inline extends \BCC\PeepSo\Controllers\InlineEditController {}

// Boot
BCC_Ajax_Inline::register();
