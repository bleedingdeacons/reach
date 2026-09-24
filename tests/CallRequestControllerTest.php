<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Core\RateLimiter;
use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestMailer;
use Reach\CallRequests\CallRequestRepository;
use Reach\Core\Settings;
use Reach\Rest\CallRequestController;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;

/**
 * Tests for {@see CallRequestController}.
 *
 * The security-relevant behaviours here are: no session ⇒ 401 (both at the
 * permission gate and defensively inside create()); the caller's PII is
 * mailed, never persisted; and when the mail fails the tracking row is
 * rolled back so no orphan record survives. The controller depends on the
 * concrete (final) CurrentSession, so tests seed a genuine signed cookie and
 * read it back through a real SessionCookie rather than mocking the session.
 */

/**
 * Exhaust the per-session budget through the same RateLimiter and bucket
 * the controller uses, rather than reaching into its internals.
 */
function spendTheCallRequestBudget(string $email): void
{
    $limiter = new RateLimiter();
    for ($i = 0; $i < 11; $i++) {
        $limiter->overLimit('callreq:' . $email, 10, 300);
    }
}

beforeEach(function () {
    /** The session seeded for this test, or null when signed out. */
    $this->session = null;

    // --- helpers ----------------------------------------------------------
    $this->makeController = function (
        ?CallRequestRepository $repo = null,
        ?Settings $settings = null,
        ?Member $responder = null
    ): CallRequestController {
        $settings = $settings ?? new Settings();

        // The responder's own member record, which CurrentSession
        // resolves to authorise the request and the controller then
        // names on the tracking row.
        $responder ??= new MemberStub($this->session?->email ?? 'responder@example.com');

        $current = $this->session !== null
            ? $this->currentSessionWith($this->session, $responder)
            : $this->currentSessionSignedOut();

        return new CallRequestController(
            $repo ?? new SpyCallRequestRepository(),
            $current,
            new CallRequestMailer($settings),
            new SessionCsrf(),
            new RateLimiter(),
        );
    };

    /**
     * @param array<string, mixed> $params
     *
     * Carries the anti-CSRF header for the seeded session: every test
     * here is about some other gate, so the request has to clear this
     * one to reach it.
     */
    $this->request = function (array $params = []): WP_REST_Request {
        $request = new WP_REST_Request($params + [
            'gender'       => 'male',
            'area'         => 'BS5',
            'caller_name'  => 'Caller',
            'caller_phone' => '07700 900000',
            'note'         => '',
        ]);

        return $this->session !== null
            ? $this->withSessionToken($request, $this->session)
            : $request;
    };

    $this->seedSession = function (string $email, string $provider = 'google'): void {
        $this->session = new Session($email, $provider, 'sub-123', time(), time() + 3600, null, Session::newId());
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($this->session);
    };

    WpState::$options = [];
    WpState::$mail = [];
    WpState::$mailResult = true;
    $this->session = null;
    $_COOKIE = [];
});

afterEach(function () {
    WpState::$mailResult = true;
    $_COOKIE = [];
});

