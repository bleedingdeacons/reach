<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestMailer;
use Reach\CallRequests\CallRequestRepository;
use Reach\Core\Settings;
use Reach\Rest\CallRequestController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Reuse the Member/MemberRepository fakes rather than redeclaring them.
require_once __DIR__ . '/PasswordAuthenticatorTest.php';

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
final class CallRequestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_options'] = [];
        $GLOBALS['__reach_actions'] = [];
        $GLOBALS['__reach_mail'] = [];
        unset($GLOBALS['__reach_mail_return']);
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__reach_mail_return']);
        $_COOKIE = [];
    }

    public function testPermissionCallbackRejectsWhenNoSession(): void
    {
        $controller = $this->makeController();

        $result = $controller->permissionCallback();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_authenticated', $result->get_error_code());
        $this->assertSame(401, $result->data['status'] ?? null);
    }

    public function testPermissionCallbackAllowsWithSession(): void
    {
        $this->seedSession('r@example.com');
        $controller = $this->makeController();

        $this->assertTrue($controller->permissionCallback());
    }

    public function testCreateReturns401WhenSessionExpiredBetweenChecks(): void
    {
        // No cookie seeded: create()'s defensive re-check must fire.
        $controller = $this->makeController();

        $result = $controller->create($this->request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->data['status'] ?? null);
    }

    public function testCreateRecordsTrackingRowAndMailsCallerDetails(): void
    {
        $this->seedSession('responder@example.com', 'google');
        $repo = new SpyCallRequestRepository();
        // PwTestMember resolves getAnonymousName() to 'Test', so that is the
        // responder identifier stored on the (non-identifying) tracking row.
        $members = new PwTestMemberRepository([
            new PwTestMember('responder@example.com'),
        ]);
        $settings = new Settings();
        $settings->setCallRequestEmail('ops@example.com');
        $controller = $this->makeController($repo, $settings, $members);

        $result = $controller->create($this->request([
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
        $this->assertCount(1, $GLOBALS['__reach_mail']);
        $message = (string) $GLOBALS['__reach_mail'][0]['message'];
        $this->assertStringContainsString('Sam', $message);
        $this->assertStringContainsString('07700 900123', $message);
        $this->assertSame('ops@example.com', $GLOBALS['__reach_mail'][0]['to']);

        // Extension hook fired with the record (and no PII on it).
        $this->assertSame('reach/call_request_created', $GLOBALS['__reach_actions'][0]['hook'] ?? null);
    }

    public function testCreateRollsBackTheRowWhenMailFails(): void
    {
        $this->seedSession('responder@example.com');
        $repo = new SpyCallRequestRepository();
        // wp_mail returns false → send() fails after the row was written.
        $GLOBALS['__reach_mail_return'] = false;
        $settings = new Settings();
        $settings->setCallRequestEmail('ops@example.com');
        $controller = $this->makeController($repo, $settings);

        $result = $controller->create($this->request([
            'gender'       => 'male',
            'area'         => 'BS3',
            'caller_name'  => 'Alex',
            'caller_phone' => '07700 900999',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_call_request_not_sent', $result->get_error_code());
        $this->assertSame(502, $result->data['status'] ?? null);

        // The orphan tracking row must have been deleted.
        $this->assertSame([1], $repo->deleted, 'the tracking row must be rolled back on mail failure');
        // No extension hook on the failure path.
        $this->assertEmpty($GLOBALS['__reach_actions']);
    }

    public function testCreateRejectsWhitespaceOnlyCallerDetails(): void
    {
        $this->seedSession('responder@example.com');
        $controller = $this->makeController();

        $result = $controller->create($this->request([
            'gender'       => 'male',
            'area'         => '   ',
            'caller_name'  => '   ',
            'caller_phone' => '   ',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_missing_caller_details', $result->get_error_code());
        $this->assertSame(400, $result->data['status'] ?? null);
    }

    public function testResponderNameFallsBackToEmailWhenNoMemberRecord(): void
    {
        $this->seedSession('stranger@example.com');
        $repo = new SpyCallRequestRepository();
        $settings = new Settings();
        $settings->setCallRequestEmail('ops@example.com');
        // Empty member repo: no anonymous name to resolve, so the email is
        // stored as the responder identifier.
        $controller = $this->makeController($repo, $settings, new PwTestMemberRepository([]));

        $controller->create($this->request([
            'gender'       => 'female',
            'area'         => 'BS1',
            'caller_name'  => 'Jo',
            'caller_phone' => '07700 900001',
        ]));

        $this->assertSame('stranger@example.com', $repo->created[0]['responderName']);
    }

    // --- helpers ----------------------------------------------------------

    private function makeController(
        ?CallRequestRepository $repo = null,
        ?Settings $settings = null,
        ?MemberRepository $members = null
    ): CallRequestController {
        $settings = $settings ?? new Settings();
        return new CallRequestController(
            $repo ?? new SpyCallRequestRepository(),
            new CurrentSession(new SessionCookie()),
            $members ?? new PwTestMemberRepository([]),
            new CallRequestMailer($settings),
        );
    }

    /** @param array<string, mixed> $params */
    private function request(array $params = []): WP_REST_Request
    {
        return new WP_REST_Request($params + [
            'gender'       => 'male',
            'area'         => 'BS5',
            'caller_name'  => 'Caller',
            'caller_phone' => '07700 900000',
            'note'         => '',
        ]);
    }

    private function seedSession(string $email, string $provider = 'google'): void
    {
        $session = new Session($email, $provider, 'sub-123', time(), time() + 3600);
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);
    }
}

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
