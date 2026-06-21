<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestRepository;
use Reach\Session\CurrentSession;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function do_action;
use function register_rest_route;
use function rest_ensure_response;

/**
 * Record a Reach responder's request for a 12th-stepper to call a
 * caller back.
 *
 * Endpoint
 * --------
 *   POST /reach/v1/call-requests
 *     {
 *       "member_id":     123,
 *       "attempt_token": "<token issued with the member in the search result>",
 *       "caller_name":   "Sam",
 *       "caller_phone":  "07700 900123",
 *       "note":          "optional free text"
 *     }
 *
 * Authn / authz
 * -------------
 * Same shape as the call-attempts controller: a valid Reach session is
 * required, and the attempt_token must verify against (viewer email,
 * member id) — i.e. the responder must actually have been shown this
 * member in a recent result set. This keeps a session-holder from
 * spraying callback requests at arbitrary member ids.
 *
 * Audit
 * -----
 * One {@see AuditLogger::logBatch} entry per request, ACTION_CALL
 * against the member's {@see PersonalDataFields::MOBILE_NUMBER} field
 * (the field a callback would use). The detail carries the *responder's*
 * anonymous name and a fixed "Callback requested" result, in the same
 * "caller:<name>#<id>;result:<label>" shape the call-attempts audit
 * uses. The caller's name, phone and note are *never* written to the
 * audit trail — that personal data lives only in the call-requests
 * table, which is purged after a few days.
 */
final class CallRequestController
{
    public const NAMESPACE = 'reach/v1';

    /** Hard cap on note length, in bytes — matches the call-attempts
     *  controller so one row can't balloon the table on a misbehaving
     *  client. The cut is multibyte-aware (mb_strcut) so it never splits
     *  a UTF-8 codepoint. */
    private const NOTE_MAX_BYTES = 1000;

    /** Defensive caps mirroring the column widths in
     *  {@see \Reach\CallRequests\WpdbCallRequestRepository::install}. */
    private const NAME_MAX_BYTES = 200;
    private const PHONE_MAX_BYTES = 50;

    public function __construct(
        private readonly CallRequestRepository $repository,
        private readonly AttemptTokenMinter $tokens,
        private readonly CurrentSession $session,
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
            '/call-requests',
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
                    'attempt_token' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && $v !== '',
                    ],
                    'caller_name' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && trim($v) !== '',
                    ],
                    'caller_phone' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && trim($v) !== '',
                    ],
                    'note' => [
                        'type'              => 'string',
                        'required'          => false,
                        'default'           => '',
                        // sanitize_textarea_field preserves line breaks
                        // while stripping HTML.
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

        $memberId    = (int) $request->get_param('member_id');
        $token       = (string) $request->get_param('attempt_token');
        $callerName  = trim((string) $request->get_param('caller_name'));
        $callerPhone = trim((string) $request->get_param('caller_phone'));
        $note        = trim((string) $request->get_param('note'));
        $now         = time();

        if (!$this->tokens->verify($token, $session->email, $memberId, $now)) {
            return new WP_Error(
                'reach_invalid_attempt_token',
                'This request link is no longer valid. Run the search again.',
                ['status' => 403]
            );
        }

        // The validate_callbacks already require non-empty name/phone,
        // but re-check after trimming — a value of only whitespace
        // passes sanitisation yet is not a usable contact detail.
        if ($callerName === '' || $callerPhone === '') {
            return new WP_Error(
                'reach_missing_caller_details',
                'A caller name and phone number are both required.',
                ['status' => 400]
            );
        }

        $callerName  = $this->cap($callerName, self::NAME_MAX_BYTES);
        $callerPhone = $this->cap($callerPhone, self::PHONE_MAX_BYTES);
        $note        = $this->capNote($note);

        $callRequest = $this->repository->create(
            $memberId,
            $callerName,
            $callerPhone,
            $note,
            $session->email,
            $session->provider,
            $now,
        );

        // Audit the request as a CALL against the member's mobile
        // number — the field a returned call would use. The detail
        // names the *responder* (never the caller) and records the
        // fixed "Callback requested" result, mirroring the structured
        // shape CallAttemptController uses so the Scrutiny admin renders
        // it the same way. Caller PII stays out of the audit trail.
        $this->auditLogger->logBatch(
            AuditLogger::ACTION_CALL,
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            [PersonalDataFields::MOBILE_NUMBER],
            $this->responderDetail($session->email),
        );

        // Extension point for a future notifier (email/SMS). Inert
        // unless something hooks it.
        do_action('reach/call_request_created', $callRequest);

        return rest_ensure_response([
            'recorded'   => true,
            'id'         => $callRequest->id,
            'created_at' => $callRequest->createdAt,
        ]);
    }

    /**
     * Cap a single-line value at $maxBytes without splitting a
     * multibyte UTF-8 sequence.
     */
    private function cap(string $value, int $maxBytes): string
    {
        if (strlen($value) > $maxBytes) {
            $value = (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        return $value;
    }

    /**
     * Cap the note at NOTE_MAX_BYTES, returning null for the empty case
     * so the DB stores NULL rather than ''.
     */
    private function capNote(string $note): ?string
    {
        if ($note === '') {
            return null;
        }
        return $this->cap($note, self::NOTE_MAX_BYTES);
    }

    /**
     * Build the audit-detail string identifying the responder who
     * raised the request, in the same
     * `caller:<anonymous name>#<member id>;result:<label>` format the
     * call-attempts audit uses. We do not gate on
     * {@see Member::isTwelfthStepper()}: any member record with a
     * non-empty anonymous name is named, so the same person appears
     * under the same identifier across the search → call → request
     * lifecycle. When the responder cannot be resolved the prefix
     * becomes `caller:unknown` — a deliberately non-PII fallback.
     */
    private function responderDetail(string $email): string
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

        return sprintf('caller:%s;result:Callback requested', $caller);
    }
}
