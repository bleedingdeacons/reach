<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Devices\Device;
use Reach\Logger\HasLogger;
use WP_Error;

/**
 * Tells the rest of the rota that somebody has picked a message up.
 *
 * <b>The problem it solves.</b> A broadcast rings every certified
 * handset at once, and the first responder to answer silences only
 * their own. Everybody else's goes on shouting about a job that is
 * already being done, and they find out it was done by acknowledging it
 * themselves — which is to say, by being woken for nothing and then
 * telling Reach they dealt with something they did not. The
 * acknowledgement was already recorded; it simply never went anywhere a
 * handset could see it.
 *
 * So an acknowledgement now raises a second message of its own: the
 * same shape as any other alert, addressed to everybody the first one
 * went to, saying who answered. Hand shows it quietly, does not alarm
 * for it, and uses it to mark the original as answered — see
 * {@see Alert::KIND_ACKNOWLEDGED}.
 *
 * <b>Who "everybody it went to" is cannot be read off the acknowledged
 * row.</b> An administrator messaging a responder who holds a phone and
 * a tablet raises two device-targeted alerts on purpose, and the one
 * that was answered names only the handset that answered it. The
 * addresses are therefore recovered from every row sharing the message
 * uuid, and a broadcast among them wins: a message that went to
 * everybody is answered to everybody.
 *
 * <b>Except the handset that acknowledged.</b> That is what
 * {@see Alert::$excludeDeviceId} is for, and it is not cosmetic — a
 * responder who presses Acknowledge and is immediately pushed a
 * notification about having pressed Acknowledge would reasonably
 * conclude the app is broken.
 *
 * <b>And never for a notice.</b> A notice is itself acknowledged, on
 * every handset it reaches, and each of those would raise another. The
 * guard in {@see announce()} is what stops one answered helpline call
 * from becoming an unbounded correspondence.
 *
 * <b>Only the first answer is news</b>, for the same reason at one
 * remove: the second and third responders to press a button are not
 * picking the job up, they are clearing a card about somebody who
 * already did. See {@see alreadyAnnounced()}.
 *
 * <b>What it may say.</b> The same rule as every other alert: no
 * personal data. The responder is named by their Unity anonymous name —
 * the form the whole suite uses in front of people — and never by their
 * email address, which is neither anonymous nor any use on a lock
 * screen. Where no name resolves it says so generically rather than
 * falling back to the address. The original title is quoted back, which
 * adds no exposure: it is being shown to the handsets that were already
 * sent it.
 */
final class AcknowledgementNotifier
{
    use HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    /**
     * Payload keys the notice carries, and the contract Hand reads it
     * by. Constants because both ends have to spell them the same way
     * and only one end is in this repository.
     */
    public const PAYLOAD_MESSAGE_UUID = 'ack_message_uuid';
    public const PAYLOAD_ALERT_ID = 'ack_alert_id';
    public const PAYLOAD_RESPONDER = 'ack_responder';
    public const PAYLOAD_DEVICE_ID = 'ack_device_id';
    public const PAYLOAD_AT = 'ack_at';

    /** What the notice says when no member record resolves. */
    public const UNKNOWN_RESPONDER = 'Another responder';

    /**
     * Longest a notice stays live. It is news, and news about a message
     * that has itself expired is not worth ringing anybody's tray about.
     */
    private const MAX_TTL_SECONDS = 3600;

