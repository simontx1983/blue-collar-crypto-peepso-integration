<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Domain\DaoPageType instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Domain_DAO extends \BCC\PeepSo\Domain\DaoPageType {}

function bcc_get_dao_id($page_id) {
    return BCC_Domain_DAO::get_or_create_from_page((int) $page_id);
}
