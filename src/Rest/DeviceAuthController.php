<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\DeviceTokenMinter;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Core\RateLimiter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use RuntimeException;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;
use function rest_url;

/**
 * REST controller: enrolling and managing Hand handsets.
 *
 * The third authentication surface, alongside {@see OAuthController}
 * (browser + cookie) and {@see PasswordAuthController} (browser +
 * cookie). This one issues bearer tokens to a native app, and admits
 * only certified telephone responders — see {@see ResponderGate} for
 * why Hand's gate is stricter than the website's.
 *
 * <b>Two ways in, one way out.</b> Both enrolment paths end at the same
 * place: a proven identity, checked against the gate, exchanged for a
 * device token. What differs is only how the identity is proven.
 *
 *   SSO — Hand opens `/auth/device/start` in the system browser, which
 *   is reach's ordinary OAuth flow carrying a validated app redirect.
 *   The existing callback recognises the device-flagged state, mints a
 *   one-time code instead of a session cookie, and bounces it to the
 *   app, which posts it to `/auth/device/exchange`. The code, rather
 *   than the token, travels through the browser — see
 *   {@see DeviceCodeStore} for why that distinction matters.
 *
 *   Password — Hand posts credentials straight to
 *   `/auth/device/password`. No browser, no redirect, no custom scheme.
 *   This is what the Windows head uses when it is not packaged as MSIX
 *   and cannot claim one, and it is the fallback anywhere the browser
 *   round trip fails.
 *
 * Routes:
 *
 *   GET  /reach/v1/auth/device/start?provider=&redirect_uri=
 *   POST /reach/v1/auth/device/exchange   { code, label, platform, ... }
 *   POST /reach/v1/auth/device/password   { email, password, label, platform, ... }
 *   POST /reach/v1/auth/device/push       { push_provider, push_token }   [Bearer]
 *   GET  /reach/v1/auth/device/session                                    [Bearer]
 *   POST /reach/v1/auth/device/signout                                    [Bearer]
 *
 * The first three are public — they *are* the auth surface. The last
 * three authenticate with the device token itself.
 */
final class DeviceAuthController
{
    use \Reach\Logger\HasLogger;
    use RequiresSecureTransport;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    public const NAMESPACE = 'reach/v1';

    /** Per-IP enrolment attempts allowed per window, and the window length. */
    private const ENROL_IP_MAX = 30;
    private const ENROL_IP_WINDOW = 15 * 60;

    /**
     * Handsets one responder may have enrolled at once.
     *
     * A responder legitimately has a couple — a phone and a desktop,
     * perhaps a tablet. The cap exists so a looping client cannot grow
     * the table without bound, and so a stack of forgotten enrolments
     * doesn't quietly become a stack of places alerts are still being
     * delivered. Reaching it revokes the least recently seen handset
     * rather than refusing the new one: someone standing there with a
     * new phone should not be locked out by an old one.
     */
    private const MAX_DEVICES_PER_MEMBER = 5;

    /** Defensive caps mirroring the column widths in WpdbDeviceRepository::install. */
    private const LABEL_MAX_BYTES = 200;
    private const PUSH_TOKEN_MAX_BYTES = 512;

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceTokenMinter $minter,
        private readonly DeviceCodeStore $codes,
        private readonly DeviceRedirectValidator $redirects,
        private readonly ResponderGate $gate,
        private readonly CurrentDevice $currentDevice,
        private readonly PasswordAuthenticator $authenticator,
        private readonly ProviderRegistry $providers,
        private readonly StateStore $stateStore,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogger $auditLogger,
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
            '/auth/device/start',
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
                    // Deliberately NOT sanitize_text_field: it strips
                    // characters a URI legitimately contains. The value is
                    // validated as a whole against the allow-list instead,
                    // which is a stronger check than sanitising parts of it.
                    'redirect_uri' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/device/exchange',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'exchange'],
                'permission_callback' => '__return_true',
                'args'                => $this->enrolmentArgs([
                    'code' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ]),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/device/password',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'password'],
                'permission_callback' => '__return_true',
                // Passwords are not run through sanitize_text_field, for
                // the reason PasswordAuthController gives: it would
                // silently alter a chosen password.
                'args'                => $this->enrolmentArgs([
                    'email'    => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'password' => ['type' => 'string', 'required' => true],
                ]),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/device/push',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'updatePush'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'push_provider' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'push_token' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/device/session',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'session'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/auth/device/signout',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'signout'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Argument spec shared by the two enrolment endpoints, merged with
     * whatever identifies the caller on that path.
     *
     * @param array<string, array<string, mixed>> $extra
     * @return array<string, array<string, mixed>>
     */
    private function enrolmentArgs(array $extra): array
    {
        return $extra + [
            'label' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'platform' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'push_provider' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
            ],
            'push_token' => [
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Begin an SSO sign-in for a handset.
     *
     * Mirrors {@see OAuthController::start()} — same providers, same
     * PKCE verifier for every server-side flow — differing only in
     * that the validated app redirect is stashed with the state, which
     * is what tells the shared callback to end in an exchange code
     * rather than a cookie.
     */
    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $redirectUri = (string) $request->get_param('redirect_uri');
        if (!$this->redirects->isAllowed($redirectUri)) {
            // See DeviceRedirectValidator: the response says nothing
            // about why, so probing the allow-list yields nothing.
            return new WP_Error(
                'reach_invalid_redirect',
                'That redirect target is not permitted.',
                ['status' => 400],
            );
        }

        $providerName = (string) $request->get_param('provider');
        $provider = $this->providers->get($providerName);
        if ($provider === null || !$provider->isServerSide()) {
            return new WP_Error('reach_unknown_provider', 'Unknown provider.', ['status' => 400]);
        }

        $codeVerifier = bin2hex(random_bytes(32));
        $tokens = $this->stateStore->issue($providerName, '', $codeVerifier, $redirectUri);
        $callbackUrl = rest_url(OAuthController::NAMESPACE . '/oauth/callback');

        $authUrl = $provider->getAuthorizationUrl(
            $tokens['state'],
            $tokens['nonce'],
            $callbackUrl,
            $codeVerifier,
        );

        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $authUrl);
        return $response;
    }

