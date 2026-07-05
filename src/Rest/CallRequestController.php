<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestMailer;
use Reach\CallRequests\CallRequestRepository;
use Reach\Session\CurrentSession;
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
 * Record a telephone responder's request for a 12th-stepper to call a
 * caller back.
 *
 * Endpoint
 * --------
 *   POST /reach/v1/call-requests
 *     {
 *       "gender":       "male" | "female" | "non-binary",
 *       "area":         "BS5 / Easton",
 *       "caller_name":  "Sam",
 *       "caller_phone": "07700 900123",
 *       "note":         "optional free text"
 *     }
 *
 * The request is not tied to a specific member: it records the preferred
 * 12th-stepper gender and the caller's area, and the responder who raised
 * it is identified by their *name* (derived from the signed-in session —
 * see {@see responderName()}). A 12th-stepper picks the callback up from
 * the admin "Call Requests" list.
 *
 * Authn / authz
 * -------------
 * A valid Reach session is the only requirement. There is no per-member
 * attempt token any more — the request targets no particular member, so
 * there is nothing member-specific to authorise against.
 *
 * No audit entry is written here: the request accesses no *member*
 * personal data. The caller's details are not stored either — they are
 * emailed to the configured call-request address (see
 * {@see CallRequestMailer}); only a non-identifying tracking row is kept
 * so the admin "Call Requests" list can show a history.
 */
final class CallRequestController
{
    public const NAMESPACE = 'reach/v1';

    /** Accepted values for the preferred-gender field. Mirrors the
     *  single-choice radio group on the request form. */
    private const GENDERS = ['male', 'female', 'non-binary'];

    /** Hard cap on note length, in bytes — matches the call-attempts
     *  controller so one row can't balloon the table on a misbehaving
     *  client. The cut is multibyte-aware (mb_strcut) so it never splits
     *  a UTF-8 codepoint. */
    private const NOTE_MAX_BYTES = 1000;

    /** Defensive caps mirroring the column widths in
     *  {@see \Reach\CallRequests\WpdbCallRequestRepository::install}. */
    private const NAME_MAX_BYTES = 200;
    private const PHONE_MAX_BYTES = 50;
    private const AREA_MAX_BYTES = 200;

    public function __construct(
        private readonly CallRequestRepository $repository,
        private readonly CurrentSession $session,
        private readonly MemberRepository $members,
        private readonly CallRequestMailer $mailer,
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
                    'gender' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && in_array($v, self::GENDERS, true),
                    ],
                    'area' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static fn($v) => is_string($v) && trim($v) !== '',
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

        $gender      = (string) $request->get_param('gender');
        $area        = trim((string) $request->get_param('area'));
        $callerName  = trim((string) $request->get_param('caller_name'));
        $callerPhone = trim((string) $request->get_param('caller_phone'));
        $note        = trim((string) $request->get_param('note'));
        $now         = time();

        // The validate_callbacks already require non-empty values, but
        // re-check after trimming — a value of only whitespace passes
        // sanitisation yet is not a usable detail.
        if ($callerName === '' || $callerPhone === '' || $area === '') {
            return new WP_Error(
                'reach_missing_caller_details',
                'A caller name, phone number and area are all required.',
                ['status' => 400]
            );
        }

        $callerName  = $this->cap($callerName, self::NAME_MAX_BYTES);
        $callerPhone = $this->cap($callerPhone, self::PHONE_MAX_BYTES);
        $area        = $this->cap($area, self::AREA_MAX_BYTES);
        $note        = $this->capNote($note);

        $responderName = $this->responderName($session->email);

        // Store the non-identifying tracking row first so we have an id
        // (and hence a serial) to put in the email. The caller's name,
        // phone, gender preference and note are deliberately NOT passed
        // here — they only ever go in the email below.
        $callRequest = $this->repository->create(
            $responderName,
            $area,
            $session->email,
            $session->provider,
            $now,
        );

        // Email the caller's details to the configured address. This is
        // the system of record for the PII; the database holds none of
        // it. If the mail can't be sent we must not leave an orphan
        // tracking row whose caller details have been lost, so roll the
        // row back and ask the responder to try again.
        $sent = $this->mailer->send(
            $callRequest->serial(),
            $responderName,
            $gender,
            $area,
            $callerName,
            $callerPhone,
            $note,
            $now,
        );

        if (!$sent) {
            $this->repository->delete($callRequest->id);
            return new WP_Error(
                'reach_call_request_not_sent',
                'Could not send that request. Please try again in a moment.',
                ['status' => 502]
            );
        }

        // Extension point for a further notifier (e.g. SMS). Inert unless
        // something hooks it. The record carries no caller PII.
        do_action('reach/call_request_created', $callRequest);

        return rest_ensure_response([
            'recorded'   => true,
            'id'         => $callRequest->id,
            'reference'  => $callRequest->serial(),
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
     * The display name to store for the responder who raised the request.
     *
     * Resolved from the signed-in session email: the matching Unity
     * member's anonymous name when there is one, otherwise the email
     * itself as a fallback so the admin list can always identify who
     * raised a request. We do not gate on {@see Member::isTwelfthStepper()}
     * — any member record with a non-empty anonymous name is named.
     */
    private function responderName(string $email): string
    {
        if ($email === '') {
            return '';
        }

        $member = $this->members->findByEmail($email);
        if ($member !== null) {
            $name = trim($member->getAnonymousName());
            if ($name !== '') {
                return $name;
            }
        }

        return $email;
    }
}
