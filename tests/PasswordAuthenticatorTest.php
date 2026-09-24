<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\PasswordAuthenticator;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\PasswordResetResult;
use Reach\Auth\VerifiedIdentity;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Unit tests for {@see PasswordAuthenticator} — the email + password
 * sign-in path and the emailed set/reset flow.
 *
 * The real {@see PasswordResetMailer} is used (its wp_mail / get_bloginfo
 * dependencies are stubbed in bootstrap.php), so the reset tests also
 * cover the link the member actually receives. Credentials live in an
 * in-memory fake that mirrors the wpdb repository's UPDATE-only semantics
 * for failed-attempt recording (unknown emails never get a row).
 */

beforeEach(function () {
    // --- helpers ----------------------------------------------------------

    $this->makeAuth = function (InMemoryPasswordCredentialRepository $repo, array $members): PasswordAuthenticator {
        /**
         * The mailer the authenticator under test was built with, kept so
         * {@see tokenFromLastMail()} can flush its queue.
         */
        $this->mailer = new PasswordResetMailer();

        return new PasswordAuthenticator(
            $repo,
            new InMemoryMemberRepository($members),
            $this->mailer,
            new PasswordPolicy(),
        );
    };

    $this->member = function (
        string $email,
        bool $twelfth = true,
        bool $responder = false,
        ResponderCertification $certification = ResponderCertification::None,
    ): Member {
        return new MemberStub($email, $twelfth, $responder, responderCertification: $certification);
    };

    /** Pull the raw reset token out of the ?token=… link in the last mail. */
    /**
     * The mail actually sent, having first flushed anything the mailer
     * is holding back until after the response.
     *
     * Assertions go through this rather than reading WpState::$mail
     * directly so that "no link was sent" stays a real assertion: read
     * without the flush, a queued-but-unsent link would look identical
     * to one that was never issued.
     *
     * @return array<int, array<string, mixed>>
     */
    $this->sentMail = function (): array {
        $this->mailer->flush();

        return WpState::$mail;
    };

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

    WpState::$mail = [];
});

// --- login ------------------------------------------------------------
test('login returns password identity for correct password', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $identity = $auth->attemptLogin('user@example.com', 'correcthorse10', 1000);

    $this->assertInstanceOf(VerifiedIdentity::class, $identity);
    $this->assertSame('password', $identity->provider);
    $this->assertSame('user@example.com', $identity->email);
});

test('login normalises email case and whitespace', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $identity = $auth->attemptLogin('  User@Example.COM ', 'correcthorse10', 1000);

    $this->assertNotNull($identity);
    $this->assertSame('user@example.com', $identity->email);
});

test('login fails and counts failure for wrong password', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $this->assertNull($auth->attemptLogin('user@example.com', 'wrong-password', 1000));
    $this->assertSame(1, $repo->find('user@example.com')->failedAttempts);
});

test('login fails for unknown email without creating a row', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, []);

    $this->assertNull($auth->attemptLogin('nobody@example.com', 'whatever-pw', 1000));
    // No credential row must be seeded for an unknown email.
    $this->assertNull($repo->find('nobody@example.com'));
});

test('account locks after five failures then unlocks later', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $repo->seedPassword('user@example.com', 'correcthorse10');
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    for ($i = 0; $i < PasswordAuthenticator::MAX_FAILED_ATTEMPTS; $i++) {
        $auth->attemptLogin('user@example.com', 'wrong-password', 1000);
    }

    // Even the correct password is refused while locked.
    $this->assertNull($auth->attemptLogin('user@example.com', 'correcthorse10', 1000));

    // Once the lockout window has passed, the correct password works.
    $later = 1000 + PasswordAuthenticator::LOCKOUT_SECONDS + 1;
    $this->assertNotNull($auth->attemptLogin('user@example.com', 'correcthorse10', $later));
});

// --- begin reset ------------------------------------------------------
test('begin reset emails link and stores token for eligible member', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);

    $this->assertCount(1, ($this->sentMail)());
    $this->assertSame('user@example.com', ($this->sentMail)()[0]['to']);

    $cred = $repo->find('user@example.com');
    $this->assertNotNull($cred);
    $this->assertNotSame('', $cred->resetTokenHash);
    $this->assertSame(1000 + PasswordAuthenticator::RESET_TTL_SECONDS, $cred->resetExpiresAt);
});

test('begin reset is silent for unknown email', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, []);

    $auth->beginReset('nobody@example.com', 1000);

    $this->assertCount(0, ($this->sentMail)());
    $this->assertNull($repo->find('nobody@example.com'));
});

