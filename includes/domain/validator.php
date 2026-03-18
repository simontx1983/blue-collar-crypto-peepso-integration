<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Domain\ValidatorPageType instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Domain_Validator extends \BCC\PeepSo\Domain\ValidatorPageType {}

function bcc_get_validator_id($page_id) {
    return BCC_Domain_Validator::get_or_create_from_page((int) $page_id);
}
