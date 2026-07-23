<?php

declare(strict_types=1);

/**
 * Test bootstrap for Reach.
 */

// Composer autoloader — brings in WP_Mock (10up/wp_mock 1.1.1), Mockery and
// Patchwork. The suite is predominantly built on the hand-rolled WordPress
// function stubs and in-memory fakes defined below (they keep the fast,
// dependency-light unit tests the plugin has always had), but WP_Mock is
// available for tests that want to assert on WordPress function calls
// directly. Guarded so the suite still loads with a clear message if
// `composer install` has not been run.
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoloader)) {
    require_once $autoloader;
}

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

// wpdb output modes — defined in wp-db.php / wp-includes/class-wpdb.php.
// WpdbCallAttemptRepository passes these to $wpdb->get_results().
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// In-memory transient + options store for tests.
$GLOBALS['__reach_transients'] = [];
$GLOBALS['__reach_options'] = [];
// Outbound mail spool captured by the wp_mail() stub below.
$GLOBALS['__reach_mail'] = [];
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
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        return $GLOBALS['__reach_bloginfo'][$show] ?? '';
    }
}
if (!function_exists('wp_mail')) {
    // Spool sent mail so tests can assert on the reset link etc. Returns
    // the value in $GLOBALS['__reach_mail_return'] (default true) so a send
    // failure can be simulated.
    function wp_mail($to, $subject, $message, $headers = '', $attachments = []): bool {
        $GLOBALS['__reach_mail'][] = [
            'to'      => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ];
        return $GLOBALS['__reach_mail_return'] ?? true;
    }
}
if (!function_exists('is_ssl')) {
    function is_ssl(): bool { return false; }
}
// URL helpers. The OAuth controller builds its provider callback URL
// via rest_url() and its page URLs via home_url(); the controller
// tests that drive callback() need these callable. Shapes mirror
// WordPress closely enough for assertions on the resulting strings.
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.test/' . ltrim($path, '/');
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(string $key, string $value, string $url): string {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value);
    }
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
namespace Unity\Members;

enum ResponderCertification: string
{
    case None = 'None';
    case Applied = 'Applied';
    case InTraining = 'In Training';
    case Pending = 'Pending';
    case Certified = 'Certified';
}

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
    public function isTelephoneResponder(): bool;
    public function getResponderCertification(): \Unity\Members\ResponderCertification;
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
    public function findTelephoneResponders(): array;
    public function count(array $args = []): int;
    public function create(string $anonymousName): int;
    public function save(Member $member): bool;
    public function delete(int $id): bool;
    public function update(Member $member): bool;
}
PHP
    );
}

// Unity container + member-view interfaces. ReachServiceProvider registers
// its services against Unity\Core\Interfaces\Container, and a few admin-page
// factories type-hint Unity\Members\Interfaces\MemberViewFactory. Load them
// from the sibling Unity checkout, or fall back to minimal stubs.
$containerInterface = $unityPath . '/src/Core/Interfaces/Container.php';
if (file_exists($containerInterface)) {
    require_once $containerInterface;
} elseif (!interface_exists(\Unity\Core\Interfaces\Container::class)) {
    eval(<<<'PHP'
namespace Unity\Core\Interfaces;

use Psr\Container\ContainerInterface;

interface Container extends ContainerInterface
{
    public function register(string $id, callable $factory): void;
    public function get(string $id): mixed;
}
PHP
    );
}

$viewFactoryInterface = $unityPath . '/src/Members/Interfaces/MemberViewFactory.php';
if (file_exists($viewFactoryInterface)) {
    require_once $viewFactoryInterface;
} elseif (!interface_exists(\Unity\Members\Interfaces\MemberViewFactory::class)) {
    eval(<<<'PHP'
namespace Unity\Members\Interfaces;

interface MemberViewFactory
{
    public function createFromSource(array $sourceIds): array;
}
PHP
    );
}

// Scrutiny interfaces. NearestMembersController and PasswordAuthController
// typehint Scrutiny\Audit\Interfaces\AuditLogger. Load it from a sibling
// Scrutiny checkout (SCRUTINY_PATH overrides the default location), or fall
// back to a minimal stub so the suite runs without Scrutiny checked out.
$scrutinyPath   = getenv('SCRUTINY_PATH') ?: dirname(__DIR__, 2) . '/scrutiny';
$auditInterface = $scrutinyPath . '/src/Audit/Interfaces/AuditLogger.php';
if (file_exists($auditInterface)) {
    require_once $auditInterface;
} elseif (!interface_exists(\Scrutiny\Audit\Interfaces\AuditLogger::class)) {
    eval(<<<'PHP'
namespace Scrutiny\Audit\Interfaces;

interface AuditLogger
{
    public const ACTION_VIEW = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_EXPORT = 'export';
    public const ACTION_IMPORT = 'import';
    public const ACTION_CALL = 'call';
    public const ACTION_MESSAGE = 'message';

    public const ENTITY_MEMBER = 'member';
    public const ENTITY_GROUP = 'group';
    public const ENTITY_MEETING = 'meeting';
    public const ENTITY_POSITION = 'position';

    public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ''): void;
    public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ''): void;
}
PHP
    );
}

// Scrutiny\Privacy\PersonalDataFields. CallAttemptController references the
// MOBILE_NUMBER field constant when auditing a call. Load from a sibling
// Scrutiny checkout, or fall back to a minimal stub carrying the one constant
// the controller uses.
$privacyClass = ($scrutinyPath ?? (dirname(__DIR__, 2) . '/scrutiny'))
    . '/src/Privacy/PersonalDataFields.php';
