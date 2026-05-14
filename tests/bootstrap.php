<?php

declare(strict_types=1);

/**
 * Test bootstrap for Reach.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}

// In-memory transient + options store for tests.
$GLOBALS['__reach_transients'] = [];
$GLOBALS['__reach_options'] = [];
$GLOBALS['__reach_salts'] = [
    'auth'      => 'test-auth-salt-' . str_repeat('x', 48),
    'logged_in' => 'test-login-salt-' . str_repeat('y', 48),
];

if (!function_exists('get_transient')) {
    function get_transient(string $key) { return $GLOBALS['__reach_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool {
        $GLOBALS['__reach_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['__reach_transients'][$key]);
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false) {
        return $GLOBALS['__reach_options'][$key] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool {
        $GLOBALS['__reach_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return $GLOBALS['__reach_salts'][$scheme] ?? 'fallback-salt';
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $flags = 0, int $depth = 512): string {
        return (string) json_encode($data, $flags, $depth);
    }
}
if (!function_exists('is_email')) {
    function is_email(string $value): bool {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
if (!function_exists('is_ssl')) {
    function is_ssl(): bool { return false; }
}
if (!function_exists('headers_sent')) {
    // Provided by PHP — but stub if needed for environments without it.
}

// Minimal WP_Error shim and HTTP function stubs.
//
// Tests that need to control remote responses (the JWT verifier in
// particular) set $GLOBALS['__reach_http_stub'] to a callable
// (function(string $url, array $args = []): array|WP_Error) which
// gets invoked here. Anything not stubbed returns a network-error
// shaped WP_Error so production code paths that handle errors are
// exercised rather than silently bypassed.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            public mixed $data = null,
        ) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof \WP_Error; }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []) {
        $stub = $GLOBALS['__reach_http_stub'] ?? null;
        if (is_callable($stub)) {
            return $stub($url, $args);
        }
        return new \WP_Error('no_stub', 'No HTTP stub for ' . $url);
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []) {
        $stub = $GLOBALS['__reach_http_stub'] ?? null;
        if (is_callable($stub)) {
            return $stub($url, $args);
        }
        return new \WP_Error('no_stub', 'No HTTP stub for ' . $url);
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int {
        if (is_array($response) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }
        return 0;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        if (is_array($response) && isset($response['body']) && is_string($response['body'])) {
            return $response['body'];
        }
        return '';
    }
}

// Reach autoloader.
spl_autoload_register(function ($class) {
    $prefix = 'Reach\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
