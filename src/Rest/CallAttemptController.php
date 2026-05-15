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
use Scrutiny\Privacy\PersonalDataPolicy;
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
 * One {@see AuditLogger::logBatch} entry per recorded attempt, with
 * the outcome and viewer email in the source detail. The note field
 * is *never* included in the audit trail — operators should not be
 * able to scrape callers' private context out of audit logs.
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

        // Audit the attempt — outcome and viewer go in the detail
        // field; note deliberately does NOT (see class docblock).
        $this->auditLogger->logBatch(
            AuditLogger::ACTION_VIEW, // closest existing action verb
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            ['call_attempt'],
            sprintf(
                'reach:call-attempt; outcome=%s; viewer=%s/%s',
                $outcome,
                $session->provider,
                $session->email,
            ),
        );

        return rest_ensure_response([
            'recorded'   => true,
            'id'         => $attempt->id,
            'outcome'    => $attempt->outcome,
            'created_at' => $attempt->createdAt,
        ]);
    }
}
