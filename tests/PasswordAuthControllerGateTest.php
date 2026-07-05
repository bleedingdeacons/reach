<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Core\RateLimiter;
use Reach\Rest\PasswordAuthController;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Reuse the in-memory credential repo + member fakes from the authenticator
// test rather than redeclaring them here.
require_once __DIR__ . '/PasswordAuthenticatorTest.php';

/**
 * Tests for {@see PasswordAuthController} — focused on the two things the
 * controller (rather than the authenticator) is responsible for: the
 * member-eligibility gate, and the generic/again-non-enumerating shape of
 * its responses.
 *
 * The eligibility gate is exercised directly through reflection (mirroring
 * {@see OAuthControllerGateTest}) so we don't drive the session-cookie
 * write, which needs real HTTP headers. The public endpoints are exercised
 * only on their non-cookie-issuing branches (rejections + the always-the-
 * same reset acknowledgement).
 */
final class PasswordAuthControllerGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_mail'] = [];
        // Fresh transient store so the per-IP rate-limit counter doesn't
        // carry between tests.
        $GLOBALS['__reach_transients'] = [];
    }

    // --- eligibility gate -------------------------------------------------

    public function testGateRejectsUnknownEmail(): void
    {
        $controller = $this->controllerWith([]);
        $this->assertNull($this->invokeGate($controller, 'nobody@example.com'));
    }

    public function testGateRejectsMemberWithNeitherRole(): void
    {
        $controller = $this->controllerWith([new PwTestMember('regular@example.com', false, false)]);
        $this->assertNull($this->invokeGate($controller, 'regular@example.com'));
    }

    public function testGateAcceptsTwelfthStepper(): void
    {
        $controller = $this->controllerWith([new PwTestMember('twelfth@example.com', true, false)]);
        $this->assertInstanceOf(Member::class, $this->invokeGate($controller, 'twelfth@example.com'));
    }

    public function testGateAcceptsTelephoneResponder(): void
    {
        $controller = $this->controllerWith([new PwTestMember('responder@example.com', false, true)]);
        $this->assertInstanceOf(Member::class, $this->invokeGate($controller, 'responder@example.com'));
    }

    // --- endpoint rejections (no session issued) --------------------------

    public function testLoginReturnsGeneric401ForUnknownCredentials(): void
    {
        $controller = $this->controllerWith([new PwTestMember('user@example.com')]);

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'user@example.com',
            'password' => 'no-password-set-yet',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_credentials', $result->get_error_code());
        $this->assertSame(401, $result->data['status'] ?? null);
    }

    public function testLoginRejectsCorrectPasswordForIneligibleMember(): void
    {
        // Right password, but the member holds neither outreach role: the
        // controller gate must refuse before any session is minted.
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('ex@example.com', 'correcthorse10');
        $controller = $this->controllerWith(
            [new PwTestMember('ex@example.com', false, false)],
            $repo,
        );

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'ex@example.com',
            'password' => 'correcthorse10',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->data['status'] ?? null);
    }

    public function testRequestResetAlwaysAcknowledgesRegardlessOfAccount(): void
    {
        $controller = $this->controllerWith([]);

        $result = $controller->requestReset(new WP_REST_Request(['email' => 'nobody@example.com']));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['sent'] ?? null);
        // Nothing was actually emailed for a non-member.
        $this->assertCount(0, $GLOBALS['__reach_mail']);
    }

    public function testSetPasswordRejectsWeakPassword(): void
    {
        $controller = $this->controllerWith([new PwTestMember('user@example.com')]);

        // Get a genuinely valid token via the reset request so it's the weak
        // password — not a bad token — that gets rejected (422, not 400).
        $controller->requestReset(new WP_REST_Request(['email' => 'user@example.com']));
        $token = $this->tokenFromLastMail();

        $result = $controller->setPassword(new WP_REST_Request([
            'token'    => $token,
            'password' => 'short',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_weak_password', $result->get_error_code());
        $this->assertSame(422, $result->data['status'] ?? null);
    }

    public function testSetPasswordRejectsInvalidToken(): void
    {
        $controller = $this->controllerWith([new PwTestMember('user@example.com')]);

        $result = $controller->setPassword(new WP_REST_Request([
            'token'    => 'not-a-real-token',
            'password' => 'long-enough-password',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_token', $result->get_error_code());
        $this->assertSame(400, $result->data['status'] ?? null);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<int, Member> $members
     */
    private function controllerWith(array $members, ?InMemoryPasswordCredentialRepository $repo = null): PasswordAuthController
    {
        $repo    = $repo ?? new InMemoryPasswordCredentialRepository();
        $members = new PwTestMemberRepository($members);
        $auth    = new PasswordAuthenticator($repo, $members, new PasswordResetMailer(), new PasswordPolicy());

        return new PasswordAuthController($auth, new SessionCookie(), $members, new NullAuditLogger(), new RateLimiter());
    }

    /** Pull the raw reset token out of the ?token=… link in the last mail. */
    private function tokenFromLastMail(): string
    {
        $mail = $GLOBALS['__reach_mail'];
        $this->assertNotEmpty($mail, 'expected a reset email to have been sent');
        $message = (string) end($mail)['message'];
        $this->assertSame(1, preg_match('/token=([A-Za-z0-9\-_]+)/', $message, $m));
        return $m[1];
    }

    private function invokeGate(PasswordAuthController $controller, string $email): ?Member
    {
        // No setAccessible() — private methods are reflectively invocable
        // since PHP 8.1 (the plugin's minimum), and the call is deprecated
        // as a no-op on 8.5.
        $ref = new \ReflectionMethod($controller, 'eligibleMember');
        /** @var Member|null $result */
        $result = $ref->invoke($controller, $email);
        return $result;
    }
}

/** No-op AuditLogger for tests that don't assert on audit output. */
final class NullAuditLogger implements AuditLogger
{
    public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ''): void
    {
    }

    public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ''): void
    {
    }
}
