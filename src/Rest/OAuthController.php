<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\AnonymisedEmailDetector;
use Reach\Auth\PendingIdentityStore;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
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
 *   POST /reach/v1/oauth/complete-email
 *        Body: { pending, email }
 *        Lands here from the /reach/email form, which is shown only
 *        when a provider returned an anonymised relay address (e.g.
 *        Facebook). Issues the real session with the typed email.
 *
 *   POST /reach/v1/oauth/signout
 *        Clears the session cookie.
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
        private readonly PendingIdentityStore $pendingIdentities,
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
            '/oauth/complete-email',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'completeEmail'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'pending' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'email'   => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email'],
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

        $tokens = $this->stateStore->issue($providerName, $this->findPageUrl(), $codeVerifier);
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
            return new WP_Error('reach_invalid_state', 'Invalid or expired sign-in attempt.', ['status' => 400]);
        }

        $provider = $this->providers->get($stored['provider']);
        if ($provider === null || !$provider->isServerSide()) {
            return new WP_Error('reach_unknown_provider', 'Unknown provider.', ['status' => 400]);
        }

        $identity = $provider->handleCallback($code, $stored['nonce'], $this->callbackUrl(), $stored['code_verifier']);
        if ($identity === null) {
            return new WP_Error('reach_signin_failed', 'Sign-in failed. Please try again.', ['status' => 401]);
        }

        $returnTo = $stored['return_to'] !== '' ? $stored['return_to'] : $this->findPageUrl();

        // If the provider gave us a relay address we don't trust as a
        // contact address, divert to the typed-email form rather than
        // issue a session against it. Today this only fires for
        // Facebook; the AnonymisedEmailDetector is the single source
        // of truth on what counts.
        if (AnonymisedEmailDetector::isAnonymised($identity->email)) {
            $token = $this->pendingIdentities->issue(
                new VerifiedIdentity(
                    $identity->email,
                    $identity->provider,
                    $identity->sub,
                    // Preserve the original relay as providerEmail; if
                    // the identity already had one set, the provider
                    // explicitly told us so and we keep that.
                    $identity->providerEmail ?? $identity->email,
                ),
                $returnTo
            );
            return $this->redirect($this->emailPageUrl($token));
        }

        $this->issueSessionFor($identity);
        return $this->redirect($returnTo);
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
        // ever broadens.
        if (AnonymisedEmailDetector::isAnonymised($identity->email)) {
            $token = $this->pendingIdentities->issue(
                new VerifiedIdentity(
                    $identity->email,
                    $identity->provider,
                    $identity->sub,
                    $identity->providerEmail ?? $identity->email,
                ),
                $this->findPageUrl()
            );
            return new WP_REST_Response(['redirect' => $this->emailPageUrl($token)], 200);
        }

        $this->issueSessionFor($identity);
        return new WP_REST_Response(['redirect' => $this->findPageUrl()], 200);
    }

    /**
     * Land here from the /reach/email form. Consume the pending-
     * identity token, validate the typed address, and only then mint
     * the real session.
     *
     * The typed email cannot itself be a relay address — that would
     * defeat the whole point of asking.
     */
    public function completeEmail(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = (string) $request->get_param('pending');
        $typed = trim((string) $request->get_param('email'));

        $pending = $this->pendingIdentities->consume($token);
        if ($pending === null) {
            return new WP_Error('reach_invalid_pending', 'This sign-in attempt has expired. Please start again.', ['status' => 400]);
        }

        if ($typed === '' || !is_email($typed)) {
            return new WP_Error('reach_invalid_email', 'Please enter a valid email address.', ['status' => 400]);
        }
        if (AnonymisedEmailDetector::isAnonymised($typed)) {
            return new WP_Error('reach_relay_email', 'Please enter your real email address, not a relay one.', ['status' => 400]);
        }

        $original = $pending['identity'];
        $promoted = new VerifiedIdentity(
            strtolower($typed),
            $original->provider,
            $original->sub,
            $original->providerEmail,
        );
        $this->issueSessionFor($promoted);

        $returnTo = $pending['return_to'] !== '' ? $pending['return_to'] : $this->findPageUrl();
        return new WP_REST_Response(['redirect' => $returnTo], 200);
    }

    /**
     * Mint and write the signed session cookie for an identity.
     * Centralised so the cookie shape stays consistent across the
     * three entry points (server-side callback, Apple POST, typed-
     * email completion).
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
        );
        $this->sessionCookie->issue($session);
    }

    /**
     * Provide a state + nonce to the in-page Apple SDK *without*
     * starting a redirect. Called by find.js before invoking
     * AppleID.auth.signIn() so the resulting ID token can be tied
     * back to this sign-in attempt.
     */
    public function appleStart(WP_REST_Request $request): WP_REST_Response
    {
        $tokens = $this->stateStore->issue('apple', $this->findPageUrl());
        return new WP_REST_Response(['state' => $tokens['state'], 'nonce' => $tokens['nonce']], 200);
    }

    public function signout(): WP_REST_Response
    {
        $this->sessionCookie->clear();
        return new WP_REST_Response(['signed_out' => true], 200);
    }

    private function callbackUrl(): string
    {
        return rest_url(self::NAMESPACE . '/oauth/callback');
    }

    private function findPageUrl(): string
    {
        return home_url('/reach/find');
    }

    /**
     * URL of the typed-email completion page, with the pending-
     * identity token attached as a query string. The token is opaque
     * to the page — the form posts it back to /oauth/complete-email
     * and the controller is the only thing that can decode it.
     */
    private function emailPageUrl(string $pendingToken): string
    {
        return add_query_arg('pending', $pendingToken, home_url('/reach/email'));
    }

    private function redirect(string $url): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        return $response;
    }
}
