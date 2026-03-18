<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Domain\AbstractPageType instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class BCC_Domain_Abstract extends \BCC\PeepSo\Domain\AbstractPageType {}
