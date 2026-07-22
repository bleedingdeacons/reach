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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/PasswordAuthenticatorTest.php';       // InMemoryPasswordCredentialRepository, PwTestMember(Repository)
require_once __DIR__ . '/PasswordAuthControllerGateTest.php';  // NullAuditLogger
require_once __DIR__ . '/NearestMembersControllerTest.php';    // RecordingAuditLogger

/**
 * Success-path and throttle cover for {@see PasswordAuthController},
 * complementing the rejection-focused PasswordAuthControllerGateTest.
 *
 * The security-relevant behaviours here: a correct login for an eligible
 * member mints a session and writes an authentication audit row; the per-IP
 * throttle refuses with 429 once tripped (login) or silently drops the send
 * while still returning the same body (request-reset, so a flooder gets no
 * signal); and completing a reset with a strong password auto-signs an
 * eligible member in.
 */
final class PasswordAuthControllerFlowTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_mail'] = [];
        $GLOBALS['__reach_transients'] = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    public function testLoginSuccessIssuesSessionAndAuditsAuthentication(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $audit = new RecordingAuditLogger();
        $controller = $this->controller([new PwTestMember('user@example.com')], $repo, $audit);

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'user@example.com',
            'password' => 'correcthorse10',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertArrayHasKey('redirect', $result->get_data());

        // One authentication audit row against the member.
        $this->assertCount(1, $audit->entries);
        $this->assertSame('view', $audit->entries[0]['action']);
        $this->assertSame('authentication', $audit->entries[0]['fieldName']);
    }

    public function testLoginIsRefusedWhenPerIpLimitTripped(): void
    {
        // Seed the per-IP login bucket up to the limit (50 / 15-min window)
        // so the controller's own call tips it over.
        $rl = new RateLimiter();
        for ($i = 0; $i < 50; $i++) {
            $rl->overLimit('login:203.0.113.9', 50, 15 * 60);
        }

        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $controller = $this->controller([new PwTestMember('user@example.com')], $repo);

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'user@example.com',
            'password' => 'correcthorse10',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_rate_limited', $result->get_error_code());
        $this->assertSame(429, $result->data['status'] ?? null);
    }

    public function testRequestResetSkipsSendWhenFloodLimitTrippedButStillAcknowledges(): void
    {
        // Seed the per-IP reset bucket to its cap (10 / hour).
        $rl = new RateLimiter();
        for ($i = 0; $i < 10; $i++) {
            $rl->overLimit('reset:203.0.113.9', 10, 60 * 60);
        }

        $controller = $this->controller([new PwTestMember('user@example.com')]);

        $result = $controller->requestReset(new WP_REST_Request(['email' => 'user@example.com']));

        // Same acknowledgement as always — no enumeration, no flood signal.
        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['sent']);
        // …but no email actually went out because the flood cap was hit.
        $this->assertCount(0, $GLOBALS['__reach_mail']);
    }

    public function testSetPasswordSuccessSignsEligibleMemberIn(): void
    {
        $controller = $this->controller([new PwTestMember('user@example.com')]);

        // Get a genuine reset token via the request-reset flow.
        $controller->requestReset(new WP_REST_Request(['email' => 'user@example.com']));
        $token = $this->tokenFromLastMail();

        $result = $controller->setPassword(new WP_REST_Request([
            'token'    => $token,
            'password' => 'a-strong-enough-password',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $data = $result->get_data();
        $this->assertTrue($data['signed_in']);
        $this->assertStringContainsString('/reach/home', $data['redirect']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<int, \Unity\Members\Interfaces\Member> $members
     */
    private function controller(
        array $members,
        ?InMemoryPasswordCredentialRepository $repo = null,
        ?\Scrutiny\Audit\Interfaces\AuditLogger $audit = null,
    ): PasswordAuthController {
        $repo    = $repo ?? new InMemoryPasswordCredentialRepository();
        $memRepo = new PwTestMemberRepository($members);
        $auth    = new PasswordAuthenticator($repo, $memRepo, new PasswordResetMailer(), new PasswordPolicy());

        return new PasswordAuthController(
            $auth,
            new SessionCookie(),
            $memRepo,
            $audit ?? new NullAuditLogger(),
            new RateLimiter(),
        );
    }

    private function tokenFromLastMail(): string
    {
        $mail = $GLOBALS['__reach_mail'];
        $this->assertNotEmpty($mail, 'expected a reset email to have been sent');
        $message = (string) end($mail)['message'];
        $this->assertSame(1, preg_match('/token=([A-Za-z0-9\-_]+)/', $message, $m));
        return $m[1];
    }
}
