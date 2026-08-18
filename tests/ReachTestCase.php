<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_REST_Request;

/**
 * Base case for Reach's unit tests.
 *
 * The WordPress stand-ins come from bleedingdeacons/wp-mocks, so the shared
 * TestCase handles the Brain Monkey lifecycle, Mockery integration and
 * resetting WpState between tests. What is added here is hook *capture*.
 *
 * Reach's wiring tests do not merely assert that a hook was registered — they
 * take the registered callback back out and invoke it, which is how the
 * rest_post_dispatch cache filter, the trusted_signup_member resolver and the
 * unity/member_deleted purge are exercised without a WordPress request.
 *
 * The old bootstrap could do that because it defined add_action()/add_filter()
 * itself and kept the callbacks in a global. Brain Monkey owns those functions
 * now — it must, or its own hook expectations silently never match — and its
 * store has no public API for reading callbacks back out. The way to get at
 * them is to say up front which hooks are interesting and let the expectation
 * hand each callback over as it is registered.
 *
 * Two consequences worth knowing:
 *
 *  - captureAction()/captureFilter() must be called *before* the code that
 *    registers the hook. Every test here calls Plugin::init() in its own body,
 *    so that is simply the first line rather than a restructuring.
 *  - only named hooks are captured. Anything not named still registers
 *    normally and is still visible to assertActionAdded() and friends, which
 *    the shared HookAssertions trait provides for the presence-only cases.
 */
abstract class ReachTestCase extends TestCase
{
    /**
     * Callbacks registered against each captured action, in registration order.
     *
     * @var array<string, array<int, callable>>
     */
    protected array $addedActions = [];

    /**
     * Callbacks registered against each captured filter, in registration order.
     *
     * @var array<string, array<int, callable>>
     */
    protected array $addedFilters = [];

    /**
     * The salts wp_salt() answers with, per scheme.
     *
     * Mutable because rotating a salt is a scenario under test: it is what a
     * site does after a suspected breach, and Reach's stored secrets are
     * expected to stop decrypting when it happens. The alias below reads this
     * array on every call, so assigning to it mid-test rotates the salt.
     *
     * @var array<string, string>
     */
    protected array $salts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->addedActions = [];
        $this->addedFilters = [];

        $this->salts = [
            'auth'      => 'test-auth-salt-' . str_repeat('x', 48),
            'logged_in' => 'test-login-salt-' . str_repeat('y', 48),
        ];

        // Safe to register here, unlike most setUp stubs: nothing overrides
        // wp_salt() per test — they mutate $this->salts instead — so the
        // one-stub-per-function-per-test rule is not in play.
        Functions\when('wp_salt')->alias(
            fn (string $scheme = 'auth'): string => $this->salts[$scheme] ?? 'fallback-salt'
        );
    }

    /**
     * Start recording the callbacks hung on an action.
     *
     * zeroOrMoreTimes() because "nothing was registered here" is a real
     * assertion in these tests — the admin pages only hook admin_menu when
     * is_admin() is true — and an unmet expectation would report that as a
     * failure of its own before the assertion could speak.
     */
    protected function captureAction(string $hook): void
    {
        Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(
            function (callable $callback, int $priority = 10, int $acceptedArgs = 1) use ($hook): void {
                $this->addedActions[$hook][] = $callback;
            }
        );
    }

    protected function captureFilter(string $hook): void
    {
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(
            function (callable $callback, int $priority = 10, int $acceptedArgs = 1) use ($hook): void {
                $this->addedFilters[$hook][] = $callback;
            }
        );
    }

    /**
     * @param array<int, string> $hooks
     */
    protected function captureActions(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $this->captureAction($hook);
        }
    }

    /**
     * @param array<int, string> $hooks
     */
    protected function captureFilters(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $this->captureFilter($hook);
        }
    }

    /**
     * Answer every outbound HTTP call with the given responder.
     *
     * The responder takes ($url, $args) and returns a WP HTTP response array
     * or a WP_Error, exactly as the old $GLOBALS['__reach_http_stub'] did.
     *
     * Deliberately not wp-mocks' Doubles\FakeWpHttp, which is a *queue*: these
     * tests need to answer differently depending on which URL was asked for —
     * a JWKS endpoint and a token endpoint fetched in the same flow — and a
     * queue cannot express that without depending on call order.
     */
    protected function stubHttp(callable $responder): void
    {
        foreach (['wp_remote_get', 'wp_remote_post', 'wp_remote_request'] as $function) {
            Functions\when($function)->alias($responder);
        }
    }

    /** @return array<int, callable> */
    protected function actionCallbacks(string $hook): array
    {
        return $this->addedActions[$hook] ?? [];
    }

    /** @return array<int, callable> */
    protected function filterCallbacks(string $hook): array
    {
        return $this->addedFilters[$hook] ?? [];
    }

    /**
     * A session value object for $email, with sensible defaults.
     *
     * Separate from the CurrentSession helpers so a test that needs an
     * odd session — expired, no id, a particular provider — can build
     * one and hand it to {@see currentSessionWith()}.
     */
    protected function sessionFor(string $email): Session
    {
        return new Session(
            email:     $email,
            provider:  'google',
            sub:       'oauth-sub-' . md5($email),
            issuedAt:  time(),
            expiresAt: time() + 3600,
            id:        Session::newId(),
        );
    }

    /**
     * A CurrentSession holding a signed-in, *authorised* session for
     * $email.
     *
     * Built by minting a real cookie and letting CurrentSession resolve
     * it, rather than by reflecting values into its private state.
     * That matters now that resolving a session is also an
     * authorisation decision: a helper that set `cached` directly would
     * hand every caller a session that had never been through the
     * eligibility gate, and the controller tests would then be passing
     * regardless of what that gate did.
     */
    protected function currentSessionFor(string $email): CurrentSession
    {
        return $this->currentSessionWith($this->sessionFor($email), new MemberStub($email));
    }

    /**
     * As {@see currentSessionFor()}, but with the member spelled out —
     * an ineligible one, or null for an email matching no member. Both
     * are cases CurrentSession must refuse, so both need saying
     * explicitly rather than defaulting.
     */
    protected function currentSessionWith(Session $session, ?Member $member): CurrentSession
    {
        $cookie = new SessionCookie();
        $_COOKIE[SessionCookie::COOKIE_NAME] = $cookie->sign($session);

        return new CurrentSession(
            $cookie,
            new InMemoryMemberRepository($member !== null ? [$member] : []),
            new SessionRevocationList(),
        );
    }

    /**
     * Attach the anti-CSRF header for $session to a request.
     *
     * Goes through set_header() rather than the constructor's header
     * array because WP_REST_Request canonicalises header names on the
     * way in as well as on lookup, and only set_header() does that in
     * the stub. A test that sets the raw array gets a header the
     * controller cannot find — which looks exactly like the token being
     * wrong.
     */
    protected function withSessionToken(WP_REST_Request $request, Session $session): WP_REST_Request
    {
        $request->set_header(SessionCsrf::HEADER, (new SessionCsrf())->mint($session));

        return $request;
    }

    /** A CurrentSession with no cookie at all — nobody is signed in. */
    protected function currentSessionSignedOut(): CurrentSession
    {
        unset($_COOKIE[SessionCookie::COOKIE_NAME]);

        return new CurrentSession(
            new SessionCookie(),
            new InMemoryMemberRepository([]),
            new SessionRevocationList(),
        );
    }
}
