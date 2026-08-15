<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for the contact details attached to an alert.
 *
 * <b>Why this is a separate repository and a separate table.</b>
 *
 * A contact — somebody's name and phone number — is personal data, and
 * everything else about an alert deliberately is not. Keeping it apart
 * buys three things that a column on the alerts table would not:
 *
 *  1. The poll query never touches it. Every handset runs that query
 *     every few seconds while on duty; personal data has no business
 *     being in the hot path, and cannot leak through a response that
 *     never selects it.
 *  2. It is encrypted at rest on its own, so a database dump of the
 *     alerts table yields nothing identifying at all.
 *  3. It can be erased independently — a GDPR request, or simply a
 *     shorter retention for the contact than for the alert record.
 *
 * The value is a single opaque string rather than parsed name/phone
 * fields. Reach has no use for the parts, and a free-text line is what
 * a raising plugin actually has — the same reasoning that keeps the
 * caller's details out of {@see \Reach\CallRequests\CallRequest}.
 */
interface AlertContactRepository
{
    /**
     * Attach contact details to an alert. Storing an empty string
     * removes them, so a plugin can clear a contact by re-sending
     * without one.
     */
    public function save(int $alertId, string $contact, int $now): bool;

    /**
     * The contact for an alert, or '' when there is none — which is the
     * normal case, since most alerts carry no personal data at all.
     */
    public function find(int $alertId): string;

    public function has(int $alertId): bool;

    public function delete(int $alertId): bool;

    /**
     * Delete contacts belonging to alerts that expired before $before.
     * Called by the same sweep that purges the alerts themselves.
     *
     * @return int Number of contacts deleted.
     */
    public function purgeForExpiredAlertsBefore(int $before): int;
}
