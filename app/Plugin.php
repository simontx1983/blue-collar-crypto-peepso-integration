<?php

namespace BCC\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight service container for the PeepSo Integration plugin.
 *
 * All services in this plugin are static, so this is primarily
 * an organisational entry point and future-proofing for DI.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
