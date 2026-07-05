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
 * 12th-stepper directly. The caller's personal data (their name, phone,
 * the preferred 12th-stepper gender and an optional note) is *not* kept
 * here: it is emailed to the configured call-request address at the
 * moment the request is raised (see {@see CallRequestMailer}). What this
 * record holds is only the non-identifying tracking data — who raised it,
 * the caller's area, when, and whether it has been actioned — so the
 * admin "Call Requests" list can show a history without storing PII.
 *
 * Each request carries a human reference ({@see serial()}) derived from
 * its id; that same reference goes in the email subject so an admin can
 * match a row in the list to the message in the inbox.
 *
 * Unlike the old design this is durable history, not short-lived
 * operational data: requests are marked *completed* (recording which
 * member actioned them) rather than deleted, and are not auto-purged.
 */
final class CallRequest
{
    public function __construct(
        public readonly int $id,
        public readonly string $responderName,
        public readonly string $area,
        public readonly string $viewerEmail,
        public readonly string $viewerProvider,
        public readonly int $createdAt,
        public readonly ?int $completedAt = null,
        public readonly int $completedByMemberId = 0,
        public readonly string $completedByName = '',
    ) {
    }

    /**
     * Human reference for this request, e.g. "CR-000123". Stable for the
     * life of the row and shared with the notification email so the two
     * can be matched up.
     */
    public function serial(): string
    {
        return sprintf('CR-%06d', $this->id);
    }

    /**
     * Whether the request has been actioned (a 12th-stepper has called
     * the caller back and an admin marked it done).
     */
    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }
}