    /**
     * Trade a one-time code from the SSO flow for a device token.
     */
    public function exchange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        if ($this->overEnrolmentLimit()) {
            return $this->tooManyAttempts();
        }

        $identity = $this->codes->consume((string) $request->get_param('code'));
        if ($identity === null) {
            return new WP_Error(
                'reach_invalid_code',
                'That sign-in link has expired. Please sign in again.',
                ['status' => 400],
            );
        }

        // Re-run the gate even though the callback already did: a role
        // can be withdrawn between the browser redirect and this call.
        $member = $this->gate->authorisedMember($identity->email);
        if ($member === null) {
            return $this->notEligible();
        }

        return $this->enrol($request, $member, $identity->provider);
    }

    /**
     * Enrol a handset with an email and password, no browser involved.
     */
    public function password(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        if ($this->overEnrolmentLimit()) {
            return $this->tooManyAttempts();
        }

        $identity = $this->authenticator->attemptLogin(
            (string) $request->get_param('email'),
            (string) $request->get_param('password'),
            time(),
        );

        if ($identity === null) {
            return $this->invalidCredentials();
        }

        $member = $this->gate->authorisedMember($identity->email);
        if ($member === null) {
            // Distinct from invalid credentials on purpose. The
            // password was right; there is nothing to hide about the
            // account's existence at this point, and "your certification
            // has lapsed" is the message the responder needs in order to
            // do something about it.
            return $this->notEligible();
        }

        return $this->enrol($request, $member, PasswordAuthenticator::PROVIDER);
    }

    /**
     * Record a fresh push registration for the calling handset.
     *
     * Firebase rotates registration tokens without warning, and a stale
     * token is the single most common reason a handset stops ringing.
     * Hand calls this whenever the platform hands it a new one.
     */
    public function updatePush(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $device = $this->currentDevice->fromRequest($request, time());
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $provider = $this->normalisePushProvider((string) $request->get_param('push_provider'));
        $token    = $this->cap((string) $request->get_param('push_token'), self::PUSH_TOKEN_MAX_BYTES);

        // A push provider with no token is a device that will never be
        // pushed to and doesn't know it. Refuse rather than store the
        // half-state.
        if ($provider !== Device::PUSH_NONE && $token === '') {
            return new WP_Error(
                'reach_missing_push_token',
                'A push token is required for that push provider.',
                ['status' => 400],
            );
        }

        $this->devices->updatePushToken($device->id, $provider, $token);

        return new WP_REST_Response([
            'updated'       => true,
            'push_provider' => $provider,
        ], 200);
    }

    /**
     * Who this handset is, and confirmation that it is still allowed.
     *
     * Hand calls it at launch. A 401 here is the app's signal to drop
     * its stored token and show sign-in — which is how a revoked
     * certification reaches the handset as a message rather than as
     * alerts that silently stop arriving.
     */
    public function session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $member = $this->currentDevice->memberFor($device);

        return new WP_REST_Response([
            'authorised'    => true,
            'device_id'     => $device->id,
            'responder'     => $member !== null ? $member->getAnonymousName() : '',
            'platform'      => $device->platform,
            'push_provider' => $device->pushProvider,
            'label'         => $device->label,
        ], 200);
    }

    /**
     * Sign this handset out, revoking its token.
     *
     * Always reports success. A caller signing out with a token that is
     * already dead has got the outcome it asked for, and saying so
     * spares the app an error path for a state it cannot act on.
     */
    public function signout(WP_REST_Request $request): WP_REST_Response
    {
        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device !== null) {
            $this->devices->revoke($device->id, $now);
        }

        return new WP_REST_Response(['signed_out' => true], 200);
    }

    /**
     * Mint a token, store the handset, and answer the app.
     *
     * Shared tail of both enrolment paths — the point at which they
     * become indistinguishable.
     */
    private function enrol(WP_REST_Request $request, Member $member, string $provider): WP_REST_Response|WP_Error
    {
        $platform = Device::normalisePlatform((string) $request->get_param('platform'));
        if ($platform === '') {
            return new WP_Error(
                'reach_unknown_platform',
                'Unknown device platform.',
                ['status' => 400],
            );
        }

        $pushProvider = $this->normalisePushProvider((string) $request->get_param('push_provider'));
        $pushToken    = $this->cap((string) $request->get_param('push_token'), self::PUSH_TOKEN_MAX_BYTES);
        if ($pushProvider === Device::PUSH_NONE) {
            // No transport means no token to keep, whatever was sent.
            $pushToken = '';
        }

        $label = $this->cap((string) $request->get_param('label'), self::LABEL_MAX_BYTES);
        $email = strtolower(trim($member->getPersonalEmail()));
        $now   = time();

        $this->pruneDevicesFor($email, $now);

        $token = $this->minter->mint();

        // A failed write must not become a successful-looking enrolment.
        // The repository throws rather than returning a row with id 0, so
        // the responder is told sign-in failed and can try again, instead
        // of being handed a token that 401s on its next use and sends them
        // round the sign-in loop with no explanation.
        // The secret alert payloads will be encrypted to. Minted here
        // beside the bearer token because they are the same kind of
        // thing — credentials this handset gets once, at enrolment — and
        // one place that mints them is easier to reason about than two.
        // 32 bytes because that is an AES-256 key; base64 so it survives
        // JSON and a string column.
        $payloadKey = base64_encode(random_bytes(32));

        try {
            $device = $this->devices->create(
                $this->minter->hash($token),
                $email,
                $member->getId(),
                $label,
                $platform,
                $pushProvider,
                $pushToken,
                $now,
                $payloadKey,
            );
        } catch (RuntimeException $e) {
            self::logError('Handset enrolment failed', [
                'member' => $member->getId(),
                'error'  => $e->getMessage(),
            ]);

            return new WP_Error(
                'reach_enrolment_failed',
                'This handset could not be enrolled. Please try again, and tell your intergroup if it keeps happening.',
                ['status' => 500],
            );
        }

        $this->auditLogger->log(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $member->getId(),
            'authentication',
            'Hand device enrolled via ' . $provider,
        );

        // Enrolling again is the remedy for a key fault, so the warning
        // goes with it. A stale warning about a handset that has since
        // been repaired is worse than none: it trains an admin to ignore
        // the column.
        $this->devices->clearKeyFault($device->id);

        // The only time either secret is ever emitted. The handset stores
        // both and cannot ask for them again; losing them means enrolling
        // afresh, which is the same recovery either way.
        return new WP_REST_Response([
            'token'         => $token,
            'payload_key'   => $payloadKey,
            'device_id'     => $device->id,
            'responder'     => $member->getAnonymousName(),
            'platform'      => $platform,
            'push_provider' => $pushProvider,
        ], 201);
    }

    /**
     * Keep a responder's enrolments under {@see MAX_DEVICES_PER_MEMBER}
     * by revoking the least recently seen, oldest first, to make room
     * for the one being enrolled now.
     */
    private function pruneDevicesFor(string $email, int $now): void
    {
        $existing = $this->devices->findByMemberEmail($email);
        $surplus = (count($existing) + 1) - self::MAX_DEVICES_PER_MEMBER;
        if ($surplus <= 0) {
            return;
        }

        usort(
            $existing,
            static fn(Device $a, Device $b): int => [$a->lastSeenAt, $a->id] <=> [$b->lastSeenAt, $b->id],
        );

        foreach (array_slice($existing, 0, $surplus) as $stale) {
            $this->devices->revoke($stale->id, $now);
        }
    }

    /**
     * Coerce a claimed push transport to one we support, defaulting to
     * "pull only". Unknown transports become PUSH_NONE rather than an
     * error: a newer app asking for a transport this server has never
     * heard of should still enrol and fall back to polling.
     */
    private function normalisePushProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return $provider === Device::PUSH_FCM ? Device::PUSH_FCM : Device::PUSH_NONE;
    }

    /**
     * Cap a value at $maxBytes without splitting a multibyte UTF-8
     * sequence, matching the call-request controller's helper.
     */
    private function cap(string $value, int $maxBytes): string
    {
        $value = trim($value);
        if (strlen($value) > $maxBytes) {
            $value = (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        return $value;
    }

    private function overEnrolmentLimit(): bool
    {
        return $this->rateLimiter->overLimit(
            'device:' . $this->rateLimiter->clientIp(),
            self::ENROL_IP_MAX,
            self::ENROL_IP_WINDOW,
        );
    }

    private function invalidCredentials(): WP_Error
    {
        return new WP_Error(
            'reach_invalid_credentials',
            'Email or password is incorrect.',
            ['status' => 401],
        );
    }

    private function notAuthenticated(): WP_Error
    {
        return new WP_Error(
            'reach_device_not_authenticated',
            'This device is not signed in.',
            ['status' => 401],
        );
    }

    private function notEligible(): WP_Error
    {
        return new WP_Error(
            'reach_not_eligible',
            'Hand is for certified telephone responders. Please contact your intergroup if you believe this is in error.',
            ['status' => 403],
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
}
