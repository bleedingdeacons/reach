<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordCredential;
use Reach\Auth\PasswordCredentialRepository;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\PasswordResetResult;
use Reach\Auth\VerifiedIdentity;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

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
final class PasswordAuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_mail'] = [];
    }

    // --- login ------------------------------------------------------------

    public function testLoginReturnsPasswordIdentityForCorrectPassword(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $identity = $auth->attemptLogin('user@example.com', 'correcthorse10', 1000);

        $this->assertInstanceOf(VerifiedIdentity::class, $identity);
        $this->assertSame('password', $identity->provider);
        $this->assertSame('user@example.com', $identity->email);
    }

    public function testLoginNormalisesEmailCaseAndWhitespace(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $identity = $auth->attemptLogin('  User@Example.COM ', 'correcthorse10', 1000);

        $this->assertNotNull($identity);
        $this->assertSame('user@example.com', $identity->email);
    }

    public function testLoginFailsAndCountsFailureForWrongPassword(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $this->assertNull($auth->attemptLogin('user@example.com', 'wrong-password', 1000));
        $this->assertSame(1, $repo->find('user@example.com')->failedAttempts);
    }

    public function testLoginFailsForUnknownEmailWithoutCreatingARow(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, []);

        $this->assertNull($auth->attemptLogin('nobody@example.com', 'whatever-pw', 1000));
        // No credential row must be seeded for an unknown email.
        $this->assertNull($repo->find('nobody@example.com'));
    }

    public function testAccountLocksAfterFiveFailuresThenUnlocksLater(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $repo->seedPassword('user@example.com', 'correcthorse10');
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        for ($i = 0; $i < PasswordAuthenticator::MAX_FAILED_ATTEMPTS; $i++) {
            $auth->attemptLogin('user@example.com', 'wrong-password', 1000);
        }

        // Even the correct password is refused while locked.
        $this->assertNull($auth->attemptLogin('user@example.com', 'correcthorse10', 1000));

        // Once the lockout window has passed, the correct password works.
        $later = 1000 + PasswordAuthenticator::LOCKOUT_SECONDS + 1;
        $this->assertNotNull($auth->attemptLogin('user@example.com', 'correcthorse10', $later));
    }

    // --- begin reset ------------------------------------------------------

    public function testBeginResetEmailsLinkAndStoresTokenForEligibleMember(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);

        $this->assertCount(1, $GLOBALS['__reach_mail']);
        $this->assertSame('user@example.com', $GLOBALS['__reach_mail'][0]['to']);

        $cred = $repo->find('user@example.com');
        $this->assertNotNull($cred);
        $this->assertNotSame('', $cred->resetTokenHash);
        $this->assertSame(1000 + PasswordAuthenticator::RESET_TTL_SECONDS, $cred->resetExpiresAt);
    }

    public function testBeginResetIsSilentForUnknownEmail(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, []);

        $auth->beginReset('nobody@example.com', 1000);

        $this->assertCount(0, $GLOBALS['__reach_mail']);
        $this->assertNull($repo->find('nobody@example.com'));
    }

    public function testBeginResetIsSilentForIneligibleMember(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        // A member exists but holds neither outreach role.
        $auth = $this->makeAuth($repo, [$this->member('regular@example.com', twelfth: false, responder: false)]);

        $auth->beginReset('regular@example.com', 1000);

        $this->assertCount(0, $GLOBALS['__reach_mail']);
        $this->assertNull($repo->find('regular@example.com'));
    }

    // --- complete reset ---------------------------------------------------

    public function testCompleteResetSetsPasswordIsSingleUseAndEnablesLogin(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);
        $token = $this->tokenFromLastMail();

        $result = $auth->completeReset($token, 'correct-horse-battery', 2000);
        $this->assertTrue($result->isOk());
        $this->assertSame('user@example.com', $result->email);

        // The new password now authenticates.
        $this->assertNotNull($auth->attemptLogin('user@example.com', 'correct-horse-battery', 2100));

        // The link is single-use: replaying it fails as an invalid token.
        $replay = $auth->completeReset($token, 'another-good-secret', 2200);
        $this->assertSame(PasswordResetResult::INVALID_TOKEN, $replay->status);
    }

    public function testCompleteResetStoresAnArgon2idHash(): void
    {
        // Only assert the algorithm when the platform actually provides
        // Argon2id; on a PHP built without it we deliberately fall back.
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('Argon2id not available on this PHP build.');
        }

        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);
        $auth->completeReset($this->tokenFromLastMail(), 'correct-horse-battery', 2000);

        $this->assertStringStartsWith('$argon2id$', $repo->find('user@example.com')->passwordHash);
    }

    public function testCompleteResetRejectsWeakPasswordWithoutSpendingToken(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);
        $token = $this->tokenFromLastMail();

        // 'short' is below the policy minimum.
        $result = $auth->completeReset($token, 'short', 2000);
        $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
        $this->assertNotSame('', $result->message);

        // A policy rejection must leave the link usable for another try.
        $retry = $auth->completeReset($token, 'correct-horse-battery', 2000);
        $this->assertTrue($retry->isOk());
    }

    public function testCompleteResetRejectsCommonPassword(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);
        // A long-but-common password: clears the length check, caught by the
        // deny-list.
        $result = $auth->completeReset($this->tokenFromLastMail(), 'passwordpassword', 2000);

        $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
    }

    public function testCompleteResetRejectsPasswordBuiltFromEmail(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('gordon@example.com')]);

        $auth->beginReset('gordon@example.com', 1000);
        // Contains the email local-part "gordon".
        $result = $auth->completeReset($this->tokenFromLastMail(), 'gordon-is-here', 2000);

        $this->assertSame(PasswordResetResult::WEAK_PASSWORD, $result->status);
    }

    public function testCompleteResetRejectsExpiredToken(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $auth->beginReset('user@example.com', 1000);
        $token = $this->tokenFromLastMail();

        $expired = 1000 + PasswordAuthenticator::RESET_TTL_SECONDS + 1;
        $result = $auth->completeReset($token, 'correct-horse-battery', $expired);
        $this->assertSame(PasswordResetResult::INVALID_TOKEN, $result->status);
    }

    public function testCompleteResetRejectsUnknownToken(): void
    {
        $repo = new InMemoryPasswordCredentialRepository();
        $auth = $this->makeAuth($repo, [$this->member('user@example.com')]);

        $result = $auth->completeReset('not-a-real-token', 'correct-horse-battery', 2000);
        $this->assertSame(PasswordResetResult::INVALID_TOKEN, $result->status);
    }

    // --- helpers ----------------------------------------------------------

    private function makeAuth(InMemoryPasswordCredentialRepository $repo, array $members): PasswordAuthenticator
    {
        return new PasswordAuthenticator(
            $repo,
            new PwTestMemberRepository($members),
            new PasswordResetMailer(),
            new PasswordPolicy(),
        );
    }

    private function member(string $email, bool $twelfth = true, bool $responder = false): Member
    {
        return new PwTestMember($email, $twelfth, $responder);
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
}

