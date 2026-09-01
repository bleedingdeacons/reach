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
     * <b>It is not an alert and must never alarm.</b> A quiet
     * notification, no siren, and a Close button in place of
     * Acknowledge — because the whole of its content is that somebody
     * has already dealt with the thing that did alarm.
     *
     * <b>None of that follows from the kind any more.</b> It is
     * {@see LEVEL_BLUE} and {@see RESPONSE_NONE}, set where the notice is
     * raised, and Hand reads those two fields exactly as it reads them on
     * any other alert. That is the point of the fields: what used to be
     * this one hard-coded exception is now something anything can ask
     * for. What the kind still does is stop a notice breeding another —
     * see {@see AcknowledgementNotifier}, which is the only thing that
     * branches on it.
     *
     * The spelling is a wire contract shared with
     * <c>HandAlert.KindMessageAcknowledged</c>; it is what Hand matches
     * a notice back to the message it reports on.
     *
     * Raised by {@see AcknowledgementNotifier}, which also refuses to
     * raise one *for* one — otherwise every acknowledgement of a notice
     * would breed the next.
     */
    public const KIND_ACKNOWLEDGED = 'message_acknowledged';

    /**
     * The kind Reach raises when a responder replies to a message: a
     * notice carrying their words back to whoever sent the original.
     *
     * <b>Quiet, like the acknowledgement notice, and for the same
     * reason.</b> It is {@see LEVEL_BLUE} and {@see RESPONSE_NONE}, set
     * where the reply is raised, so Hand shows it in the tray with a
     * Close button and never alarms. Nothing branches on the kind to
     * achieve that.
     *
     * What the kind does is stop the correspondence: a reply may not be
     * replied to, and neither may an acknowledgement notice. Without
     * that, one answered call becomes an unbounded exchange between two
     * handsets — the same guard {@see AcknowledgementNotifier} opens
     * with.
     *
     * The spelling is a wire contract shared with
     * <c>HandAlert.KindMessageReply</c>.
     */
    public const KIND_REPLY = 'message_reply';

    /**
     * <b>The compatibility spelling of {@see $level}, and nothing more.</b>
     *
     * `priority` was the original loudness dial and it had two positions.
     * {@see $level} replaced it with three, because two could not express
     * the difference between "answer this now" and "read this when you
     * next pick the phone up". These constants survive for two reasons
     * and no others: a plugin written against the old API still passes
     * `priority` and must keep working, and a handset on an older build
     * still reads `priority` off the wire and must keep ringing.
     *
     * Both directions of the mapping are {@see priorityFor()} and
     * {@see levelForPriority()}. Nothing in Reach branches on a priority
     * any more — {@see isUrgent()} asks the level.
     */
    public const PRIORITY_NORMAL = 'normal';

    /** See {@see PRIORITY_NORMAL}. Maps to and from {@see LEVEL_RED}. */
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [self::PRIORITY_NORMAL, self::PRIORITY_URGENT];

    /**
     * The loudest an alert gets: the handset takes the screen over, rings
     * like an incoming call, and goes on ringing until somebody answers.
     *
     * For the thing a responder has to deal with now. On Android this is
     * the full-screen intent, the alarm category and the looping siren;
     * on iOS the critical interruption level where Apple has granted the
     * entitlement, and time-sensitive where it has not.
     */
    public const LEVEL_RED = 'red';

    /**
     * Audible, but it does not take the phone over: a heads-up
     * notification with a sound, which a responder can miss and catch up
     * with later. The default, and the right level for most things.
     */
    public const LEVEL_YELLOW = 'yellow';

    /**
     * Information and reminders. Lands in the tray at ordinary
     * importance and never wakes anybody. What the acknowledgement
     * notice is, and what a shift reminder should be.
     */
    public const LEVEL_BLUE = 'blue';

    public const LEVELS = [self::LEVEL_RED, self::LEVEL_YELLOW, self::LEVEL_BLUE];

    /**
     * <b>First to acknowledge takes the job on.</b> The rota is told who
     * answered and the alert clears off every other handset, so a
     * callback one person has picked up stops shouting at the other
     * twenty-nine.
     *
     * The historic behaviour of every alert, and still the default: an
     * alert whose raiser said nothing about this is one somebody has to
     * take. Enforced in three places that have to agree —
     * {@see AlertRepository::pendingFor()} suppresses it on the poll,
     * {@see AcknowledgementNotifier} raises the notice that clears it in
     * the moment, and Hand offers the button that says "Acknowledge".
     */
    public const RESPONSE_FIRST = 'first';

    /**
     * <b>Everybody reads it and closes their own copy.</b> Nobody is
     * taking anything on, so one responder closing it says nothing about
     * anybody else's and must not clear it from their screens.
     *
     * For messages rather than jobs: a shift reminder, a notice that the
     * office is shut, the acknowledgement notice itself. Hand's button
     * says "Close" for these, because there is nothing here to
     * acknowledge.
     */
    public const RESPONSE_NONE = 'none';

    public const RESPONSES = [self::RESPONSE_FIRST, self::RESPONSE_NONE];

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
        /**
         * How loud this alert is, and what colour Hand paints its card.
         * One of {@see LEVELS}; see each constant for what it means.
         *
         * Defaulted rather than required so every existing construction
         * site keeps compiling, and because yellow is the honest answer
         * for an alert that never said: audible, but not an emergency.
         */
        public readonly string $level = self::LEVEL_YELLOW,
        /**
         * Whether somebody has to take this on, or everybody just reads
         * it. One of {@see RESPONSES}.
         *
         * Defaults to {@see RESPONSE_FIRST} because that is what every
         * alert did before the field existed, and a row written then
         * must go on meaning what it meant.
         */
        public readonly string $response = self::RESPONSE_FIRST,
        /**
         * The responder who raised this, or '' when nothing did.
         *
         * <b>Empty is the ordinary case and is not missing data.</b>
         * Every alert raised by a plugin or from wp-admin has no
         * responder behind it — a plugin is not a person and an
         * administrator has no handset — and that is what every row
         * written before this column existed also means. It is set only
         * when an alert was raised from a handset, which is the one case
         * where there is somewhere for a reply to go.
         *
         * <b>Never sent to a handset.</b> It is an address, and
         * {@see toArray()} carries none. A reply is routed by it
         * server-side; the name a responder actually sees travels as a
         * payload property.
         */
        public readonly string $senderEmail = '',
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

    /**
     * Whether this is a reply carrying somebody's words back to a
     * sender. See {@see KIND_REPLY}.
     */
    public function isReply(): bool
    {
        return $this->kind === self::KIND_REPLY;
    }

    /**
     * Whether this alert is Reach talking about another alert rather
     * than something in its own right.
     *
     * The two notices are the things that must never breed: a reply to
     * a reply, or an acknowledgement of an acknowledgement, is a loop
     * between two handsets with nothing at the end of it. Asked by
     * {@see AcknowledgementNotifier} and by the reply and resend routes,
     * so the three cannot drift on what counts as a notice.
     */
    public function isNotice(): bool
    {
        return $this->isAcknowledgementNotice() || $this->isReply();
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * Whether this is the loudest kind of alert. Asks the level, not the
     * priority: {@see PRIORITY_URGENT} is now only a spelling.
     */
    public function isUrgent(): bool
    {
        return $this->level === self::LEVEL_RED;
    }

    /**
     * The same alert, knowing whether contact details are held for it.
     *
     * <b>Why this exists.</b> {@see AlertRepository::create()} builds the
     * alert from the request, and the contact is written to its own table
     * immediately afterwards — so the value the dispatcher then hands to
     * the transports says `hasContact: false` however many details were
     * supplied. The poll knows better, because it joins the contacts
     * table; the push had no way to.
     *
     * The consequence was a handset that received an alert by push never
     * offering *Show contact* at all, and never being corrected, because
     * the poll copy that had it right arrived second and was discarded as
     * a duplicate. On Android, where push is the fast path and usually
     * wins, that made the whole audited caller-details flow unreachable.
     *
     * A new instance rather than a mutation, because {@see Alert} is
     * readonly and should stay that way.
     */
    public function withContact(bool $hasContact): self
    {
        return new self(
            $this->id,
            $this->kind,
            $this->source,
            $this->priority,
            $this->title,
            $this->body,
            $this->reference,
            $this->payload,
            $this->targetEmail,
            $this->createdAt,
            $this->expiresAt,
            $hasContact,
            $this->targetDeviceId,
            $this->messageUuid,
            $this->excludeDeviceId,
            $this->level,
            $this->response,
            $this->senderEmail,
        );
    }

    /**
     * Whether the first responder to acknowledge takes this on, and the
     * rest are told and cleared. See {@see RESPONSE_FIRST}.
     */
    public function isFirstToRespond(): bool
    {
        return $this->response === self::RESPONSE_FIRST;
    }

    /**
     * Whether this is something everybody reads and closes for
     * themselves. See {@see RESPONSE_NONE}.
     */
    public function isInformational(): bool
    {
        return $this->response === self::RESPONSE_NONE;
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
            // How loud, and what colour the card is.
            'level'      => $this->level,
            // Whether the button says Acknowledge or Close.
            'response'   => $this->response,
            // <b>Derived, and sent for handsets that predate the level.</b>
            // An older Hand build reads `priority` and ignores every field
            // it does not know, so a level it has never heard of still
            // reaches it as the urgency it would have been given before.
            // See PRIORITY_NORMAL.
            'priority'   => self::priorityFor($this->level),
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

    /**
     * Coerce a level to one we recognise, defaulting to yellow.
     *
     * Same permissiveness as {@see normalisePriority()} and for the same
     * reason: a plugin inventing a level should still get its alert
     * delivered. The default widens to the middle rung rather than to
     * red — an alert nobody classified is not thereby an emergency, and
     * guessing upwards would mean a typo took over somebody's screen at
     * 3am.
     */
    public static function normaliseLevel(string $level): string
    {
        $level = strtolower(trim($level));
        return in_array($level, self::LEVELS, true) ? $level : self::LEVEL_YELLOW;
    }

    /**
     * Coerce a response requirement to one we recognise, defaulting to
     * first-to-respond.
     *
     * <b>The default is the safe direction here, and it is the opposite
     * of the one instinct suggests.</b> Falling back to informational
     * would mean a mistyped value quietly left an alert on thirty
     * screens after somebody had already dealt with it; falling back to
     * first-to-respond costs at worst a notice nobody needed. It is also
     * what every alert did before this field existed.
     */
    public static function normaliseResponse(string $response): string
    {
        $response = strtolower(trim($response));
        return in_array($response, self::RESPONSES, true) ? $response : self::RESPONSE_FIRST;
    }

    /**
     * The priority an older handset should be told, for a given level.
     * See {@see PRIORITY_NORMAL} on why this still exists.
     */
    public static function priorityFor(string $level): string
    {
        return $level === self::LEVEL_RED ? self::PRIORITY_URGENT : self::PRIORITY_NORMAL;
    }

    /**
     * The level a caller meant when it passed a priority instead.
     *
     * Normal maps to yellow rather than blue: a caller using the old API
     * asked for an alert that makes a noise, and blue does not. Nothing
     * in the two-value vocabulary could ask for blue at all, which is
     * why the level exists.
     */
    public static function levelForPriority(string $priority): string
    {
        return self::normalisePriority($priority) === self::PRIORITY_URGENT
            ? self::LEVEL_RED
            : self::LEVEL_YELLOW;
    }
}
