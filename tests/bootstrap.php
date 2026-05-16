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

// Unity interfaces. The resolver and a few admin classes typehint
// against Unity\Members\Interfaces\{Member, MemberRepository, ...}, so
// the test suite needs those interfaces on the classpath.
//
// First choice: load them from a sibling Unity checkout. UNITY_PATH
// overrides the default location.
//
// Fallback: define a minimal stub inline so the suite runs out of the
// box for a contributor who doesn't have Unity checked out next door.
// The stub mirrors the real Unity interface shape closely enough for
// the resolver pipeline tests; tests that rely on richer Unity
// behaviour should set UNITY_PATH.
$unityPath = getenv('UNITY_PATH') ?: dirname(__DIR__, 2) . '/unity';
$memberInterface = $unityPath . '/src/Members/Interfaces/Member.php';
$repoInterface   = $unityPath . '/src/Members/Interfaces/MemberRepository.php';
if (file_exists($memberInterface) && file_exists($repoInterface)) {
    require_once $memberInterface;
    require_once $repoInterface;
} elseif (!interface_exists(\Unity\Members\Interfaces\Member::class)) {
    eval(<<<'PHP'
namespace Unity\Members\Interfaces;

interface Member
{
    public function getId(): int;
    public function getAnonymousName(): string;
    public function showAnonymousName(): bool;
    public function showMemberProfile(): bool;
    public function getAnonymousProfile(): string;
    public function getIntergroupPosition(): int;
    public function getIntergroupPositionRotation(): string;
    public function getHomeGroup(): int;
    public function isGSR(): bool;
    public function getMeetingPO(): mixed;
    public function getPersonalEmail(): string;
    public function getMobileNumber(): string;
    public function isTwelfthStepper(): bool;
    public function getArea(): string;
    public function getAccepts(): array;
    public function isGdprAccepted(): bool;
    public function getGdprAcceptedAt(): string;
    public function getGdprAcceptanceVersion(): string;
    public function getGdprAcceptanceMethod(): string;
    public function getGdprAcceptanceStatement(): string;
    public function getUpdated(): string;
}

interface MemberRepository
{
    public function findById(int $id): ?Member;
    public function findByEmail(string $email): ?Member;
    public function findAll(array $args = []): array;
    public function count(array $args = []): int;
    public function create(string $anonymousName): int;
    public function save(Member $member): bool;
    public function delete(int $id): bool;
    public function update(Member $member): bool;
}
PHP
    );
}

// Minimal WP REST shims. The real classes ship with WordPress; we
// only need enough surface area to exercise the REST controllers
// directly from unit tests (parameter access, response construction,
// status + payload inspection). Guarded with class_exists/
// function_exists so a richer environment can supply the real thing.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = []) {}
        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(
            private mixed $data = null,
            private int $status = 200,
        ) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function set_status(int $status): void { $this->status = $status; }
    }
}
if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }
}
if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        if ($response instanceof \WP_REST_Response || $response instanceof \WP_Error) {
            return $response;
        }
        return new \WP_REST_Response($response, 200);
    }
}
// register_rest_route / add_action are called from controllers'
// register() bootstrap, which the unit tests do not exercise.
// Stubs here just keep them callable in case a future test does.
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = []): bool { return true; }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return false; }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool { return false; }
}