/**
 * In-memory {@see PasswordCredentialRepository} for the authenticator tests.
 *
 * Mirrors the production wpdb repository's important semantics: upsert on
 * password set (clearing token + lockout), UPDATE-only failed-attempt
 * recording (an unknown email never gets a row), and token lookup by hash.
 */
final class InMemoryPasswordCredentialRepository implements PasswordCredentialRepository
{
    /** @var array<string, PasswordCredential> */
    public array $rows = [];

    public function seedPassword(string $email, string $plainPassword): void
    {
        $this->rows[$email] = new PasswordCredential(
            $email,
            password_hash($plainPassword, PASSWORD_DEFAULT),
            '',
            0,
            0,
            0,
            0,
        );
    }

    public function find(string $email): ?PasswordCredential
    {
        return $this->rows[$email] ?? null;
    }

    public function findByResetTokenHash(string $tokenHash): ?PasswordCredential
    {
        if ($tokenHash === '') {
            return null;
        }
        foreach ($this->rows as $row) {
            if ($row->resetTokenHash === $tokenHash) {
                return $row;
            }
        }
        return null;
    }

    public function upsertPasswordHash(string $email, string $passwordHash, int $now): void
    {
        // Set password, clear token + lockout — the production "clean slate".
        $this->rows[$email] = new PasswordCredential($email, $passwordHash, '', 0, 0, 0, $now);
    }

