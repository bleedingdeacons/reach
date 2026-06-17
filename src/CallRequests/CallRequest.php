<?php

declare(strict_types=1);

namespace Reach\CallRequests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A request, raised from the Reach find page, for a 12th-stepper to
 * call a caller back.
 *
 * Raised out of hours, when a responder would rather not ring the
 * 12th-stepper directly: instead of placing the call, they capture the
 * caller's name and number (plus an optional note) and ask the
 * 12th-stepper to return the call. Stored verbatim in
 * wp_reach_call_requests and surfaced only on the "Call Requests"
 * admin page, where it can be actioned and deleted.
 *
 * Unlike {@see \Reach\CallAttempts\CallAttempt}, a request carries the
 * *caller's* personal data (their name and phone), not the member's.
 * It is therefore short-lived operational data — automatically purged
 * after a few days (see {@see CallRequestRepository::purgeOlderThan})
 * rather than retained as an audit trail.
 */
final class CallRequest
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $callerName,
        public readonly string $callerPhone,
        public readonly ?string $note,
        public readonly string $viewerEmail,
        public readonly string $viewerProvider,
        public readonly int $createdAt,
    ) {
    }
}
