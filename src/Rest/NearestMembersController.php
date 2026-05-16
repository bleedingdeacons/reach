<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\Core\Settings;
use Reach\Resolution\NearestMembersResolver;
use Reach\Resolution\ResolutionResult;
use Reach\Resolution\ScoredMember;
use Reach\Session\CurrentSession;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;
use function rest_ensure_response;

/**
 * REST controller: nearest 12th-step members for Reach visitors.
 *
 * Authentication & audit
 * ----------------------
 * Reach visitors are proof-of-email holders via the Reach session cookie,
 * not WordPress users. The permission callback below requires a valid
 * session — nothing more by default. Every result returned is audit-logged
 * through Scrutiny with the source tag `reach:nearest-members` plus the
 * requesting visitor's *anonymous name* (resolved from their verified
 * email via the Unity member repository) in the detail field. The raw
 * email is never written to the audit row — Scrutiny's contract forbids
 * raw PII in `detail`, and on installs where every Reach lookup runs
 * under one shared WP account the anonymous name is the only useful
 * "who triggered this" signal a regulator can reconstruct from the
 * audit table.
 *
 * If the verified email does not match a 12th-stepper member record,
 * the requester is recorded as `unknown` rather than leaking the
 * unmatched email into the log.
 *
 * Capability flag
 * ---------------
 * The Settings::requireScrutinyCapability toggle exists for installs
 * that want to keep Reach internal-only (employees, intergroup
 * officers) — when on, the email-verified session is necessary but
 * not sufficient, and the user must also be a logged-in WP user with
 * the scrutiny_view_personal_data capability. By default it's off
 * because the whole point of Reach is to give end users a way in
 * without a WP account.
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
        private readonly MemberRepository $members,
    ) {
    }

    /**
     * Per-request cache for the requester's anonymous name. The audit
     * step calls {@see requesterAnonymousName()} once per response and
     * we want exactly one repository hit no matter how many members
     * the response contains.
     */
    private ?string $cachedRequesterName = null;

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
        $detail = sprintf(
            'reach:nearest-members; requester=%s',
            $this->requesterAnonymousName()
        );

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

    /**
     * Resolve the current session's verified email to the matching
     * member's anonymous name.
     *
     * Returns `unknown` when no session is present, when no member
     * record matches the verified email, or when the matched member
     * is not flagged as a 12th-stepper. The fallback is deliberately
     * non-PII — a regulator examining the audit row sees only that
     * "an unrecognised Reach visitor viewed this data", not whose
     * email it was.
     */
    private function requesterAnonymousName(): string
    {
        if ($this->cachedRequesterName !== null) {
            return $this->cachedRequesterName;
        }

        $session = $this->session->get();
        if ($session === null || $session->email === '') {
            return $this->cachedRequesterName = 'unknown';
        }

        $member = $this->members->findByEmail($session->email);
        if ($member === null || !$member->isTwelfthStepper()) {
            return $this->cachedRequesterName = 'unknown';
        }

        $name = trim($member->getAnonymousName());
        return $this->cachedRequesterName = ($name !== '' ? $name : 'unknown');
    }
}
