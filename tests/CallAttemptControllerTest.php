<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\Rest\CallAttemptController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/PasswordAuthenticatorTest.php';
require_once __DIR__ . '/PasswordAuthControllerGateTest.php'; // NullAuditLogger

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
final class CallAttemptControllerTest extends TestCase
{
    private AttemptTokenMinter $minter;

    protected function setUp(): void
    {
        $this->minter = new AttemptTokenMinter();
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    public function testPermissionCallbackRejectsWhenNoSession(): void
    {
        $result = $this->makeController()->permissionCallback();
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->data['status'] ?? null);
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
        $this->assertSame(403, $result->data['status'] ?? null);
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
        $members = new PwTestMemberRepository([new PwTestMember('viewer@example.com', true, true, 1)]);
        $controller = $this->makeController($repo, $audit, $members);

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

    public function testAuditFallsBackToUnknownCallerWhenViewerHasNoMember(): void
    {
        $this->seedSession('ghost@example.com');
        $repo  = new SpyCallAttemptRepository();
        $audit = new SpyAuditLogger();
        $controller = $this->makeController($repo, $audit, new PwTestMemberRepository([]));

        $token = $this->minter->mint('ghost@example.com', 7, time());

        $controller->create($this->request([
            'member_id'     => 7,
            'outcome'       => CallAttempt::OUTCOME_NO_ANSWER,
            'attempt_token' => $token,
        ]));

        $detail = $audit->batches[0]['detail'];
        // No identifier invented for an unresolved caller; result still shown.
        $this->assertStringContainsString('caller:unknown', $detail);
        $this->assertStringContainsString('result:No Answer', $detail);
    }

    // --- helpers ----------------------------------------------------------

    private function makeController(
        ?CallAttemptRepository $repo = null,
        ?AuditLogger $audit = null,
        ?MemberRepository $members = null
    ): CallAttemptController {
        return new CallAttemptController(
            $repo ?? new SpyCallAttemptRepository(),
            $this->minter,
            new CurrentSession(new SessionCookie()),
            $audit ?? new NullAuditLogger(),
            $members ?? new PwTestMemberRepository([]),
        );
    }

    /** @param array<string, mixed> $params */
    private function request(array $params = []): WP_REST_Request
    {
        return new WP_REST_Request($params + [
            'member_id'     => 1,
            'outcome'       => CallAttempt::OUTCOME_REACHED,
            'attempt_token' => '',
            'note'          => '',
        ]);
    }

    private function seedSession(string $email, string $provider = 'google'): void
    {
        $session = new Session($email, $provider, 'sub-1', time(), time() + 3600);
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);
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

/** Captures logBatch() calls for assertions on the audit shape. */
final class SpyAuditLogger implements AuditLogger
{
    /** @var array<int, array<string, mixed>> */
    public array $batches = [];

    public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ''): void
    {
    }

    public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ''): void
    {
        $this->batches[] = compact('action', 'entityType', 'entityId', 'fieldNames', 'detail');
    }
}
