<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\Resolution\NearestMembersResolver;
use Reach\Resolution\ResolutionResult;
use Reach\Resolution\ScoredMember;
use Reach\Session\CurrentSession;
use Scrutiny\Audit\Interfaces\AuditLogger;
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
 * through Scrutiny in a structured `caller:<name>#<member id>` detail
 * format (or `caller:unknown` when the verified email matches no member),
 * which the Scrutiny admin renders as a linked "Caller: <name>" entry
 * matching the format used by the call-attempt audit. The raw email is
 * never written to the audit row — Scrutiny's contract forbids raw PII
 * in `detail`, and on installs where every Reach lookup runs under one
 * shared WP account the anonymous name is the only useful
 * "who triggered this" signal a regulator can reconstruct from the
 * audit table.
 *
 * If the verified email does not match any Unity member record, the
 * viewer is recorded as `unknown` rather than leaking the unmatched
 * email into the log. The 12th-stepper flag is deliberately *not* a
 * gate here, matching CallAttemptController: an intergroup officer or
 * other non-12th-step member who legitimately reaches this endpoint
 * still appears under their anonymous name.
 */
final class NearestMembersController
{
    public const NAMESPACE = 'reach/v1';
    private const DEFAULT_LIMIT = 10;

    /**
     * Upper bound on how many members one response may carry. The find
     * page asks for the full set inside its distance cap (so the
     * client-side distance buttons have every in-range member to
     * narrow from), which is why this is comfortably above the
     * default: a single intergroup rarely has this many 12th-steppers
     * within 20km, but the ceiling stops a pathological area string
     * from returning an unbounded list.
     */
    private const MAX_LIMIT = 50;

    /**
     * Hard ceiling on the max-distance cutoff a caller may request, in
     * kilometres. The find page only ever asks for 20; this guards the
     * endpoint against an arbitrarily large cap being passed directly.
     */
    private const MAX_DISTANCE_KM = 100.0;

    /**
     * Fields counted as a personal-data view when a member appears in
     * a results set. `area` (geographic area string) and `accepts`
     * (gender filter) are deliberately *not* in this list: they are
     * selection criteria the caller already supplied, not personal
     * data we expose to them, so logging them under GDPR audit would
     * misrepresent what the visitor actually saw. Personal email is
     * also not exposed by Reach — the only contact method surfaced
     * to a viewer is the mobile number — so it isn't audited here
     * either.
     */
    private const AUDITED_FIELDS = [
        'mobile_number',
    ];

    public function __construct(
        private readonly NearestMembersResolver $resolver,
        private readonly AuditLogger $auditLogger,
        private readonly CurrentSession $session,
        private readonly CallAttemptRepository $callAttempts,
        private readonly ResponsivenessScorer $scorer,
        private readonly AttemptTokenMinter $attemptTokens,
        private readonly MemberRepository $members,
    ) {
    }

    /**
     * Per-request cache for the viewer's audit-detail string. The
     * audit step calls {@see callerDetail()} once per response and
     * we want exactly one repository hit no matter how many members
     * the response contains.
     */
    private ?string $cachedCallerDetail = null;

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
                    'max_km' => [
                        'type'              => 'number',
                        'required'          => false,
                        'default'           => null,
                        'sanitize_callback' => static function ($v) {
                            if ($v === null || $v === '') {
                                return null;
                            }
                            return (float) $v;
                        },
                        'validate_callback' => static function ($v) {
                            if ($v === null || $v === '') {
                                return true;
                            }
                            return is_numeric($v) && (float) $v > 0;
                        },
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

        return true;
    }

    public function getNearest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $location = (string) $request->get_param('location');
        $accepts  = (array) $request->get_param('accepts');
        $limit    = min(self::MAX_LIMIT, max(1, (int) $request->get_param('limit')));

        $maxKmParam = $request->get_param('max_km');
        $maxKm = ($maxKmParam === null || $maxKmParam === '')
            ? null
            : min(self::MAX_DISTANCE_KM, max(0.0, (float) $maxKmParam));

        // The find page surfaces nearby members who fall outside the
        // caller's gender preference as well as those who match it,
        // ordered by distance then preference, so include-non-preferred
        // is on. The non-matching members are tagged (not preferred) in
        // the projected response rather than dropped.
        $result = $this->resolver->resolve($location, $accepts, $limit, $maxKm, true);

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
                    // For a pipe-separated member (e.g. "Kingswood|Hanham"),
                    // surface only the entry that drove the match — that's
                    // the area the reported distance refers to, and it
                    // avoids leaking the separator-as-data into the UI.
                    // Single-area members fall back to the raw field, so
                    // their behaviour is unchanged.
                    'area'            => $scored->matchedArea ?? $m->getArea(),
                    'accepts'         => $m->getAccepts(),
                    'preferred'       => $scored->preferred,
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
        $detail = $this->callerDetail();

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
     * Build the audit-detail string identifying the viewer of this
     * exposure.
     *
     * The viewer is the logged-in Reach user who ran the search
     * (typically a 12th-stepper, but not necessarily — an intergroup
     * officer, or any member without the 12th-stepper flag, can also
     * reach this endpoint). We therefore do not gate on
     * {@see Member::isTwelfthStepper()}: any member record with a
     * non-empty anonymous name is named in the audit row. This is the
     * same policy as
     * {@see CallAttemptController::callerDetail()}, so a single
     * person appears under the same identifier across the
     * "search → call placed" lifecycle.
     *
     * Format: `caller:<anonymous name>#<member id>` when the viewer
     * resolves to a member record, or `caller:unknown` otherwise.
     * Scrutiny's audit admin parses this shape and renders
     * "Caller: <name>" with the name linked to that member's edit
     * page. The raw email is never written — Scrutiny's contract
     * forbids raw PII in `detail`.
     *
     * Cached per-request: the audit step logs one row per result
     * member and we want exactly one repository hit no matter how
     * many members the response contains.
     */
    private function callerDetail(): string
    {
        if ($this->cachedCallerDetail !== null) {
            return $this->cachedCallerDetail;
        }

        $caller = 'unknown';

        $session = $this->session->get();
        if ($session !== null && $session->email !== '') {
            $member = $this->members->findByEmail($session->email);
            if ($member !== null) {
                $name = trim($member->getAnonymousName());
                if ($name !== '') {
                    $caller = sprintf('%s#%d', $name, $member->getId());
                }
            }
        }

        return $this->cachedCallerDetail = sprintf('caller:%s', $caller);
    }
}
