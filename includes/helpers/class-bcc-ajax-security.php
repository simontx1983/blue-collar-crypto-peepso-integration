<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\PeepSo\Security\AjaxSecurity instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Ajax_Security extends \BCC\PeepSo\Security\AjaxSecurity {}