test('permission callback rejects when no session', function () {
    $controller = ($this->makeController)();

    $result = $controller->permissionCallback();

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_not_authenticated', $result->get_error_code());
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

test('permission callback allows with session', function () {
    ($this->seedSession)('r@example.com');
    $controller = ($this->makeController)();

    $this->assertTrue($controller->permissionCallback());
});

test('create returns401 when session expired between checks', function () {
    // No cookie seeded: create()'s defensive re-check must fire.
    $controller = ($this->makeController)();

    $result = $controller->create(($this->request)());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

// --- throttle -------------------------------------------------------
test('create is throttled per session', function () {
    // This was the one authenticated write in Reach with no throttle.
    // AlertController::raise() is capped and getNearest() is capped;
    // create() was not, and it mails the intergroup for every call, so
    // one signed-in responder — or one client in a retry loop — could
    // flood the mailbox and burn the site's SMTP quota.
    ($this->seedSession)('responder@example.com');
    spendTheCallRequestBudget('responder@example.com');

    $result = ($this->makeController)()->create(($this->request)());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_rate_limited', $result->get_error_code());
    $this->assertSame(429, $result->get_error_data()['status'] ?? null);
});

test('the throttle is per session not site wide', function () {
    // Keyed on the session's email: the caller is authenticated here, so
    // the meaningful unit is the person. An IP bucket would throttle a
    // whole shared connection for one responder's retry loop.
    spendTheCallRequestBudget('someone-else@example.com');

    ($this->seedSession)('responder@example.com');
    $repo = new SpyCallRequestRepository();
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');

    $result = ($this->makeController)($repo, $settings)->create(($this->request)());

    $code = $result instanceof WP_Error ? $result->get_error_code() : '';
    $this->assertNotSame('reach_rate_limited', $code, "One responder's flood must not block another.");
});

test('an ordinary call request is not throttled', function () {
    // The cap has to sit above real usage to be worth having.
    ($this->seedSession)('responder@example.com');
    $repo = new SpyCallRequestRepository();
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');

    $result = ($this->makeController)($repo, $settings)->create(($this->request)());

    $code = $result instanceof WP_Error ? $result->get_error_code() : '';
    $this->assertNotSame('reach_rate_limited', $code);
});

test('create records tracking row and mails caller details', function () {
    ($this->seedSession)('responder@example.com', 'google');
    $repo = new SpyCallRequestRepository();
    // MemberStub resolves getAnonymousName() to 'Test', so that is the
    // responder identifier stored on the (non-identifying) tracking row.
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');
    $controller = ($this->makeController)($repo, $settings, new MemberStub('responder@example.com'));

    $result = $controller->create(($this->request)([
        'gender'       => 'female',
        'area'         => 'BS5 / Easton',
        'caller_name'  => 'Sam',
        'caller_phone' => '07700 900123',
        'note'         => 'prefers evenings',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $data = $result->get_data();
    $this->assertTrue($data['recorded']);
    $this->assertSame('CR-000001', $data['reference']);

    // Tracking row holds only non-identifying data: responder name +
    // area + viewer email/provider. Caller name/phone/note never reach it.
    $this->assertCount(1, $repo->created);
    $created = $repo->created[0];
    $this->assertSame('Test', $created['responderName']);
    $this->assertSame('BS5 / Easton', $created['area']);
    $this->assertStringNotContainsString('Sam', (string) json_encode($created));
    $this->assertStringNotContainsString('900123', (string) json_encode($created));

    // The caller PII is what got mailed — the email is the system of
    // record for it, never the database.
    $this->assertCount(1, WpState::$mail);
    $message = (string) WpState::$mail[0]['message'];
    $this->assertStringContainsString('Sam', $message);
    $this->assertStringContainsString('07700 900123', $message);
    $this->assertSame('ops@example.com', WpState::$mail[0]['to']);

    // Extension hook fired with the record (and no PII on it).
    $this->assertActionFired('reach/call_request_created');
});

test('create rolls back the row when mail fails', function () {
    ($this->seedSession)('responder@example.com');
    $repo = new SpyCallRequestRepository();
    // wp_mail returns false → send() fails after the row was written.
    WpState::$mailResult = false;
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');
    $controller = ($this->makeController)($repo, $settings);

    $result = $controller->create(($this->request)([
        'gender'       => 'male',
        'area'         => 'BS3',
        'caller_name'  => 'Alex',
        'caller_phone' => '07700 900999',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_call_request_not_sent', $result->get_error_code());
    $this->assertSame(502, $result->get_error_data()['status'] ?? null);

    // The orphan tracking row must have been deleted.
    $this->assertSame([1], $repo->deleted, 'the tracking row must be rolled back on mail failure');
    // No extension hook on the failure path.
    $this->assertActionFiredTimes('reach/call_request_created', 0);
});

test('create rejects whitespace only caller details', function () {
    ($this->seedSession)('responder@example.com');
    $controller = ($this->makeController)();

    $result = $controller->create(($this->request)([
        'gender'       => 'male',
        'area'         => '   ',
        'caller_name'  => '   ',
        'caller_phone' => '   ',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_missing_caller_details', $result->get_error_code());
    $this->assertSame(400, $result->get_error_data()['status'] ?? null);
});

test('responder name falls back to email when member has no anonymous name', function () {
    ($this->seedSession)('stranger@example.com');
    $repo = new SpyCallRequestRepository();
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');
    // Eligible, so the request goes through, but with no anonymous
    // name to resolve - so the email is stored as the responder
    // identifier. This was written as an empty member repository
    // until CurrentSession began refusing a session whose email
    // matches no member; see the test below for that case.
    $controller = ($this->makeController)(
        $repo,
        $settings,
        new MemberStub('stranger@example.com', anonymousName: ''),
    );

    $controller->create(($this->request)([
        'gender'       => 'female',
        'area'         => 'BS1',
        'caller_name'  => 'Jo',
        'caller_phone' => '07700 900001',
    ]));

    $this->assertSame('stranger@example.com', $repo->created[0]['responderName']);
});

/**
 * A signed cookie whose email matches no member is refused, and no
 * caller details are mailed anywhere as a result.
 */
test('rejects session whose email matches no member', function () {
    ($this->seedSession)('ghost@example.com');
    $repo = new SpyCallRequestRepository();

    $controller = new CallRequestController(
        $repo,
        $this->currentSessionWith($this->session, null),
        new CallRequestMailer(new Settings()),
        new SessionCsrf(),
        new RateLimiter(),
    );

    $result = $controller->create(($this->request)());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $repo->created);
    $this->assertSame([], WpState::$mail, 'no caller details should have been mailed');
});

/**
 * The cookie alone must not be enough to raise a request - this
 * endpoint mails caller-supplied text to the intergroup, so a
 * forged one is a message somebody acts on.
 */
test('rejects write without the session token', function () {
    ($this->seedSession)('responder@example.com');
    $repo = new SpyCallRequestRepository();
    $controller = ($this->makeController)($repo);

    // Deliberately built without the header the helper adds.
    $result = $controller->create(new WP_REST_Request([
        'gender'       => 'male',
        'area'         => 'BS5',
        'caller_name'  => 'Caller',
        'caller_phone' => '07700 900000',
        'note'         => '',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_session_token', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $repo->created);
    $this->assertSame([], WpState::$mail, 'no caller details should have been mailed');
});

/**
 * In-memory {@see CallRequestRepository} recording what it was asked to
 * create and delete, and minting sequential ids so serial() is meaningful.
 */
final class SpyCallRequestRepository implements CallRequestRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $created = [];
    /** @var int[] */
    public array $deleted = [];
    private int $nextId = 1;

    public function create(string $responderName, string $area, string $viewerEmail, string $viewerProvider, int $createdAt): CallRequest
    {
        $id = $this->nextId++;
        $this->created[] = [
            'id'             => $id,
            'responderName'  => $responderName,
            'area'           => $area,
            'viewerEmail'    => $viewerEmail,
            'viewerProvider' => $viewerProvider,
            'createdAt'      => $createdAt,
        ];
        return new CallRequest($id, $responderName, $area, $viewerEmail, $viewerProvider, $createdAt);
    }

    public function delete(int $id): bool
    {
        $this->deleted[] = $id;
        return true;
    }

    public function list(int $limit, int $offset): array
    {
        return [];
    }

    public function countAll(): int
    {
        return count($this->created);
    }

    public function countPending(): int
    {
        return count($this->created);
    }

    public function findById(int $id): ?CallRequest
    {
        return null;
    }

    public function markCompleted(int $id, int $memberId, string $memberName, int $completedAt): bool
    {
        return true;
    }
}
