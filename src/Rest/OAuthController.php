<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\AnonymisedEmailDetector;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\OutreachEligibility;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Devices\ResponderGate;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * REST controller: OAuth sign-in / sign-out.
 *
 * Routes:
 *
 *   GET  /reach/v1/oauth/start?provider={name}
 *        Mints CSRF state + nonce, redirects to the provider's
 *        authorisation endpoint. Server-side providers only.
 *
 *   GET  /reach/v1/oauth/callback?provider={name}&code=...&state=...
 *        Provider's redirect target. Validates state, exchanges code,
 *        sets the signed session cookie, redirects to the find page.
 *
 *   POST /reach/v1/oauth/apple
 *        Body: { id_token, state, code? }
 *        Apple's client-side flow lands here from the in-page JS.
 *
 *   POST /reach/v1/oauth/signout
 *        Clears the session cookie.
 *
 * If a provider proves an identity but only hands back an anonymised
 * relay address it can't be reached on (e.g. a Facebook
 * `*.facebook.com` relay when the user declines to share their real
 * email), sign-in is refused: Reach needs a real, contactable email
 * to verify the user, so the callback returns an error rather than
 * issuing a session.
 *
 * All routes are public — they *are* the authentication surface.
 * The state cookie + provider signature checks are what protect them.
 */
final class OAuthController
{
    public const NAMESPACE = 'reach/v1';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly StateStore $stateStore,
        private readonly SessionCookie $sessionCookie,
        private readonly MemberRepository $members,
        private readonly DeviceCodeStore $deviceCodes,
        private readonly DeviceRedirectValidator $deviceRedirects,
        private readonly ResponderGate $responderGate,
        private readonly CurrentSession $currentSession,
        private readonly SessionRevocationList $revocations,
        private readonly SessionCsrf $csrf,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/oauth/start',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'start'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'provider' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/oauth/callback',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'callback'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'code'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'state' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/oauth/apple/start',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'appleStart'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/oauth/apple',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'apple'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id_token' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'state'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/oauth/signout',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'signout'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $providerName = (string) $request->get_param('provider');
        $provider = $this->providers->get($providerName);
        if ($provider === null || !$provider->isServerSide()) {
            return new WP_Error('reach_unknown_provider', 'Unknown provider.', ['status' => 400]);
        }

        // Mint a PKCE verifier on every server-side flow. Providers
        // that don't use PKCE (Google, Microsoft) ignore it; providers
        // that do (Facebook) derive an S256 challenge from it. 32
        // random bytes → 64 hex chars, well within RFC 7636's 43–128
        // range.
        $codeVerifier = bin2hex(random_bytes(32));

        $tokens = $this->stateStore->issue($providerName, $this->homePageUrl(), $codeVerifier);
        $redirectUri = $this->callbackUrl();

        $authUrl = $provider->getAuthorizationUrl($tokens['state'], $tokens['nonce'], $redirectUri, $codeVerifier);

        return $this->redirect($authUrl);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $state = (string) $request->get_param('state');
        $code = (string) $request->get_param('code');

        $stored = $this->stateStore->consume($state);
        if ($stored === null) {
            // Stale link, back-button, or an expired attempt — common
            // enough that a raw JSON error would be a poor experience.
            // Send them back to sign-in with a "try again" notice.
            return $this->denyRedirect('signin_failed');
        }

        $provider = $this->providers->get($stored['provider']);
        if ($provider === null || !$provider->isServerSide()) {
            // Abnormal: provider names are fixed in the sign-in links,
            // so this only happens on tampering/misconfig. Keep it a
            // hard error rather than a friendly page.
            return new WP_Error('reach_unknown_provider', 'Unknown provider.', ['status' => 400]);
        }

        // Set when the flow was begun by the Hand app rather than by a
        // browser. It was validated against the allow-list at /start and
        // has been out of reach of the request ever since, so it can be
        // trusted here. Every refusal below routes back to the app when
        // it is set: a friendly page rendered inside an in-app browser
        // tab is a dead end the app never hears about, so it would hang
        // on the sign-in sheet until the responder gave up.
        $deviceRedirect = $stored['device_redirect'];

        $identity = $provider->handleCallback($code, $stored['nonce'], $this->callbackUrl(), $stored['code_verifier']);
        if ($identity === null) {
            return $this->denyFor($deviceRedirect, 'signin_failed');
        }

        $returnTo = $stored['return_to'] !== '' ? $stored['return_to'] : $this->homePageUrl();

        // If the provider proved who the user is but only gave us a
        // relay address we can't treat as a contact email, refuse
        // sign-in: Reach needs a real, reachable email to verify the
        // user. Today this only fires for Facebook; the
        // AnonymisedEmailDetector is the single source of truth on
        // what counts as anonymised.
        if (AnonymisedEmailDetector::isAnonymised($identity->email)) {
            return $this->denyFor($deviceRedirect, 'email_required');
        }

        if (($denied = $this->assertMemberAllowed($identity)) !== null) {
            return $this->denyFor($deviceRedirect, $this->errorSlug($denied));
        }