    public function storeResetToken(string $email, string $tokenHash, int $expiresAt, int $now): void
    {
        $existing = $this->rows[$email] ?? null;
        $this->rows[$email] = new PasswordCredential(
            $email,
            $existing?->passwordHash ?? '',
            $tokenHash,
            $expiresAt,
            $existing?->failedAttempts ?? 0,
            $existing?->lockedUntil ?? 0,
            $now,
        );
    }

    public function clearResetToken(string $email, int $now): void
    {
        $e = $this->rows[$email] ?? null;
        if ($e === null) {
            return;
        }
        $this->rows[$email] = new PasswordCredential($email, $e->passwordHash, '', 0, $e->failedAttempts, $e->lockedUntil, $now);
    }

    public function recordFailedAttempt(string $email, int $failedAttempts, int $lockedUntil, int $now): void
    {
        $e = $this->rows[$email] ?? null;
        if ($e === null) {
            return; // UPDATE-only: no row for unknown emails.
        }
        $this->rows[$email] = new PasswordCredential($email, $e->passwordHash, $e->resetTokenHash, $e->resetExpiresAt, $failedAttempts, $lockedUntil, $now);
    }

    public function resetFailedAttempts(string $email, int $now): void
    {
        $e = $this->rows[$email] ?? null;
        if ($e === null) {
            return;
        }
        $this->rows[$email] = new PasswordCredential($email, $e->passwordHash, $e->resetTokenHash, $e->resetExpiresAt, 0, 0, $now);
    }

    public function delete(string $email): void
    {
        unset($this->rows[$email]);
    }
}

/**
 * Minimal Member fake for the password tests. Distinct class name from the
 * other suites' fakes so all can coexist in one PHPUnit run.
 */
final class PwTestMember implements Member
{
    public function __construct(
        private string $email,
        private bool $twelfth = true,
        private bool $responder = false,
        private int $id = 1,
    ) {
    }

    public function getId(): int { return $this->id; }
    public function getAnonymousName(): string { return 'Test'; }
    public function showAnonymousName(): bool { return true; }
    public function showMemberProfile(): bool { return true; }
    public function getAnonymousProfile(): string { return ''; }
    public function getIntergroupPosition(): int { return 0; }
    public function getIntergroupPositionRotation(): string { return ''; }
    public function getHomeGroup(): int { return 0; }
    public function isGSR(): bool { return false; }
    public function getMeetingPO(): mixed { return null; }
    public function getPersonalEmail(): string { return $this->email; }
    public function getMobileNumber(): string { return ''; }
    public function isTwelfthStepper(): bool { return $this->twelfth; }
    public function isTelephoneResponder(): bool { return $this->responder; }
    public function getArea(): string { return ''; }
    public function getAccepts(): array { return []; }
    public function isGdprAccepted(): bool { return true; }
    public function getGdprAcceptedAt(): string { return ''; }
    public function getGdprAcceptanceVersion(): string { return ''; }
    public function getGdprAcceptanceMethod(): string { return ''; }
    public function getGdprAcceptanceStatement(): string { return ''; }
    public function getUpdated(): string { return ''; }
}

/**
 * Minimal MemberRepository fake for the password tests. Unique class name
 * so it can live alongside the other suites' member-repository fakes.
 */
final class PwTestMemberRepository implements MemberRepository
{
    /** @param array<int, Member> $members */
    public function __construct(private array $members)
    {
    }

    public function findById(int $id): ?Member
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $id) {
                return $m;
            }
        }
        return null;
    }

    public function findByEmail(string $email): ?Member
    {
        foreach ($this->members as $m) {
            if (strcasecmp($m->getPersonalEmail(), $email) === 0) {
                return $m;
            }
        }
        return null;
    }

    public function findAll(array $args = []): array { return $this->members; }
    public function findTelephoneResponders(): array
    {
        return array_values(array_filter($this->members, fn($m) => $m->isTelephoneResponder()));
    }
    public function count(array $args = []): int { return count($this->members); }
    public function create(string $anonymousName): int { return 0; }
    public function save(Member $member): bool { return true; }
    public function delete(int $id): bool { return true; }
    public function update(Member $member): bool { return true; }
}
