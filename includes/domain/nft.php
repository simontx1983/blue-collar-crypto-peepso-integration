<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Domain\NftPageType instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Domain_NFT extends \BCC\PeepSo\Domain\NftPageType {}

function bcc_get_nft_id($page_id) {
    return BCC_Domain_NFT::get_or_create_from_page((int) $page_id);
}
