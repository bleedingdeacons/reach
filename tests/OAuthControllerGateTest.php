<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\PendingIdentityStore;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Rest\OAuthController;
use Reach\Session\SessionCookie;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the sign-in eligibility gate introduced on OAuthController.
 *
 * Sign-in must only mint a session for members whose Unity record has
 * either {@see Member::isTwelfthStepper()} or
 * {@see Member::isTelephoneResponder()} set. Anyone else — including
 * verified OAuth identities that don't match a Reach-using member at
 * all — is rejected at the controller boundary so downstream code can
 * assume an authenticated session always belongs to an eligible member.
 *
 * The gate is exercised through `completeEmail`, the typed-email
 * completion endpoint. That path is the smallest slice that covers the
 * helper end-to-end: it doesn't need a ProviderRegistry, a StateStore,
 * or any JWT/OAuth round-trip — just a parked pending identity and a
 * MemberRepository. The same helper guards the `callback` and `apple`
 * entry points, so this coverage is representative of all three.
 */
final class OAuthControllerGateTest extends TestCase
{
    protected function setUp(): void
    {
        // Each test gets a fresh transient store so pending-identity
        // tokens issued here don't leak between tests.
        $GLOBALS['__reach_transients'] = [];
        $GLOBALS['__reach_options']    = [];
    }

    public function testCompleteEmailRejectsWhenNoMemberMatchesTheTypedAddress(): void
    {
        $controller = $this->controllerWith(members: []);
        $token = $this->parkPendingIdentity($controller, 'nobody@example.com');

        $response = $controller->completeEmail(new WP_REST_Request([
            'pending' => $token,
            'email'   => 'nobody@example.com',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('reach_not_eligible', $response->get_error_code());
        $this->assertSame(403, $response->data['status'] ?? null);
    }

    public function testCompleteEmailRejectsMemberWithNeitherRole(): void
    {
        // A member exists for this email but has neither isTwelfthStepper
        // nor isTelephoneResponder set — e.g. a regular member who has
        // not opted into either outreach role. Reach is not for them.
        $member = $this->stubMember('regular@example.com', twelfth: false, responder: false);

        $controller = $this->controllerWith(members: [$member]);
        $token = $this->parkPendingIdentity($controller, 'regular@example.com');

        $response = $controller->completeEmail(new WP_REST_Request([
            'pending' => $token,
            'email'   => 'regular@example.com',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('reach_not_eligible', $response->get_error_code());
    }

    public function testCompleteEmailAcceptsTwelfthStepperMember(): void
    {
        $member = $this->stubMember('twelfth@example.com', twelfth: true, responder: false);

        $controller = $this->controllerWith(members: [$member]);
        $token = $this->parkPendingIdentity($controller, 'twelfth@example.com');

        $response = $controller->completeEmail(new WP_REST_Request([
            'pending' => $token,
            'email'   => 'twelfth@example.com',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
    }

    public function testCompleteEmailAcceptsTelephoneResponderMember(): void
    {
        // The whole reason this gate exists: a responder is not
        // necessarily a 12th-stepper, but must still be allowed to sign
        // in. If this test fails the gate has slipped back to a
        // 12th-stepper-only check.
        $member = $this->stubMember('responder@example.com', twelfth: false, responder: true);

        $controller = $this->controllerWith(members: [$member]);
        $token = $this->parkPendingIdentity($controller, 'responder@example.com');

        $response = $controller->completeEmail(new WP_REST_Request([
            'pending' => $token,
            'email'   => 'responder@example.com',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
    }

    public function testCompleteEmailLooksUpMemberByTheTypedEmailNotTheRelay(): void
    {
        // The pending identity was parked against a Facebook relay
        // address; the user types their real address into the form.
        // The gate must check the real address against the member
        // table, not the relay. If it checked the relay it would
        // wrongly reject every Facebook sign-in.
        $member = $this->stubMember('real@example.com', twelfth: true, responder: false);

        $controller = $this->controllerWith(members: [$member]);
        $token = $this->parkPendingIdentity($controller, 'abc123@privaterelay.appleid.com');

        $response = $controller->completeEmail(new WP_REST_Request([
            'pending' => $token,
            'email'   => 'real@example.com',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
    }

    /**
     * @param array<int, Member> $members
     */
    private function controllerWith(array $members): OAuthController
    {
        return new OAuthController(
            new ProviderRegistry(),
            new StateStore(),
            new SessionCookie(),
            new PendingIdentityStore(),
            new GateTestMemberRepository($members),
        );
    }

    /**
     * Park a pending identity through the real PendingIdentityStore so
     * the controller can consume it back out the other side. Returns
     * the opaque token to pass into completeEmail().
     */
    private function parkPendingIdentity(OAuthController $controller, string $email): string
    {
        // Re-use the store the controller is using via its private
        // property. Cleaner than re-wiring constructor args.
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('pendingIdentities');
        $prop->setAccessible(true);
        /** @var PendingIdentityStore $store */
        $store = $prop->getValue($controller);

        return $store->issue(
            new VerifiedIdentity(
                email: $email,
                provider: 'facebook',
                sub: 'oauth-sub-42',
                providerEmail: $email,
            ),
            'https://example.test/reach/find'
        );
    }

    private function stubMember(string $email, bool $twelfth, bool $responder): Member
    {
        return new class($email, $twelfth, $responder) implements Member {
            public function __construct(
                private string $email,
                private bool $twelfth,
                private bool $responder,
            ) {}
            public function getId(): int { return 1; }
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
        };
    }
}

/**
 * Minimal MemberRepository for the gate tests.
 *
 * Distinct class name from the other test fakes (ControllerMemberRepository,
 * InMemoryMemberRepository) so the three can coexist in one suite without
 * triggering a class-redeclaration error.
 */
final class GateTestMemberRepository implements MemberRepository
{
    /** @param array<int, Member> $members */
    public function __construct(private array $members) {}

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
    public function count(array $args = []): int { return count($this->members); }
    public function create(string $anonymousName): int { return 0; }
    public function save(Member $member): bool { return true; }
    public function delete(int $id): bool { return true; }
    public function update(Member $member): bool { return true; }
}
