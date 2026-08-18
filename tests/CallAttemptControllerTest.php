<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Tests\ReachTestCase;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\Rest\CallAttemptController;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

require_once __DIR__ . '/PasswordAuthenticatorTest.php';
require_once __DIR__ . '/PasswordAuthControllerGateTest.php'; // SpyAuditLogger

/**
 * Tests for {@see CallAttemptController}.
 *
 * The controller's security job is the attempt-token gate: a signed-in user
 * may only log an attempt against a member who was actually shown to *them*,
 * proven by an {@see AttemptTokenMinter} token binding (viewer email, member
 * id). These tests drive the happy path, every rejection (no session, forged
 * / wrong-member / wrong-viewer token), and the privacy shape of the audit
 * entry: the caller's anonymous name and the result — never their email or
 * provider, and never the free-text note.
 */
final class CallAttemptControllerTest extends ReachTestCase
{
    private AttemptTokenMinter $minter;

    /** The session seeded for this test, or null when signed out. */
    private ?Session $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->minter = new AttemptTokenMinter();
        $this->session = null;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }

    public function testPermissionCallbackRejectsWhenNoSession(): void
    {
        $result = $this->makeController()->permissionCallback();
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testCreateRejectsInvalidAttemptToken(): void
    {
        $this->seedSession('viewer@example.com');
        $controller = $this->makeController();

        $result = $controller->create($this->request([
            'member_id'     => 42,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => 'obviously-not-a-valid-token',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_attempt_token', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    public function testCreateRejectsTokenMintedForADifferentMember(): void
    {
        $this->seedSession('viewer@example.com');
        $controller = $this->makeController();

        // Token binds member 99, but the request targets member 42.
        $token = $this->minter->mint('viewer@example.com', 99, time());

        $result = $controller->create($this->request([
            'member_id'     => 42,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => $token,
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_attempt_token', $result->get_error_code());
    }

    public function testCreateRejectsTokenMintedForADifferentViewer(): void
    {
        $this->seedSession('viewer@example.com');
        $controller = $this->makeController();

        // Token was minted for a different viewer — must not be usable by
        // this session even though the member id matches.
        $token = $this->minter->mint('someone-else@example.com', 42, time());

        $result = $controller->create($this->request([
            'member_id'     => 42,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => $token,
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_attempt_token', $result->get_error_code());
    }

    public function testCreateRecordsAttemptAndWritesPrivacyPreservingAudit(): void
    {
        $this->seedSession('viewer@example.com', 'google');
        $repo  = new SpyCallAttemptRepository();
        $audit = new SpyAuditLogger();
        // The viewer resolves to a member (anonymous name 'Test', id 1).
        $controller = $this->makeController($repo, $audit, new MemberStub('viewer@example.com', true, true, 1));

        $token = $this->minter->mint('viewer@example.com', 42, time());

        $result = $controller->create($this->request([
            'member_id'     => 42,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => $token,
            'note'          => 'left a voicemail',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertTrue($result->get_data()['recorded']);

        // The attempt was recorded against the right member with the note.
        $this->assertCount(1, $repo->recorded);
        $this->assertSame(42, $repo->recorded[0]['memberId']);
        $this->assertSame('left a voicemail', $repo->recorded[0]['note']);

        // Exactly one audit entry, a CALL against member 42's mobile number.
        $this->assertCount(1, $audit->batches);
        $entry = $audit->batches[0];
        $this->assertSame(AuditLogger::ACTION_CALL, $entry['action']);
        $this->assertSame(42, $entry['entityId']);

        // The audit detail names the caller and result but NEVER leaks the
        // caller's email, provider, or the private note.
        $detail = $entry['detail'];
        $this->assertStringContainsString('caller:Test#1', $detail);
        $this->assertStringContainsString('result:Spoke', $detail);
        $this->assertStringNotContainsString('viewer@example.com', $detail);
        $this->assertStringNotContainsString('google', $detail);
        $this->assertStringNotContainsString('voicemail', $detail);
    }

    public function testAuditFallsBackToUnknownCallerWhenViewerHasNoAnonymousName(): void
    {
        $this->seedSession('ghost@example.com');
        $repo  = new SpyCallAttemptRepository();
        $audit = new SpyAuditLogger();
        // Eligible, so the request is allowed through, but carrying no
        // anonymous name to put in the audit row. This used to be
        // written as a viewer matching no member at all; that case can
        // no longer reach the audit step, because CurrentSession now
        // refuses it outright — see the test below.
        $controller = $this->makeController($repo, $audit, new MemberStub('ghost@example.com', anonymousName: ''));

        $token = $this->minter->mint('ghost@example.com', 7, time());

        $controller->create($this->request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_NO_ANSWER,
            'attempt_token' => $token,
        ]));

        $detail = $audit->batches[0]['detail'];
        // No identifier invented for an unnamed caller; result still shown.
        $this->assertStringContainsString('caller:unknown', $detail);
        $this->assertStringContainsString('result:No Answer', $detail);
    }

    /**
     * A signed cookie whose email matches no member is not a session
     * this controller will act on. The signature proves who signed in;
     * it says nothing about whether they may still use Reach, and the
     * member record is where that answer lives.
     */
    public function testRejectsSessionWhoseEmailMatchesNoMember(): void
    {
        $this->seedSession('ghost@example.com');
        $repo = new SpyCallAttemptRepository();

        $controller = new CallAttemptController(
            $repo,
            $this->minter,
            $this->currentSessionWith($this->session, null),
            new SpyAuditLogger(),
            new SessionCsrf(),
        );

        $this->assertInstanceOf(WP_Error::class, $controller->permissionCallback());

        $result = $controller->create($this->request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_NO_ANSWER,
            'attempt_token' => $this->minter->mint('ghost@example.com', 7, time()),
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $repo->recorded, 'nothing should have been recorded');
    }

    /**
     * A member who has been de-flagged since signing in loses access at
     * their next request, not whenever their cookie happens to expire.
     * This is the whole point of re-checking eligibility per request.
     */
    public function testRejectsSessionWhoseMemberIsNoLongerEligible(): void
    {
        $this->seedSession('lapsed@example.com');
        $repo = new SpyCallAttemptRepository();

        $ineligible = new MemberStub(
            'lapsed@example.com',
            twelfthStepper: false,
            telephoneResponder: false,
        );

        $controller = new CallAttemptController(
            $repo,
            $this->minter,
            $this->currentSessionWith($this->session, $ineligible),
            new SpyAuditLogger(),
            new SessionCsrf(),
        );

        $result = $controller->create($this->request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_NO_ANSWER,
            'attempt_token' => $this->minter->mint('lapsed@example.com', 7, time()),
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $repo->recorded, 'nothing should have been recorded');
    }

    /**
     * The cookie alone must not be enough to write. Without the
     * anti-CSRF header the request is refused even though the session
     * is perfectly valid.
     */
    public function testRejectsWriteWithoutTheSessionToken(): void
    {
        $this->seedSession('viewer@example.com');
        $repo = new SpyCallAttemptRepository();
        $controller = $this->makeController($repo);

        $token = $this->minter->mint('viewer@example.com', 7, time());

        // Deliberately built without the header the helper adds.
        $request = new WP_REST_Request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => $token,
            'note'          => '',
        ]);

        $result = $controller->create($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_session_token', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $repo->recorded, 'nothing should have been recorded');
    }

    /** A token minted for a different session does not work either. */
    public function testRejectsWriteWithAnotherSessionsToken(): void
    {
        $this->seedSession('viewer@example.com');
        $repo = new SpyCallAttemptRepository();
        $controller = $this->makeController($repo);

        $other = new Session('viewer@example.com', 'google', 'sub-1', time(), time() + 3600, null, Session::newId());

        $request = $this->withSessionToken(new WP_REST_Request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => $this->minter->mint('viewer@example.com', 7, time()),
            'note'          => '',
        ]), $other);

        $result = $controller->create($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_session_token', $result->get_error_code());
        $this->assertSame([], $repo->recorded, 'nothing should have been recorded');
    }

    // --- helpers ----------------------------------------------------------

    private function makeController(
        ?CallAttemptRepository $repo = null,
        ?AuditLogger $audit = null,
        ?Member $viewer = null
    ): CallAttemptController {
        // The viewer's own member record, which CurrentSession resolves
        // to authorise the request and the controller then names in the
        // audit row. Defaults to an eligible member for the seeded
        // email; pass one explicitly to vary it.
        $viewer ??= new MemberStub($this->session?->email ?? 'viewer@example.com');

        $current = $this->session !== null
            ? $this->currentSessionWith($this->session, $viewer)
            : $this->currentSessionSignedOut();

        return new CallAttemptController(
            $repo ?? new SpyCallAttemptRepository(),
            $this->minter,
            $current,
            $audit ?? new SpyAuditLogger(),
            new SessionCsrf(),
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * Carries the anti-CSRF header for the seeded session: every test
     * here is about some *other* gate, so the request has to clear this
     * one to reach it. The token's own gate has its own tests.
     */
    private function request(array $params = []): WP_REST_Request
    {
        $request = new WP_REST_Request($params + [
            'member_id'     => 1,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => '',
            'note'          => '',
        ]);

        return $this->session !== null
            ? $this->withSessionToken($request, $this->session)
            : $request;
    }

    private function seedSession(string $email, string $provider = 'google'): void
    {
        $this->session = new Session($email, $provider, 'sub-1', time(), time() + 3600, null, Session::newId());
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($this->session);
    }
}

/** In-memory {@see CallAttemptRepository} recording what it was asked to store. */
final class SpyCallAttemptRepository implements CallAttemptRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $recorded = [];
    private int $nextId = 1;

    public function record(int $memberId, string $viewerEmail, string $viewerProvider, string $outcome, ?string $note, int $now): CallAttempt
    {
        $id = $this->nextId++;
        $this->recorded[] = compact('memberId', 'viewerEmail', 'viewerProvider', 'outcome', 'note', 'now');
        return new CallAttempt($id, $memberId, $viewerEmail, $viewerProvider, $outcome, $note, $now);
    }

    public function forMembersSince(array $memberIds, int $sinceSeconds, int $now): array
    {
        return [];
    }

    public function list(array $filters, int $limit, int $offset): array
    {
        return [];
    }

    public function countWhere(array $filters): int
    {
        return count($this->recorded);
    }

    public function findById(int $id): ?CallAttempt
    {
        return null;
    }
}
