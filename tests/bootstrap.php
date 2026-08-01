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
$certificationEnum = $unityPath . '/src/Members/ResponderCertification.php';
if (file_exists($memberInterface) && file_exists($repoInterface)) {
    // Member.php type-hints ResponderCertification (getResponderCertification),
    // so the real enum has to be loaded alongside the interface — it is a
    // separate file and there is no Unity autoloader in the test runtime.
    if (file_exists($certificationEnum)) {
        require_once $certificationEnum;
    }
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
