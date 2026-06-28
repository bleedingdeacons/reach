<?php

declare(strict_types=1);

namespace Reach\CallRequests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A request, raised from the Reach home page, for a 12th-stepper to
 * call a caller back.
 *
 * Raised by a telephone responder who would rather not ring a
 * 12th-stepper directly: instead of placing the call, they capture the
 * caller's name and number (plus a preferred 12th-stepper gender and an
 * optional note) and log the request for a 12th-stepper to return the
 * call. Stored verbatim in wp_reach_call_requests and surfaced only on
 * the "Call Requests" admin page, where it can be actioned and deleted.
 *
 * The request is no longer tied to a specific member: instead of a
 * target member id it records the *responder's* name ({@see
 * $responderName}) — the signed-in user who raised it — plus the
 * preferred gender and the caller's area for the callback.
 *
 * Unlike {@see \Reach\CallAttempts\CallAttempt}, a request carries the
 * *caller's* personal data (their name and phone), not a member's.
 * It is therefore short-lived operational data — automatically purged
 * after a few days (see {@see CallRequestRepository::purgeOlderThan})
 * rather than retained as an audit trail.
 */
final class CallRequest
{
    public function __construct(
        public readonly int $id,
        public readonly string $responderName,
        public readonly string $gender,
        public readonly string $area,
        public readonly string $callerName,
        public readonly string $callerPhone,
        public readonly ?string $note,
        public readonly string $viewerEmail,
        public readonly string $viewerProvider,
        public readonly int $createdAt,
    ) {
    }
}
