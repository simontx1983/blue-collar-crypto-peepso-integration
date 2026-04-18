<?php
/**
 * PHPStan bootstrap for blue-collar-crypto-peepso-integration.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}

if (!defined('BCC_PEEPSO_VERSION')) {
    define('BCC_PEEPSO_VERSION', '1.0.0');
}
if (!defined('BCC_PEEPSO_PLUGIN_PATH')) {
    define('BCC_PEEPSO_PLUGIN_PATH', __DIR__ . '/');
}
if (!defined('BCC_PEEPSO_INCLUDES_PATH')) {
    define('BCC_PEEPSO_INCLUDES_PATH', __DIR__ . '/includes/');
}

// ── PeepSo class stubs ─────────────────────────────────────────────────

if (!class_exists('PeepSo')) {
    class PeepSo {
        /** @return string */
        public static function get_page(string $page = ''): string { return ''; }
        /** @return string */
        public static function get_peepso_uri(): string { return ''; }
        /** @return string */
        public static function get_asset(string $asset): string { return ''; }
    }
}

if (!class_exists('PeepSoUser')) {
    class PeepSoUser {
        /** @var int */
        public $id = 0;
        public function __construct(int $id = 0) { $this->id = $id; }
        /** @return string */
        public function get_avatar(): string { return ''; }
        /** @return string */
        public function get_fullname(): string { return ''; }
        /** @return string */
        public function get_profileurl(): string { return ''; }
    }
}

if (!class_exists('PeepSoUrlSegments')) {
    class PeepSoUrlSegments {
        /** @return self */
        public static function get_instance(): self { return new self(); }
        /** @return string|null */
        public function get(int $index): ?string { return null; }
    }
}

if (!class_exists('PeepSoPagesShortcode')) {
    class PeepSoPagesShortcode {
        /** @var int|false */
        public $page_id = false;
        /** @return self */
        public static function get_instance(): self { return new self(); }
    }
}

if (!class_exists('PeepSoPage')) {
    class PeepSoPage {
        /** @var int */
        public $id = 0;
        public function __construct(string $slug = '') {}
    }
}

// ── ACF function stubs (Advanced Custom Fields — optional dependency) ───

if (!function_exists('get_field')) {
    /**
     * @param string          $selector
     * @param int|string|false $post_id
     * @param bool            $format_value
     * @return mixed
     */
    function get_field(string $selector, $post_id = false, bool $format_value = true) { return null; }
}

if (!function_exists('update_field')) {
    /**
     * @param string          $selector
     * @param mixed           $value
     * @param int|string|false $post_id
     * @return bool
     */
    function update_field(string $selector, $value, $post_id = false): bool { return false; }
}

if (!function_exists('have_rows')) {
    /** @param string $selector @param int|string|false $post_id @return bool */
    function have_rows(string $selector, $post_id = false): bool { return false; }
}

if (!function_exists('the_row')) {
    /** @return void */
    function the_row(): void {}
}

if (!function_exists('get_sub_field')) {
    /** @param string $selector @return mixed */
    function get_sub_field(string $selector) { return null; }
}

// ── PeepSo function stubs ───────────────────────────────────────────────

if (!function_exists('peepso_get_option')) {
    /** @param mixed $default @return mixed */
    function peepso_get_option(string $key, $default = null) { return $default; }
}
