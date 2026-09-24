<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Core\RateLimiter;
use Reach\Rest\PasswordAuthController;
use Reach\Session\SessionCookie;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

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

beforeEach(function () {

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<int, Member> $members
     */
    $this->controllerWith = function (array $members, ?InMemoryPasswordCredentialRepository $repo = null): PasswordAuthController {
        $repo    = $repo ?? new InMemoryPasswordCredentialRepository();
        $members = new InMemoryMemberRepository($members);
        /**
         * The mailer the authenticator under test was built with, kept so
         * {@see tokenFromLastMail()} can flush its queue.
         */
        $this->mailer = new PasswordResetMailer();
        $auth    = new PasswordAuthenticator($repo, $members, $this->mailer, new PasswordPolicy());

        return new PasswordAuthController($auth, new SessionCookie(), $members, new SpyAuditLogger(), new RateLimiter());
    };

    /** Pull the raw reset token out of the ?token=… link in the last mail. */
    $this->tokenFromLastMail = function (): string {
        // Reset links are queued and sent after the response, so that an
        // eligible address and an ineligible one cost the same to answer
        // (see PasswordResetMailer). In production the flush runs on
        // `shutdown`; here we run it directly, because Brain Monkey
        // records hooks without firing them.
        $this->mailer->flush();

        $mail = WpState::$mail;
        $this->assertNotEmpty($mail, 'expected a reset email to have been sent');
        $message = (string) end($mail)['message'];
        $this->assertSame(1, preg_match('/token=([A-Za-z0-9\-_]+)/', $message, $m));
        return $m[1];
    };

    $this->invokeGate = function (PasswordAuthController $controller, string $email): ?Member {
        // No setAccessible() — private methods are reflectively invocable
        // since PHP 8.1 (the plugin's minimum), and the call is deprecated
        // as a no-op on 8.5.
        $ref = new \ReflectionMethod($controller, 'eligibleMember');
        /** @var Member|null $result */
        $result = $ref->invoke($controller, $email);
        return $result;
    };

    WpState::$mail = [];
    // Fresh transient store so the per-IP rate-limit counter doesn't
    // carry between tests.
    WpState::$transients = [];
});

// --- eligibility gate -------------------------------------------------
test('gate rejects unknown email', function () {
    $controller = ($this->controllerWith)([]);
    $this->assertNull(($this->invokeGate)($controller, 'nobody@example.com'));
});

test('gate rejects member with neither role', function () {
    $controller = ($this->controllerWith)([new MemberStub('regular@example.com', false, false)]);
    $this->assertNull(($this->invokeGate)($controller, 'regular@example.com'));
});

test('gate accepts twelfth stepper', function () {
    $controller = ($this->controllerWith)([new MemberStub('twelfth@example.com', true, false)]);
    $this->assertInstanceOf(Member::class, ($this->invokeGate)($controller, 'twelfth@example.com'));
});

test('gate accepts certified telephone responder', function () {
    $controller = ($this->controllerWith)([new MemberStub(
        personalEmail: 'responder@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    )]);

    $this->assertInstanceOf(Member::class, ($this->invokeGate)($controller, 'responder@example.com'));
});

test('gate rejects uncertified telephone responder', function () {
    // This assertion used to read the other way, and that was the bug:
    // the responder role alone was enough here while the OAuth path
    // additionally required a current certification, so an uncertified
    // responder could sign in by password and not by provider. The rule
    // now lives once, in Reach\Auth\OutreachEligibility.
    $controller = ($this->controllerWith)([new MemberStub(
        personalEmail: 'trainee@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::InTraining,
    )]);

    $this->assertNull(($this->invokeGate)($controller, 'trainee@example.com'));
});

// --- endpoint rejections (no session issued) --------------------------
test('login returns generic401 for unknown credentials', function () {
    $controller = ($this->controllerWith)([new MemberStub('user@example.com')]);

    $result = $controller->login(new WP_REST_Request([
        'email'    => 'user@example.com',
        'password' => 'no-password-set-yet',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_credentials', $result->get_error_code());
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

test('login rejects correct password for ineligible member', function () {
    // Right password, but the member holds neither outreach role: the
    // controller gate must refuse before any session is minted.
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('ex@example.com', 'correcthorse10');
    $controller = ($this->controllerWith)(
        [new MemberStub('ex@example.com', false, false)],
        $repo,
    );

    $result = $controller->login(new WP_REST_Request([
        'email'    => 'ex@example.com',
        'password' => 'correcthorse10',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_not_eligible', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status'] ?? null);
});

test('request reset always acknowledges regardless of account', function () {
    $controller = ($this->controllerWith)([]);

    $result = $controller->requestReset(new WP_REST_Request(['email' => 'nobody@example.com']));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(200, $result->get_status());
    $this->assertTrue($result->get_data()['sent'] ?? null);
    // Nothing was actually emailed for a non-member.
    $this->assertCount(0, WpState::$mail);
});

test('set password rejects weak password', function () {
    $controller = ($this->controllerWith)([new MemberStub('user@example.com')]);

    // Get a genuinely valid token via the reset request so it's the weak
    // password — not a bad token — that gets rejected (422, not 400).
    $controller->requestReset(new WP_REST_Request(['email' => 'user@example.com']));
    $token = ($this->tokenFromLastMail)();

    $result = $controller->setPassword(new WP_REST_Request([
        'token'    => $token,
        'password' => 'short',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_weak_password', $result->get_error_code());
    $this->assertSame(422, $result->get_error_data()['status'] ?? null);
});

test('set password rejects invalid token', function () {
    $controller = ($this->controllerWith)([new MemberStub('user@example.com')]);

    $result = $controller->setPassword(new WP_REST_Request([
        'token'    => 'not-a-real-token',
        'password' => 'long-enough-password',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_token', $result->get_error_code());
    $this->assertSame(400, $result->get_error_data()['status'] ?? null);
});
