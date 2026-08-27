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
 * history of the handset.
 *
 * <b>The lock screen is not redacted by anything Reach controls.</b>
 * Hand marks its notifications private and supplies a public version
 * reading "Helpline alert / Unlock to read", but Android substitutes
 * that only where the phone's owner has chosen to hide sensitive
 * content. On a handset set to show everything — which many are, by
 * default — the alert's own words are what a stranger reads, and no
 * app can override that. Handsets that have said so are flagged on the
 * Hand devices screen; a handset that has never said is not a handset
 * known to be safe. Which is to say the rule below is the protection,
 * not a second layer behind one. Reach already refuses to persist a caller's
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
 *
 * `targetDeviceId` narrows it further still, to one handset. A responder
 * with a phone and a tablet has two devices behind one address, and
 * there are two things that must reach only one of them: the admin
 * test alert, whose entire value is answering "does *that* handset
 * ring", and the removal notice a handset is sent as it is taken off
 * the rota. Both are about a device rather than a person, so neither
 * can be addressed by email. Zero means "any handset the address
 * resolves to", which is every alert a plugin will ever raise.
 */
final class Alert
{
    /**
     * The kind Reach raises when a handset acknowledges a message: a
     * notice to everybody *else* it was sent to, saying who picked it up.
     *
     * <b>It is not an alert and must never alarm.</b> Hand treats this
     * kind as information — a quiet notification, no siren, and a Close
     * button in place of Acknowledge — because the whole of its content
     * is that somebody has already dealt with the thing that did alarm.
     * The spelling is a wire contract shared with
     * <c>HandAlert.KindMessageAcknowledged</c>; changing it here alone
     * turns the notice back into a 3am siren.
     *
     * Raised by {@see AcknowledgementNotifier}, which also refuses to
     * raise one *for* one — otherwise every acknowledgement of a notice
     * would breed the next.
     */
    public const KIND_ACKNOWLEDGED = 'message_acknowledged';

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
        /**
         * The one handset this alert is for, or 0 for any. See the class
         * docblock; {@see \Reach\Alerts\AlertDispatcher} resolves it and
         * {@see \Reach\Rest\AlertController} enforces it on the way back.
         */
        public readonly int $targetDeviceId = 0,
        /**
         * The message this alert is one delivery of. See
         * {@see MessageUuid}: every alert has one, and every alert raised
         * by the same send shares it.
         *
         * Empty only on rows written before the column existed. Nothing
         * generates an empty one.
         */
        public readonly string $messageUuid = '',
        /**
         * One handset this alert is deliberately kept from, or 0.
         *
         * The inverse of {@see $targetDeviceId}, and it exists for one
         * case: the notice saying a message has been acknowledged goes to
         * everybody it was sent to *except* the handset that acknowledged
         * it. Without this that handset would be told about its own
         * button press — by push immediately, and again on its next poll.
         *
         * Honoured in three places, and it has to be all three:
         * {@see AlertDispatcher::resolveTargets()} for the push,
         * {@see AlertRepository::pendingFor()} for the poll, and
         * {@see \Reach\Rest\AlertController} for what a handset may
         * acknowledge or read a contact from.
         */
        public readonly int $excludeDeviceId = 0,
    ) {
    }

    /**
     * Whether this alert is addressed to every eligible responder
     * rather than to one named person or one named handset.
     */
    public function isBroadcast(): bool
    {
        return $this->targetEmail === '' && $this->targetDeviceId === 0;
    }

    /**
     * Whether this alert is for one specific handset.
     *
     * Checked before the address, not after: a device-targeted alert
     * carries no email at all, so testing the email first would read it
     * as a broadcast and ring the whole rota.
     */
    public function isDeviceTargeted(): bool
    {
        return $this->targetDeviceId > 0;
    }

    /**
     * Whether this handset is the one the alert is being kept from.
     * See {@see $excludeDeviceId}.
     */
    public function excludes(int $deviceId): bool
    {
        return $this->excludeDeviceId > 0 && $this->excludeDeviceId === $deviceId;
    }

    /**
     * Whether this is the "somebody has answered" notice rather than
     * something needing an answer. See {@see KIND_ACKNOWLEDGED}.
     */
    public function isAcknowledgementNotice(): bool
    {
        return $this->kind === self::KIND_ACKNOWLEDGED;
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
            // The message this delivery belongs to. Hand matches the
            // acknowledgement notice back to the alert it is about on
            // this, not on the id — see MessageUuid.
            'message_uuid' => $this->messageUuid,
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