if (file_exists($privacyClass)) {
    require_once $privacyClass;
} elseif (!class_exists(\Scrutiny\Privacy\PersonalDataFields::class)) {
    eval(<<<'PHP'
namespace Scrutiny\Privacy;

class PersonalDataFields
{
    public const MOBILE_NUMBER = 'mobile_number';
    public const PERSONAL_EMAIL = 'personal_email';
}
PHP
    );
}

// Scrutiny\Privacy\PersonalDataPolicy. The Reach admin pages gate on its
// VIEW_CAPABILITY constant. Stub the capability constants they reference.
if (!class_exists(\Scrutiny\Privacy\PersonalDataPolicy::class)) {
    eval(<<<'PHP'
namespace Scrutiny\Privacy;

class PersonalDataPolicy
{
    public const VIEW_CAPABILITY = 'scrutiny_view_personal_data';
    public const EDIT_CAPABILITY = 'scrutiny_edit_personal_data';
}
PHP
    );
}

// Additional WordPress function stubs used by the controllers, geocoder and
// mailers under test. Each is guarded so a richer WordPress-loaded
// environment supplies the real implementation instead.
if (!function_exists('do_action')) {
    // Spool fired actions so tests can assert an extension point ran.
    $GLOBALS['__reach_actions'] = [];
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['__reach_actions'][] = ['hook' => $hook, 'args' => $args];
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        $value = is_string($value) ? $value : '';
        // Collapse whitespace and strip tags — enough of WP's behaviour for
        // the assertions the tests make.
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?? '';
        return trim($value);
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value): string
    {
        // Like sanitize_text_field but keeps line breaks.
        $value = is_string($value) ? $value : '';
        return trim(strip_tags($value));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key): string
    {
        $key = is_string($key) ? strtolower($key) : '';
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}
if (!function_exists('absint')) {
    function absint($value): int
    {
        return abs((int) $value);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url): string
    {
        return filter_var((string) $url, FILTER_SANITIZE_URL) ?: '';
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url): string
    {
        return filter_var((string) $url, FILTER_SANITIZE_URL) ?: '';
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('wp_safe_redirect')) {
    // Record redirects so PageRouter tests can assert on the target without
    // actually issuing headers.
    $GLOBALS['__reach_redirects'] = [];
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['__reach_redirects'][] = ['location' => $location, 'status' => $status];
        return true;
    }
}
if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect(string $location, string $fallback = ''): string
    {
        // Allow same-host and relative URLs; otherwise fall back — a coarse
        // stand-in for WP's allowed-hosts check, enough for the tests.
        if ($location === '' || str_starts_with($location, '/')) {
            return $location !== '' ? $location : $fallback;
        }
        $host = parse_url($location, PHP_URL_HOST);
        return $host === 'example.test' ? $location : $fallback;
    }
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
        public function get_route(): string
        {
            return (string) ($this->params['__route'] ?? '');
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        private array $headers = [];

        public function __construct(
            private mixed $data = null,
            private int $status = 200,
        ) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function set_status(int $status): void { $this->status = $status; }
        public function header(string $key, string $value): void { $this->headers[$key] = $value; }
        /** @return array<string, string> */
        public function get_headers(): array { return $this->headers; }
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
    // Capture the registered route definitions so tests can assert on them
    // and exercise the inline sanitize_callback / validate_callback closures.
    $GLOBALS['__reach_routes'] = [];
    function register_rest_route(string $namespace, string $route, array $args = []): bool
    {
        $GLOBALS['__reach_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
        return true;
    }
}
if (!function_exists('add_action')) {
    // Record the callback so tests can fire a registered hook (e.g. a
    // controller's rest_api_init handler, or Plugin's member_deleted closure).
    $GLOBALS['__reach_hooks'] = [];
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__reach_hooks'][$hook][] = $callback;
        return true;
    }
}
if (!function_exists('add_filter')) {
    $GLOBALS['__reach_filters'] = [];
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__reach_filters'][$hook][] = $callback;
        return true;
    }
}
if (!function_exists('is_admin')) {
    // Flag-driven so a test can exercise the admin-only branch of Plugin::init.
    function is_admin(): bool { return (bool) ($GLOBALS['__reach_is_admin'] ?? false); }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []) { return $GLOBALS['__reach_cron'][$hook] ?? false; }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        unset($GLOBALS['__reach_cron'][$hook]);
        return 0;
    }
}
if (!function_exists('dbDelta')) {
    // The Wpdb repositories call dbDelta() from their install() routines. The
    // real one diffs and applies schema; tests only need to confirm install()
    // reaches it without touching a database, so record the SQL and return.
    $GLOBALS['__reach_dbdelta'] = [];
    function dbDelta($queries = '', bool $execute = true): array
    {
        $GLOBALS['__reach_dbdelta'][] = $queries;
        return [];
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return false; }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool { return false; }
}

// Bring WP_Mock online for the tests that use it. Done last, after every
// hand-rolled stub above is already defined: WP_Mock only intercepts the
// functions a test explicitly declares with WP_Mock::userFunction(), so the
// plain stubs remain the default behaviour for everything else and the
// existing fake-based tests are unaffected.
if (class_exists(\WP_Mock::class)) {
    \WP_Mock::bootstrap();
}