    /** Floor, because {@see AlertRequest} reads anything under a minute as absent. */
    private const MIN_TTL_SECONDS = 60;

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertDispatcher $dispatcher,
    ) {
    }

    /**
     * Announce that $device has acknowledged $alert.
     *
     * Raises one alert per address the original message had, less the
     * acknowledging handset. Does nothing at all when the original was
     * itself a notice, or when there is nobody left to tell.
     *
     * Failures are logged and swallowed. This is called from the
     * acknowledgement endpoint, and a handset that has successfully
     * silenced its alarm must not be answered with an error because a
     * courtesy to somebody else could not be raised.
     */
    public function announce(Alert $alert, Device $device, string $responderName, int $now): void
    {
        // No notices about notices. See the class docblock.
        if ($alert->isAcknowledgementNotice()) {
            return;
        }

        $rows = $this->messageRows($alert);

        // Only the first answer is news. See {@see alreadyAnnounced()}.
        if ($this->alreadyAnnounced($rows)) {
            return;
        }

        $addresses = $this->addresses($rows, $device);
        if ($addresses === []) {
            return;
        }

        // One uuid for this announcement, however many handsets it takes
        // to deliver — the same rule every other send follows. The
        // message it is *about* travels as a payload property, because
        // reusing its uuid here would make the notice indistinguishable
        // from the thing it reports on.
        $messageUuid = MessageUuid::generate();

        $raised = 0;
        foreach ($addresses as $address) {
            if ($this->raise($alert, $device, $responderName, $now, $messageUuid, $address)) {
                $raised++;
            }
        }

        self::logInfo('Acknowledgement announced', [
            'alert_id'     => $alert->id,
            'message_uuid' => $alert->messageUuid,
            'device_id'    => $device->id,
            'notices'      => $raised,
        ]);
    }

    /**
     * Every alert row the acknowledged message was raised as.
     *
     * @return array<int, Alert>
     */
    private function messageRows(Alert $alert): array
    {
        $rows = $alert->messageUuid !== ''
            ? $this->alerts->findByMessageUuid($alert->messageUuid)
            : [$alert];

        // The acknowledged row is the one certainly in play, and a uuid
        // lookup that came back empty (a row older than the column, a
        // purge mid-request) must not lose it.
        return $rows === [] ? [$alert] : $rows;
    }

    /**
     * Whether this message has already been answered by somebody.
     *
     * <b>Only the first answer is news, and the difference is not
     * cosmetic.</b> The notice tells a rota that a job is taken; every
     * handset that then reads it and presses Close is acknowledging
     * something, and without this each of those would raise a round of
     * its own. On a rota of thirty that is one callback turning into
     * nine hundred deliveries, most of them announcing that somebody
     * closed a card about somebody else closing a card.
     *
     * Counted across the whole message rather than the answered row,
     * because an administrator's message to a responder with two
     * handsets is two rows and answering on the phone is the news the
     * tablet needs.
     *
     * The caller records its acknowledgement before announcing, so at
     * this point the first answer is a count of exactly one.
     *
     * @param array<int, Alert> $rows
     */
    private function alreadyAnnounced(array $rows): bool
    {
        $answers = 0;

        foreach ($rows as $row) {
            $answers += count($this->alerts->acknowledgementsFor($row->id));

            if ($answers > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the notice has to go: one entry per address the message
     * used, with the acknowledging handset removed.
     *
     * A broadcast anywhere in the message collapses the lot — there is
     * no narrower address than "everybody", and raising a broadcast
     * *and* a targeted copy would ring somebody twice.
     *
     * @param array<int, Alert> $rows
     * @return array<int, array<string, int|string>>
     */
    private function addresses(array $rows, Device $device): array
    {
        $devices = [];
        $emails = [];

        foreach ($rows as $row) {
            if ($row->isBroadcast()) {
                return [[]];
            }

            if ($row->isDeviceTargeted()) {
                $devices[$row->targetDeviceId] = true;
                continue;
            }

            $emails[$row->targetEmail] = true;
        }

        // The handset that answered is dropped here as well as being
        // excluded on the way out. Both, because they cover different
        // things: this removes an address that would otherwise resolve to
        // nobody at all, and the exclusion covers the addresses — a
        // responder's own email, a broadcast — that still resolve to
        // other handsets it must not be one of.
        unset($devices[$device->id]);

        $addresses = [];
        foreach (array_keys($devices) as $deviceId) {
            $addresses[] = ['target_device_id' => $deviceId];
        }
        foreach (array_keys($emails) as $email) {
            $addresses[] = ['target_email' => $email];
        }

        return $addresses;
    }

    /**
     * Raise one notice at one address. True when it was accepted.
     *
     * @param array<string, int|string> $address
     */
    private function raise(
        Alert $alert,
        Device $device,
        string $responderName,
        int $now,
        string $messageUuid,
        array $address
    ): bool {
        $request = AlertRequest::fromArray($address + [
            'kind'     => Alert::KIND_ACKNOWLEDGED,
            'source'   => 'reach',
            // Never urgent, whatever the original was. Urgency escalates
            // the delivery path so it breaks through a Focus mode, and
            // nothing about "somebody else has this" is worth that.
            'priority' => Alert::PRIORITY_NORMAL,
            'title'    => $responderName . ' acknowledged',
            // The original's own title, so the notice says which message
            // it is about. Safe to repeat: these are the handsets it was
            // already sent to.
            'body'      => $alert->title,
            'reference' => $alert->reference,
            'message_uuid' => $messageUuid,
            'exclude_device_id' => $device->id,
            'ttl'      => $this->ttl($alert, $now),
            'payload'  => [
                self::PAYLOAD_MESSAGE_UUID => $alert->messageUuid,
                self::PAYLOAD_ALERT_ID     => (string) $alert->id,
                self::PAYLOAD_RESPONDER    => $responderName,
                self::PAYLOAD_DEVICE_ID    => (string) $device->id,
                self::PAYLOAD_AT           => (string) $now,
            ],
        ]);

        if ($request instanceof WP_Error) {
            self::logWarning('Acknowledgement notice was refused', [
                'alert_id' => $alert->id,
                'reason'   => $request->get_error_message(),
            ]);

            return false;
        }

        $this->dispatcher->dispatch($request, $now);

        return true;
    }

    /**
     * How long the notice lives: what is left of the message it reports
     * on, clamped into the range {@see AlertRequest} will honour.
     */
    private function ttl(Alert $alert, int $now): int
    {
        $remaining = $alert->expiresAt - $now;

        return max(self::MIN_TTL_SECONDS, min($remaining, self::MAX_TTL_SECONDS));
    }
}
