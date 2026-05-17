<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\Core\Settings;
use Reach\Session\CurrentSession;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
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
 * Record a Reach visitor's attempt to contact a member.
 *
 * Endpoint
 * --------
 *   POST /reach/v1/call-attempts
 *     {
 *       "member_id":     123,
 *       "outcome":       "reached" | "no_answer" | "wrong_or_bad_number",
 *       "attempt_token": "<token issued with the member in the search result>",
 *       "note":          "optional free text, never shown to other users"
 *     }
 *
 * Authn / authz
 * -------------
 * Same as the nearest-members controller: a valid Reach session is
 * required, and the optional Scrutiny capability gate applies. On top
 * of that, the attempt_token must verify against (viewer email,
 * member id) — i.e. the caller must have actually been shown this
 * member in a recent result set.
 *
 * Audit
 * -----
 * One {@see AuditLogger::logBatch} entry per recorded attempt, written
 * with {@see AuditLogger::ACTION_CALL} against the member's
 * {@see PersonalDataFields::MOBILE_NUMBER} field. The detail field
 * carries the caller's *anonymous name* — never their email or
 * provider — so a regulator reading the audit log sees who called
 * (in 12th-step parlance) without learning the caller's identity.
 * The free-text note is *never* included in the audit trail —
 * operators should not be able to scrape callers' private context
 * out of audit logs.
 */
final class CallAttemptController
{
    public const NAMESPACE = 'reach/v1';

    public function __construct(
        private readonly CallAttemptRepository $repository,
        private readonly AttemptTokenMinter $tokens,
        private readonly CurrentSession $session,
        private readonly Settings $settings,
        private readonly AuditLogger $auditLogger,
        private readonly MemberRepository $members,
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
            '/call-attempts',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'permissionCallback'],
                'args'                => [
                    'member_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn($v) => is_numeric($v) && (int) $v > 0,
                    ],
                    'outcome' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && CallAttempt::isValidOutcome($v),
                    ],
                    'attempt_token' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && $v !== '',
                    ],
                    'note' => [
                        'type'              => 'string',
                        'required'          => false,
                        'default'           => '',
                        // sanitize_textarea_field preserves line breaks
                        // for the caller's own notes while stripping HTML.
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
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
                    'You do not have permission to record member contact attempts.',
                    ['status' => 403]
                );
            }
        }
        return true;
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session = $this->session->get();
        // permissionCallback already required a session, but guard
        // against a weird race where it expired between checks.
        if ($session === null) {
            return new WP_Error('reach_not_authenticated', 'Session expired.', ['status' => 401]);
        }

        $memberId = (int) $request->get_param('member_id');
        $outcome  = (string) $request->get_param('outcome');
        $token    = (string) $request->get_param('attempt_token');
        $note     = trim((string) $request->get_param('note'));
        $now      = time();

        if (!$this->tokens->verify($token, $session->email, $memberId, $now)) {
            return new WP_Error(
                'reach_invalid_attempt_token',
                'This attempt link is no longer valid. Run the search again.',
                ['status' => 403]
            );
        }

        // Cap the note at a sensible size so a misbehaving client can't
        // fill the table with megabytes of text per row.
        if ($note === '') {
            $note = null;
        } elseif (strlen($note) > 1000) {
            $note = substr($note, 0, 1000);
        }

        $attempt = $this->repository->record(
            $memberId,
            $session->email,
            $session->provider,
            $outcome,
            $note,
            $now,
        );

        // Audit the attempt as a CALL against the member's mobile
        // number (the field that was actually used to make contact).
        // The detail field carries the caller's *anonymous name* and
        // the call's result in a structured
        // "caller:<name>#<id>;result:<label>" format so the admin can
        // render the name as a link to the caller's member record and
        // surface the outcome alongside it. We deliberately never
        // include email or auth provider, mirroring the privacy stance
        // of NearestMembersController.
        $this->auditLogger->logBatch(
            AuditLogger::ACTION_CALL,
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            [PersonalDataFields::MOBILE_NUMBER],
            $this->callerDetail($session->email, $attempt->outcome),
        );

        return rest_ensure_response([
            'recorded'   => true,
            'id'         => $attempt->id,
            'outcome'    => $attempt->outcome,
            'created_at' => $attempt->createdAt,
        ]);
    }

    /**
     * Build the audit-detail string identifying the caller and outcome.
     *
     * The caller is the Reach visitor logging the result of a phone
     * call — typically the same person who ran the nearest-members
     * search. As in
     * {@see NearestMembersController::callerDetail()}, we do not gate
     * on {@see Member::isTwelfthStepper()}: any member record with a
     * non-empty anonymous name is named in the audit row, so the same
     * person appears under the same identifier across the
     * "search → call placed" lifecycle.
     *
     * Format: `caller:<anonymous name>#<member id>;result:<label>`
     * when the caller resolves to a member record. The Scrutiny audit
     * admin parses this shape to render "Caller: <name> Result: <label>"
     * with the name linked to the caller's member edit page. When the
     * caller cannot be resolved the prefix becomes `caller:unknown` —
     * a deliberately non-PII fallback so the audit row never carries
     * an unjustified identifier — but the result is always included.
     *
     * Human-readable outcome labels are baked in here rather than in
     * Scrutiny's admin so that Scrutiny stays domain-neutral: it only
     * needs to know how to parse the structure, not what `reached`
     * means.
     */
    private function callerDetail(string $email, string $outcome): string
    {
        $caller = 'unknown';

        if ($email !== '') {
            $member = $this->members->findByEmail($email);
            if ($member !== null) {
                $name = trim($member->getAnonymousName());
                if ($name !== '') {
                    $caller = sprintf('%s#%d', $name, $member->getId());
                }
            }
        }

        return sprintf(
            'caller:%s;result:%s',
            $caller,
            self::outcomeLabel($outcome),
        );
    }

    /**
     * Map a stored outcome code to the human label used in audit
     * detail strings. Unknown codes fall back to the raw value so a
     * future outcome added in code but not here still produces a
     * readable (if unstyled) audit row.
     */
    private static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            CallAttempt::OUTCOME_REACHED      => 'Spoke',
            CallAttempt::OUTCOME_NO_ANSWER    => 'No Answer',
            CallAttempt::OUTCOME_WRONG_OR_BAD => 'Wrong/Bad Number',
            default                           => $outcome,
        };
    }
}
