<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Domain\BuilderPageType instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Domain_Builder extends \BCC\PeepSo\Domain\BuilderPageType {}

function bcc_get_builder_id($page_id) {
    return BCC_Domain_Builder::get_or_create_from_page((int) $page_id);
}
