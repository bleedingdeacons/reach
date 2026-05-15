<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Compass\Resolution\NearestMembersResolver;
use Compass\Resolution\ResolutionResult;
use Compass\Resolution\ScoredMember;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\Core\Settings;
use Reach\Session\CurrentSession;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataPolicy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;
use function rest_ensure_response;

/**
 * Reach's wrapper around Compass's resolver.
 *
 * Why a wrapper rather than calling Compass's own controller?
 * ----------------------------------------------------------
 * Compass's controller authenticates against a logged-in WP user with
 * the scrutiny_view_personal_data capability. Reach users are *not*
 * WP users — they're proof-of-email holders via the Reach session
 * cookie. Going through Compass's HTTP surface would require either
 * provisioning a WP user per email (which we ruled out) or sending
 * the request as a privileged service user (which would lose the
 * "this specific person looked at this record" audit trail).
 *
 * Calling the resolver directly keeps the audit clean: every record
 * exposed via Reach is logged with the source tag
 * `reach:nearest-members` plus the requesting email in the detail
 * field, so a regulator can answer "which Reach user saw this member's
 * mobile, and when" from Scrutiny's audit table.
 *
 * Capability flag
 * ---------------
 * The Settings::requireScrutinyCapability toggle exists for installs
 * that want to keep Reach internal-only (employees, intergroup
 * officers) — when on, the email-verified session is necessary but
 * not sufficient, and the user must also be a logged-in WP user with
 * the capability. By default it's off because the whole point of
 * Reach is to give end users a way in without a WP account.
 */
final class NearestMembersController
{
    public const NAMESPACE = 'reach/v1';
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 25;

    private const AUDITED_FIELDS = [
        'personal_email',
        'mobile_number',
        'area',
        'accepts',
    ];

    public function __construct(
        private readonly NearestMembersResolver $resolver,
        private readonly AuditLogger $auditLogger,
        private readonly CurrentSession $session,
        private readonly Settings $settings,
        private readonly CallAttemptRepository $callAttempts,
        private readonly ResponsivenessScorer $scorer,
        private readonly AttemptTokenMinter $attemptTokens,
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
            '/nearest-members',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getNearest'],
                'permission_callback' => [$this, 'permissionCallback'],
                'args'                => [
                    'location' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && trim($v) !== '',
                    ],
                    'accepts' => [
                        'type'              => 'array',
                        'required'          => false,
                        'items'             => ['type' => 'string'],
                        'default'           => [],
                        'sanitize_callback' => static function ($v) {
                            if (!is_array($v)) return [];
                            return array_values(array_filter(array_map(
                                static fn($item) => is_string($item) ? sanitize_text_field($item) : '',
                                $v
                            ), static fn($item) => $item !== ''));
                        },
                    ],
                    'limit' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'default'           => self::DEFAULT_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Lightweight introspection route used by the find page to
        // decide whether to redirect to /reach/signin.
        register_rest_route(
            self::NAMESPACE,
            '/session',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getSession'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function permissionCallback(): bool|WP_Error
    {
        $session = $this->session->get();
        if ($session === null) {
            return new WP_Error(
                'reach_not_authenticated',
                'Sign in to use Reach.',
                ['status' => 401]
            );
        }

        if ($this->settings->requireScrutinyCapability()) {
            if (!is_user_logged_in() || !current_user_can(PersonalDataPolicy::VIEW_CAPABILITY)) {
                return new WP_Error(
                    'reach_forbidden',
                    'You do not have permission to view member contact details.',
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    public function getNearest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $location = (string) $request->get_param('location');
        $accepts  = (array) $request->get_param('accepts');
        $limit    = min(self::MAX_LIMIT, max(1, (int) $request->get_param('limit')));

        $result = $this->resolver->resolve($location, $accepts, $limit);

        if (!$result->resolved) {
            return new WP_Error(
                'reach_unresolvable_location',
                sprintf('Could not find the location "%s". Try a postcode or area name.', $result->unresolvedLocation ?? ''),
                ['status' => 422]
            );
        }

        $this->auditExposure($result->members);

        return rest_ensure_response($this->projectResponse($result));
    }

    public function getSession(): WP_REST_Response
    {
        $session = $this->session->get();
        if ($session === null) {
            return new WP_REST_Response(['authenticated' => false], 200);
        }
        return new WP_REST_Response([
            'authenticated' => true,
            'email'         => $session->email,
            'provider'      => $session->provider,
            'expires_at'    => $session->expiresAt,
        ], 200);
    }

    private function projectResponse(ResolutionResult $result): array
    {
        $now = time();
        $session = $this->session->get();
        $viewerEmail = $session !== null ? $session->email : '';

        // Pull recent attempts in one query, score in PHP. The query is
        // bounded by the current result-set's member ids, so it stays
        // small even on a busy install.
        $memberIds = array_map(
            static fn(ScoredMember $sm): int => $sm->member->getId(),
            $result->members,
        );
        $recentAttempts = $this->callAttempts->forMembersSince(
            $memberIds,
            ResponsivenessScorer::LOOKBACK_SECONDS,
            $now,
        );
        $badges = $this->scorer->scoreMany($memberIds, $recentAttempts);

        $members = array_map(
            function (ScoredMember $scored) use ($badges, $viewerEmail, $now): array {
                $m = $scored->member;
                $id = $m->getId();
                return [
                    'id'              => $id,
                    'anonymous_name'  => $m->getAnonymousName(),
                    'area'            => $m->getArea(),
                    'accepts'         => $m->getAccepts(),
                    'personal_email'  => $m->getPersonalEmail(),
                    'mobile_number'   => $m->getMobileNumber(),
                    'distance_km'     => round($scored->distanceKm, 1),
                    'responsiveness'  => $badges[$id] ?? null,
                    // Bind a token so the find-page can log an outcome
                    // for *this* member without re-fetching the list.
                    'attempt_token'   => $viewerEmail !== ''
                        ? $this->attemptTokens->mint($viewerEmail, $id, $now)
                        : null,
                ];
            },
            $result->members
        );

        return [
            'count' => count($members),
            'members' => $members,
        ];
    }

    /**
     * @param array<int, ScoredMember> $members
     */
    private function auditExposure(array $members): void
    {
        $session = $this->session->get();
        $detail = $session !== null
            ? sprintf('reach:nearest-members; viewer=%s/%s', $session->provider, $session->email)
            : 'reach:nearest-members';

        foreach ($members as $scored) {
            $this->auditLogger->logBatch(
                AuditLogger::ACTION_VIEW,
                AuditLogger::ENTITY_MEMBER,
                $scored->member->getId(),
                self::AUDITED_FIELDS,
                $detail
            );
        }
    }
}
