<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Renderers\FieldRenderer instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Field_Renderer extends \BCC\PeepSo\Renderers\FieldRenderer {}