test('begin reset is silent for ineligible member', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    // A member exists but holds neither outreach role.
    $auth = ($this->makeAuth)($repo, [($this->member)('regular@example.com', twelfth: false, responder: false)]);

    $auth->beginReset('regular@example.com', 1000);

    $this->assertCount(0, ($this->sentMail)());
    $this->assertNull($repo->find('regular@example.com'));
});

test('begin reset is silent for uncertified responder', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    // A telephone responder who is not yet certified is not eligible
    // for Reach, so a reset request must be silently ignored.
    $auth = ($this->makeAuth)($repo, [
        ($this->member)('trainee@example.com', twelfth: false, responder: true, certification: ResponderCertification::Pending),
    ]);

    $auth->beginReset('trainee@example.com', 1000);

    $this->assertCount(0, ($this->sentMail)());
    $this->assertNull($repo->find('trainee@example.com'));
});

test('begin reset proceeds for certified responder', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    // A certified responder is eligible even without the 12th-stepper
    // role, so the reset email must be sent.
    $auth = ($this->makeAuth)($repo, [
        ($this->member)('certified@example.com', twelfth: false, responder: true, certification: ResponderCertification::Certified),
    ]);

    $auth->beginReset('certified@example.com', 1000);

    $this->assertCount(1, ($this->sentMail)());
});

test('begin reset honours cooldown then allows resend later', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    // A second request inside the cooldown window sends nothing.
    $auth->beginReset('user@example.com', 1000 + PasswordAuthenticator::RESET_COOLDOWN_SECONDS - 1);
    $this->assertCount(1, ($this->sentMail)());

    // Past the cooldown, a resend is allowed.
    $auth->beginReset('user@example.com', 1000 + PasswordAuthenticator::RESET_COOLDOWN_SECONDS + 1);
    $this->assertCount(2, ($this->sentMail)());
});

// --- complete reset ---------------------------------------------------
test('complete reset sets password is single use and enables login', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    $token = ($this->tokenFromLastMail)();

    $result = $auth->completeReset($token, 'correct-horse-battery', 2000);
    $this->assertTrue($result->isOk());
    $this->assertSame('user@example.com', $result->email);

    // The new password now authenticates.
    $this->assertNotNull($auth->attemptLogin('user@example.com', 'correct-horse-battery', 2100));

    // The link is single-use: replaying it fails as an invalid token.
    $replay = $auth->completeReset($token, 'another-good-secret', 2200);
    $this->assertSame(PasswordResetResult::INVALID_TOKEN, $replay->status);
});

test('complete reset stores an argon2id hash', function () {
    // Only assert the algorithm when the platform actually provides
    // Argon2id; on a PHP built without it we deliberately fall back.
    if (!defined('PASSWORD_ARGON2ID')) {
        $this->markTestSkipped('Argon2id not available on this PHP build.');
    }

    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    $auth->completeReset(($this->tokenFromLastMail)(), 'correct-horse-battery', 2000);

    $this->assertStringStartsWith('$argon2id$', $repo->find('user@example.com')->passwordHash);
});

test('complete reset rejects weak password without spending token', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    $token = ($this->tokenFromLastMail)();

    // 'short' is below the policy minimum.
    $result = $auth->completeReset($token, 'short', 2000);
    $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
    $this->assertNotSame('', $result->message);

    // A policy rejection must leave the link usable for another try.
    $retry = $auth->completeReset($token, 'correct-horse-battery', 2000);
    $this->assertTrue($retry->isOk());
});

test('complete reset rejects common password', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    // A long-but-common password: clears the length check, caught by the
    // deny-list.
    $result = $auth->completeReset(($this->tokenFromLastMail)(), 'passwordpassword', 2000);

    $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
});

test('complete reset rejects password built from email', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('gordon@example.com')]);

    $auth->beginReset('gordon@example.com', 1000);
    // Contains the email local-part "gordon".
    $result = $auth->completeReset(($this->tokenFromLastMail)(), 'gordon-is-here', 2000);

    $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
});

test('complete reset rejects expired token', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $auth->beginReset('user@example.com', 1000);
    $token = ($this->tokenFromLastMail)();

    $expired = 1000 + PasswordAuthenticator::RESET_TTL_SECONDS + 1;
    $result = $auth->completeReset($token, 'correct-horse-battery', $expired);
    $this->assertSame(PasswordResetResult::INVALID_TOKEN, $result->status);
});

test('complete reset rejects unknown token', function () {
    $repo = new InMemoryPasswordCredentialRepository();
    $auth = ($this->makeAuth)($repo, [($this->member)('user@example.com')]);

    $result = $auth->completeReset('not-a-real-token', 'correct-horse-battery', 2000);
    $this->assertSame(PasswordResetResult::INVALID_TOKEN, $result->status);
});
