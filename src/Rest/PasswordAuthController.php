<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordResetResult;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\RateLimiter;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function home_url;
use function register_rest_route;

/**
 * REST controller: email + password sign-in and the emailed set/reset flow.
 *
 * The second authentication surface alongside {@see OAuthController}. It
 * shares that controller's shape deliberately: the {@see PasswordAuthenticator}
 * proves the identity, this controller applies the same member-eligibility
 * gate (12th-stepper / telephone responder) and mints the same signed
 * {@see Session} cookie, tagged `provider = "password"`.
 *
 * Routes (all public — they *are* the auth surface, like /oauth/*):
 *
 *   POST /reach/v1/auth/login          { email, password }
 *        On success sets the session cookie and returns { redirect }.
 *        Any failure returns a single generic 401 — a wrong password, an
 *        unknown email and a locked account are indistinguishable.
 *
 *   POST /reach/v1/auth/request-reset  { email }
 *        Always returns { sent: true }. A link is emailed only when the
 *        address is an eligible member, but the response never says so
 *        (no account enumeration).
 *
 *   POST /reach/v1/auth/set-password   { token, password }
 *        Validates the one-time token, stores the new password, and (if the
 *        member is still eligible) signs them in — returning { redirect }.
 */
final class PasswordAuthController
{
    public const NAMESPACE = 'reach/v1';

    /** Per-IP login attempts allowed per window, and the window length. */
    private const LOGIN_IP_MAX = 50;
    private const LOGIN_IP_WINDOW = 15 * 60;

    /** Per-IP reset requests allowed per window, and the window length. */
    private const RESET_IP_MAX = 10;
    private const RESET_IP_WINDOW = 60 * 60;

    public function __construct(
        private readonly PasswordAuthenticator $authenticator,
        private readonly SessionCookie $sessionCookie,
        private readonly MemberRepository $members,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // Passwords are intentionally NOT run through sanitize_text_field:
        // it trims/collapses whitespace and strips angle brackets, which
        // would silently alter a chosen password. They're hashed, never
        // echoed, so there's nothing to sanitise for.
        register_rest_route(
            self::NAMESPACE,
            '/auth/login',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'login'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'email'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'password' => ['type' => 'string', 'required' => true],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/request-reset',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'requestReset'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'email' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/set-password',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'setPassword'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'token'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'password' => ['type' => 'string', 'required' => true],
                ],
            ]
        );
    }

    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $email    = (string) $request->get_param('email');
        $password = (string) $request->get_param('password');
        $now      = time();

        // Coarse per-IP throttle in front of the per-account lockout, so a
        // single source can't grind through many accounts (or hammer one).
        if ($this->rateLimiter->overLimit('login:' . $this->rateLimiter->clientIp(), self::LOGIN_IP_MAX, self::LOGIN_IP_WINDOW)) {
            return $this->tooManyAttempts();
        }

        $identity = $this->authenticator->attemptLogin($email, $password, $now);
        if ($identity === null) {
            return $this->invalidCredentials();
        }

        // Same gate as OAuth: only outreach members may hold a session.
        $member = $this->eligibleMember($identity->email);
        if ($member === null) {
            return $this->notEligible();
        }

        $this->issueSessionFor($identity, $now);
        $this->auditLogger->log(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $member->getId(),
            'authentication',
            'Password sign-in',
        );

        return new WP_REST_Response(['redirect' => $this->homePageUrl()], 200);
    }

    public function requestReset(WP_REST_Request $request): WP_REST_Response
    {
        $email = (string) $request->get_param('email');

        // Per-IP flood cap (on top of the per-email cooldown in beginReset).
        // When exceeded we skip sending but still return the same response,
        // so a flooder gets no signal and no email goes out.
        if (!$this->rateLimiter->overLimit('reset:' . $this->rateLimiter->clientIp(), self::RESET_IP_MAX, self::RESET_IP_WINDOW)) {
            // beginReset is a silent no-op for anything but an eligible member,
            // so this always returns the same thing regardless of whether a
            // link was actually sent.
            $this->authenticator->beginReset($email, time());
        }

        return new WP_REST_Response(['sent' => true], 200);
    }

    public function setPassword(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token    = (string) $request->get_param('token');
        $password = (string) $request->get_param('password');
        $now      = time();

        $result = $this->authenticator->completeReset($token, $password, $now);

        // A bad/expired/spent token is the blocking error and takes
        // precedence — the password is irrelevant if the link is dead.
        if ($result->status === PasswordResetResult::INVALID_TOKEN) {
            return new WP_Error(
                'reach_invalid_token',
                'This password link is invalid or has expired. Please request a new one.',
                ['status' => 400],
            );
        }

        // Token was valid but the password fails the strength policy; the
        // link is left unspent so the member can try again.
        if ($result->status === PasswordResetResult::WEAK_PASSWORD) {
            return new WP_Error(
                'reach_weak_password',
                $result->message,
                ['status' => 422],
            );
        }

        $email = $result->email;

        // The password is set. Auto sign-in — but re-run the eligibility gate
        // in case the member's role changed since the link was issued. If
        // they're no longer eligible, the password still stands; we just
        // can't hand them a session, so send them to sign-in.
        $member = $this->eligibleMember($email);
        if ($member === null) {
            return new WP_REST_Response(['redirect' => $this->signInUrl(), 'signed_in' => false], 200);
        }

        $identity = new VerifiedIdentity(
            email: $email,
            provider: PasswordAuthenticator::PROVIDER,
            sub: $email,
        );
        $this->issueSessionFor($identity, $now);
        $this->auditLogger->log(
            AuditLogger::ACTION_UPDATE,
            AuditLogger::ENTITY_MEMBER,
            $member->getId(),
            'password',
            'Password set via reset link',
        );

        return new WP_REST_Response(['redirect' => $this->homePageUrl(), 'signed_in' => true], 200);
    }

    /**
     * The member for $email if they exist and hold an outreach role, else
     * null. Mirrors OAuthController's assertMemberAllowed gate so the two
     * sign-in paths admit exactly the same people.
     */
    private function eligibleMember(string $email): ?Member
    {
        $member = $this->members->findByEmail($email);
        if ($member === null || (!$member->isTwelfthStepper() && !$member->isTelephoneResponder())) {
            return null;
        }
        return $member;
    }

    private function issueSessionFor(VerifiedIdentity $identity, int $now): void
    {
        $session = new Session(
            $identity->email,
            $identity->provider,
            $identity->sub,
            $now,
            $now + SessionCookie::TTL_SECONDS,
        );
        $this->sessionCookie->issue($session);
    }

    private function invalidCredentials(): WP_Error
    {
        // One message for every failure mode — wrong password, unknown
        // email, no password set, or a locked account — so none can be told
        // apart by the response.
        return new WP_Error(
            'reach_invalid_credentials',
            'Email or password is incorrect.',
            ['status' => 401],
        );
    }

    private function tooManyAttempts(): WP_Error
    {
        return new WP_Error(
            'reach_rate_limited',
            'Too many attempts. Please wait a little while and try again.',
            ['status' => 429],
        );
    }

    private function notEligible(): WP_Error
    {
        return new WP_Error(
            'reach_not_eligible',
            'This account is not registered to use Reach. Please contact your intergroup if you believe this is in error.',
            ['status' => 403],
        );
    }

    private function homePageUrl(): string
    {
        return home_url('/reach/home');
    }

    private function signInUrl(): string
    {
        return home_url('/reach/signin');
    }
}
