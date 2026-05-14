<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
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
 *   POST /reach/v1/oauth/signout
 *        Clears the session cookie.
 *
 * All four routes are public — they *are* the authentication surface.
 * The state cookie + provider signature checks are what protect them.
 */
final class OAuthController
{
    public const NAMESPACE = 'reach/v1';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly StateStore $stateStore,
        private readonly SessionCookie $sessionCookie,
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

        $tokens = $this->stateStore->issue($providerName, $this->findPageUrl());
        $redirectUri = $this->callbackUrl();

        $authUrl = $provider->getAuthorizationUrl($tokens['state'], $tokens['nonce'], $redirectUri);

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

        $identity = $provider->handleCallback($code, $stored['nonce'], $this->callbackUrl());
        if ($identity === null) {
            return new WP_Error('reach_signin_failed', 'Sign-in failed. Please try again.', ['status' => 401]);
        }

        $now = time();
        $session = new Session(
            $identity->email,
            $identity->provider,
            $identity->sub,
            $now,
            $now + SessionCookie::TTL_SECONDS,
        );
        $this->sessionCookie->issue($session);

        $returnTo = $stored['return_to'] !== '' ? $stored['return_to'] : $this->findPageUrl();
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

        $now = time();
        $session = new Session(
            $identity->email,
            $identity->provider,
            $identity->sub,
            $now,
            $now + SessionCookie::TTL_SECONDS,
        );
        $this->sessionCookie->issue($session);

        return new WP_REST_Response(['redirect' => $this->findPageUrl()], 200);
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

    private function redirect(string $url): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        return $response;
    }
}
