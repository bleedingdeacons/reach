<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\Providers\OAuthProvider;
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
 * Tests for the sign-in eligibility gate and the anonymised-email
 * refusal on OAuthController.
 *
 * Two behaviours are covered:
 *
 *  1. Sign-in must only mint a session for members whose Unity record
 *     has either {@see Member::isTwelfthStepper()} or
 *     {@see Member::isTelephoneResponder()} set. Anyone else —
 *     including verified OAuth identities that don't match a
 *     Reach-using member at all — is rejected at the controller
 *     boundary so downstream code can assume an authenticated session
 *     always belongs to an eligible member. The gate lives in the
 *     private `assertMemberAllowed` helper, exercised here directly
 *     via reflection; the same helper guards both the `callback` and
 *     `apple` entry points, so this coverage is representative of both.
 *
 *  2. When a provider proves an identity but only hands back an
 *     anonymised relay address Reach can't use as a contact email
 *     (e.g. a Facebook `*.facebook.com` relay), sign-in is refused
 *     with a `reach_email_required` error rather than a session being
 *     issued. This is driven end-to-end through `callback()` with a
 *     stub provider so the detector + controller wiring is covered as
 *     a unit.
 */
final class OAuthControllerGateTest extends TestCase
{
    protected function setUp(): void
    {
        // Each test gets a fresh transient/option store so OAuth state
        // tokens issued here don't leak between tests.
        $GLOBALS['__reach_transients'] = [];
        $GLOBALS['__reach_options']    = [];
    }

    // --- eligibility gate -------------------------------------------------

    public function testGateRejectsWhenNoMemberMatchesTheEmail(): void
    {
        $controller = $this->controllerWith(members: []);

        $result = $this->invokeGate($controller, $this->identity('nobody@example.com'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->data['status'] ?? null);
    }

    public function testGateRejectsMemberWithNeitherRole(): void
    {
        // A member exists for this email but has neither isTwelfthStepper
        // nor isTelephoneResponder set — e.g. a regular member who has
        // not opted into either outreach role. Reach is not for them.
        $member = $this->stubMember('regular@example.com', twelfth: false, responder: false);

        $controller = $this->controllerWith(members: [$member]);

        $result = $this->invokeGate($controller, $this->identity('regular@example.com'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
    }

    public function testGateAcceptsTwelfthStepperMember(): void
    {
        $member = $this->stubMember('twelfth@example.com', twelfth: true, responder: false);

        $controller = $this->controllerWith(members: [$member]);

        // null return from the gate == "sign-in may proceed".
        $this->assertNull($this->invokeGate($controller, $this->identity('twelfth@example.com')));
    }

    public function testGateAcceptsTelephoneResponderMember(): void
    {
        // The whole reason this gate exists: a responder is not
        // necessarily a 12th-stepper, but must still be allowed to sign
        // in. If this test fails the gate has slipped back to a
        // 12th-stepper-only check.
        $member = $this->stubMember('responder@example.com', twelfth: false, responder: true);

        $controller = $this->controllerWith(members: [$member]);

        $this->assertNull($this->invokeGate($controller, $this->identity('responder@example.com')));
    }

    // --- anonymised-email refusal ----------------------------------------

    public function testCallbackRefusesAnonymisedRelayAddressWithEmailRequired(): void
    {
        // Facebook proved who the user is but only gave back a relay
        // address on *.facebook.com. There is no contactable email, so
        // the callback must refuse rather than mint a session — and it
        // must refuse before the eligibility gate even runs (we never
        // got a real address to look a member up by).
        //
        // The refusal is a friendly redirect back to the sign-in page
        // carrying ?reach_error=email_required (the template renders a
        // styled notice), NOT a raw WP_Error/JSON page.
        $relay = 'abc123hash@privaterelay.facebook.com';
        $provider = new GateStubProvider($this->identity($relay, 'facebook'));

        // A member *does* exist on the relay address; this proves the
        // refusal is driven by anonymisation, not by member eligibility.
        $member = $this->stubMember($relay, twelfth: true, responder: false);
        $controller = $this->controllerWith(members: [$member], provider: $provider);

        [$state] = $this->seedState($controller, 'facebook');

        $result = $controller->callback(new WP_REST_Request([
            'state' => $state,
            'code'  => 'auth-code-xyz',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        $location = $result->get_headers()['Location'] ?? '';
        $this->assertStringContainsString('/reach/signin', $location);
        $this->assertStringContainsString('reach_error=email_required', $location);
    }

    public function testCallbackAcceptsRealAddressThenRunsEligibilityGate(): void
    {
        // A real (non-relay) address from the provider must NOT trip the
        // email-required refusal — it should fall through to the
        // eligibility gate. Here the address matches no member, so the
        // gate rejects it and the user is redirected back to sign-in
        // with ?reach_error=not_eligible. Seeing that code (rather than
        // email_required) confirms a real address sailed past the
        // anonymisation check and into the gate.
        $provider = new GateStubProvider($this->identity('real-but-unknown@example.com', 'facebook'));
        $controller = $this->controllerWith(members: [], provider: $provider);

        [$state] = $this->seedState($controller, 'facebook');

        $result = $controller->callback(new WP_REST_Request([
            'state' => $state,
            'code'  => 'auth-code-xyz',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        $location = $result->get_headers()['Location'] ?? '';
        $this->assertStringContainsString('/reach/signin', $location);
        $this->assertStringContainsString('reach_error=not_eligible', $location);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Call the private eligibility gate and return its result
     * (null == allowed, WP_Error == denied).
     */
    private function invokeGate(OAuthController $controller, VerifiedIdentity $identity): ?WP_Error
    {
        $ref = new \ReflectionMethod($controller, 'assertMemberAllowed');
        $ref->setAccessible(true);
        /** @var WP_Error|null $result */
        $result = $ref->invoke($controller, $identity);
        return $result;
    }

    /**
     * Issue a real OAuth state token for $provider through the
     * controller's own StateStore, so callback() can consume it back
     * out the other side. Returns [state, nonce].
     *
     * @return array{0: string, 1: string}
     */
    private function seedState(OAuthController $controller, string $provider): array
    {
        $ref = new \ReflectionProperty($controller, 'stateStore');
        $ref->setAccessible(true);
        /** @var StateStore $store */
        $store = $ref->getValue($controller);
        $tokens = $store->issue($provider, 'https://example.test/reach/find');
        return [$tokens['state'], $tokens['nonce']];
    }

    private function identity(string $email, string $provider = 'facebook'): VerifiedIdentity
    {
        return new VerifiedIdentity(
            email: $email,
            provider: $provider,
            sub: 'oauth-sub-42',
            providerEmail: $email,
        );
    }

    /**
     * @param array<int, Member> $members
     */
    private function controllerWith(array $members, ?OAuthProvider $provider = null): OAuthController
    {
        $registry = new ProviderRegistry();
        if ($provider !== null) {
            $registry->register($provider);
        }

        return new OAuthController(
            $registry,
            new StateStore(),
            new SessionCookie(),
            new GateTestMemberRepository($members),
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
 * Minimal server-side OAuthProvider that yields a fixed identity from
 * handleCallback(), so the controller's callback() path can be driven
 * without a real OAuth round-trip.
 */
final class GateStubProvider implements OAuthProvider
{
    public function __construct(private VerifiedIdentity $identity) {}

    public function name(): string { return $this->identity->provider; }
    public function isServerSide(): bool { return true; }
    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        return 'https://provider.example/authorize';
    }
    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        return $this->identity;
    }
    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        return null;
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
