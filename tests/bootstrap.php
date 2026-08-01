<?php

declare(strict_types=1);

/**
 * Test bootstrap for Reach.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions of its own — here, only
 * dbDelta() — must stay after the Bootstrap::load() call, not before it.
 *
 * This file used to carry all of that itself, and said so:
 *
 *     If a test here ever does need to assert on WordPress function calls, the
 *     suite-wide answer is bleedingdeacons/wp-mocks (Brain Monkey underneath).
 *     Adding it would mean loading Patchwork before the stubs above, since
 *     Patchwork cannot redefine a function whose file was included first.
 *
 * That is exactly what happened. What remains here is the part that is not
 * WordPress: the sibling-plugin interface loading, dbDelta(), and Reach's own
 * constants.
 *
 * Groups: `wordpress`, plus `rest` because the controller tests drive route
 * callbacks with WP_REST_Request/WP_REST_Response, plus `sentinel` so
 * HasLogger's resolution path runs rather than being skipped by its
 * function_exists('wp_log') guard. Not `acf` — Reach does not use it.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoloader)) {
    require_once $autoloader;
}

Bootstrap::load(['wordpress', 'rest', 'sentinel']);

// Makes plugins_url()/plugin_dir_url() answer with Reach's own path.
WpState::$pluginSlug = 'reach';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Constants describing a particular installation are left to the consuming
// plugin by design, so the cookie ones live here. The *_IN_SECONDS family and
// the $wpdb output modes (OBJECT, ARRAY_A, ARRAY_N) come from wp-mocks.
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
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

// Unity. The resolver, the controllers and several admin classes type-hint
// Unity\Members\Interfaces\{Member, MemberRepository, MemberViewFactory} and
// Unity\Core\Interfaces\Container, and the fixtures extend the test doubles
// Unity ships at Unity\Testing\Doubles. A PSR-4 autoloader over the sibling
// checkout covers all of it; UNITY_PATH overrides the default location.
//
// This used to load three named interface files and, failing that, eval() a
// hand-written copy of Member, MemberRepository, Container, MemberViewFactory
// and ResponderCertification so the suite would run from a bare clone. That
// fallback is gone. Its own comment described the failure mode it caused --
// "tests that rely on richer Unity behaviour should set UNITY_PATH" -- and a
// copy of a contract owned elsewhere, never exercised in CI (which always
// checks Unity out), is exactly how this suite came to be broken before. It
// could not supply the doubles in any case.
$unityPath = getenv('UNITY_PATH') ?: dirname(__DIR__, 2) . '/unity';
$unitySrc  = $unityPath . '/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . "Reach is built on Unity's interfaces and test doubles, so the Unity plugin" . PHP_EOL
        . 'must be checked out as a sibling directory (or UNITY_PATH set) for this' . PHP_EOL
        . 'suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }

    $file = $unitySrc . '/' . str_replace('\\', '/', substr($class, strlen('Unity\\'))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

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

// dbDelta() is the one WordPress function still defined here: it lives in
// wp-admin/includes rather than the loaded core, so no shared stub group
// covers it. The Wpdb repositories call it from their install() routines, and
// the tests only need to confirm install() reaches it without touching a
// database — so record the SQL and return. LifeLines keeps its own for the
// same reason.
$GLOBALS['__reach_dbdelta'] = [];
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true): array
    {
        $GLOBALS['__reach_dbdelta'][] = $queries;

        return [];
    }
}