        if ($deviceRedirect !== null) {
            return $this->completeDeviceSignIn($identity, $deviceRedirect);
        }

        $this->issueSessionFor($identity);
        // return_to is the only redirect target that could be influenced
        // by a tampered link, so clamp it to this site here.
        return $this->redirect($this->safeInternalUrl($returnTo));
    }

    /**
     * Finish a sign-in that the Hand app started.
     *
     * No session cookie is minted: a native app is not a browser and has
     * no use for one. Instead the proven identity becomes a single-use
     * exchange code, which the app trades for a device token over TLS.
     * The code — not the token — is what travels back through the
     * browser; see {@see \Reach\Auth\DeviceCodeStore} for why.
     *
     * The gate applied here is Hand's, which is stricter than the one
     * {@see assertMemberAllowed()} has already run: the website admits
     * 12th-steppers, the helpline handset admits only certified
     * telephone responders. A 12th-stepper therefore gets this far and
     * is turned away at this last step, with the same `not_eligible`
     * slug the app renders for every refusal.
     */
    private function completeDeviceSignIn(VerifiedIdentity $identity, string $deviceRedirect): WP_REST_Response
    {
        if ($this->responderGate->authorisedMember($identity->email) === null) {
            return $this->denyFor($deviceRedirect, 'not_eligible');
        }

        $code = $this->deviceCodes->issue($identity);

        return $this->redirect(
            $this->deviceRedirects->withParams($deviceRedirect, ['code' => $code]),
        );
    }

    /**
     * Refuse a sign-in, routing the refusal wherever the flow came from:
     * back into the app when this was a device flow, otherwise to the
     * styled notice on the sign-in page.
     */
    private function denyFor(?string $deviceRedirect, string $slug): WP_REST_Response
    {
        if ($deviceRedirect === null) {
            return $this->denyRedirect($slug);
        }

        return $this->redirect(
            $this->deviceRedirects->withParams($deviceRedirect, ['error' => $slug]),
        );
    }

    public function apple(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $idToken = (string) $request->get_param('id_token');
        $state = (string) $request->get_param('state');

        $stored = $this->stateStore->consume($state);
        if ($stored === null || $stored['provider'] !== 'apple') {
            return new WP_Error('reach_invalid_state', 'Invalid or expired sign-in attempt.', ['status' => 400]);
        }

        $provider = $this->providers->get('apple');
        if ($provider === null) {
            return new WP_Error('reach_unknown_provider', 'Apple sign-in is not configured.', ['status' => 400]);
        }

        $identity = $provider->verifyIdToken($idToken, $stored['nonce']);
        if ($identity === null) {
            return new WP_Error('reach_signin_failed', 'Sign-in failed.', ['status' => 401]);
        }

        // Apple's privaterelay is treated as a real contact address
        // (Apple forwards it), so this branch is currently dormant for
        // Apple. Wired through anyway in case the detector's policy
        // ever broadens: an anonymised address means we have no
        // reachable email, so sign-in is refused.
        if (AnonymisedEmailDetector::isAnonymised($identity->email)) {
            return $this->emailRequiredError();
        }

        if (($denied = $this->assertMemberAllowed($identity)) !== null) {
            return $denied;
        }

        $this->issueSessionFor($identity);
        return new WP_REST_Response(['redirect' => $this->homePageUrl()], 200);
    }

    /**
     * Refuse sign-in when the provider only gave us an anonymised
     * relay address. Reach verifies people by a real, reachable email,
     * so without one there's nothing to verify and access is denied.
     *
     * Returned from both entry points (the server-side callback and
     * the Apple POST) so the message stays consistent. 403 mirrors the
     * eligibility gate: the identity was proven, the request is just
     * not allowed to proceed.
     */
    private function emailRequiredError(): WP_Error
    {
        return new WP_Error(
            'reach_email_required',
            'An email address is required for access. The provider you signed in with didn\'t share a usable email address, so we can\'t verify you. Please sign in again and choose to share your email, or use a different provider.',
            ['status' => 403]
        );
    }

    /**
     * Mint and write the signed session cookie for an identity.
     * Centralised so the cookie shape stays consistent across the
     * two entry points (server-side callback and Apple POST).
     */
    private function issueSessionFor(VerifiedIdentity $identity): void
    {
        $now = time();
        $session = new Session(
            $identity->email,
            $identity->provider,
            $identity->sub,
            $now,
            $now + SessionCookie::TTL_SECONDS,
            $identity->providerEmail,
            Session::newId(),
        );
        $this->sessionCookie->issue($session);
        // The cookie was written mid-request; anything downstream that
        // asks for the current session must see this one rather than
        // whatever was cached before it existed.
        $this->currentSession->invalidate();
    }

    /**
     * Gate sign-in on the member's role.
     *
     * A verified identity whose email doesn't match any member, matches
     * a member with neither outreach role, or matches an uncertified
     * responder, is rejected here so the session cookie is never minted.
     * That keeps the downstream code (NearestMembersController,
     * CallAttemptController) able to assume any authenticated session
     * belongs to someone entitled to use Reach.
     *
     * The rule itself lives in {@see \Reach\Auth\OutreachEligibility},
     * which all three sign-in paths now consult rather than restate —
     * see that class for what went wrong when they each kept their own
     * copy.
     *
     * Returns null when sign-in may proceed, or a WP_Error suitable for
     * returning from the calling REST callback otherwise.
     */
    private function assertMemberAllowed(VerifiedIdentity $identity): ?WP_Error
    {
        $member = $this->members->findByEmail($identity->email);
        if (!OutreachEligibility::permits($member)) {
            return new WP_Error(
                'reach_not_eligible',
                'This account is not registered to use Reach. Please contact your intergroup if you believe this is in error.',
                ['status' => 403]
            );
        }
        return null;
    }

    /**
     * Provide a state + nonce to the in-page Apple SDK *without*
     * starting a redirect. Called by find.js before invoking
     * AppleID.auth.signIn() so the resulting ID token can be tied
     * back to this sign-in attempt.
     */
    public function appleStart(WP_REST_Request $request): WP_REST_Response
    {
        $tokens = $this->stateStore->issue('apple', $this->homePageUrl());
        return new WP_REST_Response(['state' => $tokens['state'], 'nonce' => $tokens['nonce']], 200);
    }

    /**
     * Sign out: revoke the session server-side, then clear the cookie.
     *
     * Clearing the cookie alone only removes the browser's copy — the
     * token stays signed and valid until it expires, so any other copy
     * of it keeps working. Recording the id in
     * {@see SessionRevocationList} is what actually ends the session.
     *
     * The session is read through {@see CurrentSession::raw()} rather
     * than `get()`: a session that is already refused — an expired
     * certification, say — is still worth revoking, and refusing to
     * sign it out because it is not authorised would be exactly
     * backwards.
     *
     * Always reports success, including when there was nothing to sign
     * out. A caller asking to be signed out has got the outcome it
     * asked for, and saying otherwise only tells an unauthenticated
     * prober whether a cookie was valid.
     */
    public function signout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session = $this->currentSession->raw();

        if ($session !== null) {
            if (!$this->csrf->verify($request, $session)) {
                return $this->csrfError();
            }

            $this->revocations->revoke($session->id, $session->expiresAt, time());
        }

        $this->sessionCookie->clear();
        $this->currentSession->invalidate();

        return new WP_REST_Response(['signed_out' => true], 200);
    }

    /**
     * The refusal for a write that arrived without a valid session
     * token. 403 rather than 401: the caller is authenticated — the
     * cookie is what got the request this far — but this particular
     * request is not one Reach will act on. Shared with the other
     * cookie-authenticated write endpoints so the client can handle one
     * shape; see {@see SessionCsrf}.
     */
    private function csrfError(): WP_Error
    {
        return new WP_Error(
            'reach_invalid_session_token',
            'That request could not be verified. Please reload the page and try again.',
            ['status' => 403],
        );
    }

    private function callbackUrl(): string
    {
        return rest_url(self::NAMESPACE . '/oauth/callback');
    }

    private function homePageUrl(): string
    {
        // After sign-in the visitor lands on the menu (Search / Shift sign-up).
        return home_url('/reach/home');
    }

    /**
     * Bounce the browser back to the sign-in page carrying a
     * `reach_error` code the template turns into a friendly,
     * styled notice. Used for the server-side (redirect) flow, where
     * returning a WP_Error would otherwise render as a raw JSON page
     * in the browser.
     */
    private function denyRedirect(string $code): WP_REST_Response
    {
        return $this->redirect($this->signinErrorUrl($code));
    }

    private function signinErrorUrl(string $code): string
    {
        return add_query_arg('reach_error', $code, home_url('/reach/signin'));
    }

    /**
     * Reduce a WP_Error code to the short slug the sign-in template
     * keys its notices on, e.g. `reach_not_eligible` → `not_eligible`.
     * Unknown slugs fall back to the template's generic message.
     */
    private function errorSlug(WP_Error $error): string
    {
        $code = $error->get_error_code();
        return is_string($code) && str_starts_with($code, 'reach_')
            ? substr($code, strlen('reach_'))
            : 'signin_failed';
    }

    /**
     * Emit a 302 to an arbitrary URL.
     *
     * This helper must pass URLs through verbatim — start() uses it to
     * send the browser to the provider's *external* authorisation
     * endpoint (e.g. accounts.google.com), which an internal-only
     * allow-list check would wrongly reject. Open-redirect protection for
     * the one caller that takes an attacker-influenceable target (the
     * callback's return_to) is applied at that call site via
     * {@see safeInternalUrl()}, not here.
     */
    private function redirect(string $url): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        return $response;
    }

    /**
     * Clamp a post-sign-in return target to this site, falling back to the
     * home page if the host isn't permitted — the string-returning
     * equivalent of wp_safe_redirect() (which we can't use here because we
     * emit a REST Location header rather than sending headers ourselves).
     */
    private function safeInternalUrl(string $url): string
    {
        return wp_validate_redirect($url, $this->homePageUrl());
    }
}
