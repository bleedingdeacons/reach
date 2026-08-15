<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One alert raised for the telephone-responder rota.
 *
 * An alert is the thing that makes a Hand handset ring. It is raised by
 * a plugin — Reach itself when a callback is requested, or any other
 * plugin through {@see AlertApi} — stored here, and delivered to every
 * enrolled handset belonging to a responder who may receive it.
 *
 * <b>Alerts carry no personal data. This is not advisory.</b>
 *
 * The title and body of an alert travel further than almost anything
 * else in this stack: through Google's FCM servers, onto a lock screen
 * that anyone standing nearby can read, and into the notification
 * history of the handset. Reach already refuses to persist a caller's
 * name and number — {@see \Reach\CallRequests\CallRequest} keeps only
 * non-identifying tracking data and emails the rest — and an alert must
 * not undo that by putting the same details on a lock screen instead.
 *
 * So an alert says what kind of thing happened and gives a reference to
 * look it up by. "Call request CR-000123 — male 12th-stepper wanted in
 * BS5" is an alert. The caller's name and number are not, and belong in
 * the email that already carries them. {@see AlertRequest} is where
 * that rule is enforced on the way in.
 *
 * `targetEmail` empty means the alert goes to every eligible responder
 * — the normal case for a helpline, where whoever is free picks it up.
 * A named target is for the narrower case of an alert that concerns one
 * responder specifically.
 */
final class Alert
{
    /** Ordinary alert: audible, but not treated as an emergency. */
    public const PRIORITY_NORMAL = 'normal';

    /**
     * Urgent alert. Escalates the delivery path — on iOS this is what
     * asks for the critical interruption level, on Android the maximum
     * notification priority — so it breaks through a Focus mode.
     */
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [self::PRIORITY_NORMAL, self::PRIORITY_URGENT];

    /**
     * @param array<string, string> $payload Non-identifying structured
     *        extras, passed through to the app verbatim.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $source,
        public readonly string $priority,
        public readonly string $title,
        public readonly string $body,
        public readonly string $reference,
        public readonly array $payload,
        public readonly string $targetEmail,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        /**
         * Whether contact details are held for this alert.
         *
         * A flag, never the details themselves. Those live encrypted in
         * a separate table and are fetched by an authenticated responder
         * over TLS, one audited read at a time — see
         * {@see AlertContactRepository}. This is only here so the app
         * knows whether to offer the button.
         */
        public readonly bool $hasContact = false,
    ) {
    }

    /**
     * Whether this alert is addressed to every eligible responder
     * rather than to one named person.
     */
    public function isBroadcast(): bool
    {
        return $this->targetEmail === '';
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUrgent(): bool
    {
        return $this->priority === self::PRIORITY_URGENT;
    }

    /**
     * The alert as the Hand app receives it over REST.
     *
     * Deliberately does not include `targetEmail`: a handset polling for
     * its own alerts has no use for the address they were routed by, and
     * a broadcast alert's response would otherwise differ from a
     * targeted one in a way the app would have to ignore.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'kind'       => $this->kind,
            'source'     => $this->source,
            'priority'   => $this->priority,
            'title'      => $this->title,
            'body'       => $this->body,
            'reference'  => $this->reference,
            'payload'    => $this->payload,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            // The flag travels; the contact does not.
            'has_contact' => $this->hasContact,
        ];
    }

    /**
     * Coerce a priority to one we recognise, defaulting to normal.
     *
     * Unknown values become normal rather than raising: a plugin
     * inventing a priority should still get its alert delivered, just
     * without the escalation it asked for by a name nothing agrees on.
     */
    public static function normalisePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, self::PRIORITIES, true) ? $priority : self::PRIORITY_NORMAL;
    }
}
