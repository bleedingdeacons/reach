<?php

declare(strict_types=1);

namespace Reach\Alerts\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\Fcm\FcmClient;
use Reach\Alerts\Fcm\ServiceAccount;
use Reach\Core\Settings;
use Reach\Alerts\PayloadCipher;
use Reach\Logger\HasLogger;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;

/**
 * Delivers alerts through Firebase Cloud Messaging.
 *
 * Covers the Android and iOS heads. Windows and macOS have no FCM
 * coverage and are not attempted — {@see supports()} declines them and
 * they collect their alerts by polling instead.
 *
 * <b>The message shape is the whole point of this class, so it is worth
 * explaining why it is shaped this way.</b>
 *
 * The requirement is that a handset makes a noise when an alert arrives
 * even if Hand is closed. Android and iOS reach that outcome by
 * opposite routes, and the two have to be expressed in one message.
 *
 * <i>Android — data-only, deliberately.</i> The obvious thing is to
 * send a `notification` block and let the system tray display it. That
 * is a trap here: when a message carries `notification` and the app is
 * in the background, Android's system tray handles it and the app's
 * `onMessageReceived` is never called — so Hand never gets the chance
 * to raise a full-screen intent or start a looping alarm, and a duty
 * handset gets a single polite ding instead of ringing like a phone.
 * Sending data only means Hand is always the one that builds the
 * notification, which is what lets it use a full-screen intent, the
 * alarm category, and a sound that loops until acknowledged. `priority:
 * high` is what wakes the app to do it; without that the message is
 * held for a maintenance window and can arrive an hour late.
 *
 * <i>iOS — the payload must carry the sound, because nothing else can.</i>
 * A terminated iOS app runs no code at all, so there is no
 * `onMessageReceived` equivalent to build anything. Whatever noise the
 * handset makes has to be described in the APNs payload itself and
 * played by the system: hence a full `aps` dictionary with an explicit
 * sound file, capped at the 30 seconds iOS allows.
 *
 * <i>Critical alerts.</i> `"critical": 1` is what overrides the ringer
 * switch and Do Not Disturb — exactly what a helpline handset wants,
 * and exactly why Apple gates it behind an entitlement granted only on
 * application. When the entitlement has not been granted, sending the
 * critical flag gets the notification rejected rather than downgraded,
 * so it is off by default and enabled from settings once Apple has said
 * yes. Without it, `time-sensitive` still breaks through a Focus mode,
 * which is most of the benefit for none of the paperwork.
 *
 * The TTL matches the alert's own expiry. There is no value in FCM
 * retrying delivery of an alert that has already gone stale — a
 * helpline callback nobody answered an hour ago should not ring a
 * handset now.
 */
final class FcmTransport implements AlertTransport
{
    use HasLogger;

    /**
     * Notification channel id on Android. Must match the channel Hand
     * creates, or the alert lands on the default channel with the
     * default sound and none of the alarm behaviour.
     */
    public const ANDROID_CHANNEL = 'reach_alerts';

    /**
     * Sound resource names, per platform. Android resolves this against
     * `res/raw` by bare name, iOS against the bundle by filename, and
     * both must ship the file — a name that resolves to nothing falls
     * back to silence on iOS, which is the one failure this feature
     * cannot tolerate.
     *
     * The same linear-PCM WAV serves both. iOS accepts CAF, AIFF or WAV
     * for a notification sound provided it is under 30 seconds, and a
     * WAV avoids needing a Mac (afconvert) in the pipeline just to
     * produce the asset.
     */
    private const ANDROID_SOUND = 'reach_alert';
    private const IOS_SOUND = 'reach_alert.wav';

    /**
     * The single data key an encrypted push carries.
     *
     * A constant because it is counted as well as written — see
     * {@see FCM_DATA_BYTES} — and a budget computed from a different
     * spelling than the one sent would be a budget that is quietly
     * wrong.
     */
    private const CIPHERTEXT_KEY = 'ciphertext';

    /**
     * What FCM allows in a data message.
     *
     * <b>Keys count towards it, not just values.</b> The limit is on the
     * data block as a whole, so the ten characters of
     * {@see CIPHERTEXT_KEY} come out of the same 4096 the payload does.
     * Measuring the blob alone would let a payload 4090 bytes long pass
     * a check it fails at FCM by six.
     *
     * What is not counted is the JSON syntax around them — the braces,
     * quotes and colon — because the limit is on the data, not on its
     * encoding.
     */
    private const FCM_DATA_BYTES = 4096;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    public function __construct(
        private readonly FcmClient $client,
        private readonly Settings $settings,
        private readonly DeviceRepository $devices,
    ) {
    }

    public function supports(Device $device): bool
    {
        if (!$device->wantsPush()) {
            return false;
        }

        // Only the mobile heads. maccatalyst and windows enrol happily
        // and simply never claim the FCM transport, but check the
        // platform too rather than trusting the claim.
        if ($device->platform !== 'android' && $device->platform !== 'ios') {
            return false;
        }

        return $this->account() !== null;
    }

    public function deliver(Alert $alert, Device $device): bool
    {
        $account = $this->account();
        if ($account === null) {
            return false;
        }

        $message = $this->message($alert, $device);
        if ($message === null) {
            return false;
        }

        return $this->client->send($account, $message);
    }

    /**
     * Build the FCM `message` body for one alert to one device.
     *
     * Null when this handset cannot be sent to. See {@see dataFor()}.
     *
     * @return array<string, mixed>|null
     */
    private function message(Alert $alert, Device $device): ?array
    {
        $data = $this->dataFor($alert, $device);
        if ($data === null) {
            return null;
        }

        $ttlSeconds = max(1, $alert->expiresAt - time());

        return [
            'token' => $device->pushToken,
            // Top-level data only. See the class docblock: adding a
            // `notification` here would stop Android's app-side handler
            // from ever running.
            'data'  => $data,
            'android' => [
                'priority' => 'high',
                'ttl'      => $ttlSeconds . 's',
            ],
            'apns' => [
                'headers' => [
                    'apns-priority'   => '10',
                    'apns-push-type'  => 'alert',
                    'apns-expiration' => (string) $alert->expiresAt,
                ],
                'payload' => [
                    'aps' => $this->aps($alert),
                    // Repeated outside `aps` because iOS hands the app
                    // everything *except* the aps dictionary when it is
                    // opened from a notification.
                    'reach' => $this->data($alert),
                ],
            ],
        ];
    }

    /**
     * The data block for one handset.
     *
     * <b>On Android this is one field.</b> `ciphertext`, and nothing
     * beside it. Everything the handset needs — the alert id it will
     * acknowledge against, the kind so a removal notice never reaches
     * the alarm, the priority, the channel and sound it builds the
     * notification from, and whatever the raising plugin attached — is
     * inside the blob. The handset holds one key and opens one thing; it
     * needs nothing readable first.
     *
     * The rule this replaces was "an alert names nobody", which
     * {@see \Reach\Alerts\AlertRequest} enforces by capping and
     * stripping rather than by reading meaning. Encrypting the lot
     * removes the question instead of policing it.
     *
     * <b>iOS is still plaintext.</b> An iOS lock screen is rendered by
     * the system from the `aps` dictionary before the app sees anything,
     * so ciphertext there would put base64 on the lock screen rather
     * than hide anything. The way round it is a Notification Service
     * Extension in Hand, which is wanted and not yet built: it needs its
     * own project, an App Group entitlement the app does not have, the
     * payload key moved to a shared keychain, and Apple provisioning —
     * none of it doable without a Mac and a developer account to hand.
     *
     * So iOS is waiting on hardware rather than on a decision. When the
     * extension ships, this branch collapses and the transport encrypts
     * for both platforms. Until then, what keeps iOS survivable is the
     * convention above — which on iOS is currently the only protection
     * rather than the second layer, and is the reason to finish this.
     *
     * <b>An Android handset that cannot be encrypted for is not sent
     * to.</b> Null rather than a plaintext fallback, and the refusal is
     * logged as an error so it surfaces on the Sentinel dashboard rather
     * than only in a file nobody opens.
     *
     * This is a deliberate reversal of the obvious instinct, which is
     * that a degraded alert beats no alert. The reasoning against it: a
     * silent downgrade means a handset quietly receiving caller-adjacent
     * text in the clear through Google for as long as nobody notices,
     * and nobody would, because everything keeps working. A refusal is
     * loud, appears on the dashboard, and is fixed by the responder
     * signing in again — which is a minute's work and the same recovery
     * as a lost token. Hand refuses from its own side too: a push with
     * no `ciphertext` is ignored outright.
     *
     * @return array<string, string>|null
     */
    private function dataFor(Alert $alert, Device $device): ?array
    {
        if ($device->platform !== 'android') {
            return $this->data($alert);
        }

        $key = $this->devices->payloadKeyFor($device->id);
        if ($key === '') {
            self::logError('Handset has no payload key; alert not sent', [
                'device'    => $device->id,
                'responder' => $device->memberEmail,
                'alert'     => $alert->id,
                'remedy'    => 'The responder should sign in again to be issued one.',
            ]);

            return null;
        }

        $sealed = PayloadCipher::seal($this->data($alert), $key);
        if ($sealed === '') {
            // The same outcome for the same reason: this handset cannot be
            // sent to in the only form it should be sent to. A key that
            // will not seal is a key that has been corrupted in storage,
            // and re-enrolling replaces it.
            self::logError('Handset payload key could not be used; alert not sent', [
                'device'    => $device->id,
                'responder' => $device->memberEmail,
                'alert'     => $alert->id,
                'remedy'    => 'The responder should sign in again to be issued a new key.',
            ]);

            return null;
        }

        $bytes = strlen(self::CIPHERTEXT_KEY) + strlen($sealed);

        if ($bytes > self::FCM_DATA_BYTES) {
            // Refused here rather than left to fail inside FCM, and for
            // the same reason as the two above: it becomes an error on
            // the dashboard instead of an alert that was accepted and
            // then quietly never arrived.
            //
            // Should not be reachable. AlertRequest's caps put the worst
            // case it will accept at about a quarter of this once
            // compressed — see PayloadCipher. It exists because those two
            // sets of numbers live in different files and nothing but
            // this connects them.
            self::logError('Alert payload is too large to push; alert not sent', [
                'device'    => $device->id,
                'responder' => $device->memberEmail,
                'alert'     => $alert->id,
                'bytes'     => $bytes,
                'limit'     => self::FCM_DATA_BYTES,
                'remedy'    => 'The raising plugin is sending more than an alert is meant to carry.',
            ]);

            return null;
        }

        return [self::CIPHERTEXT_KEY => $sealed];
    }

    /**
     * The structured body Hand reads to build its own alarm.
     *
     * Every value is a string: FCM's data block is a string→string map
     * and silently rejects anything else.
     *
     * @return array<string, string>
     */
    private function data(Alert $alert): array
    {
        // The alert's own fields go first, because `+` keeps the *left*
        // operand's value on a key collision. That ordering is the whole
        // protection: nothing upstream strips reserved names from a
        // payload — see AlertRequest::payload(), which only flattens and
        // caps — so a plugin that puts its own "kind" or "alert_id" in
        // the payload would otherwise change what Hand thinks the alert
        // is, and what it acknowledges against.
        return [
            'alert_id'  => (string) $alert->id,
            'kind'      => $alert->kind,
            'source'    => $alert->source,
            'priority'  => $alert->priority,
            'title'     => $alert->title,
            'body'      => $alert->body,
            'reference' => $alert->reference,
            'channel'   => self::ANDROID_CHANNEL,
            'sound'     => self::ANDROID_SOUND,
        ] + $alert->payload;
    }

    /**
     * The APNs `aps` dictionary. See the class docblock on critical
     * alerts and why the flag is configuration rather than a constant.
     *
     * @return array<string, mixed>
     */
    private function aps(Alert $alert): array
    {
        $critical = $alert->isUrgent() && $this->settings->isApnsCriticalEnabled();

        return [
            'alert' => [
                'title' => $alert->title,
                'body'  => $alert->body,
            ],
            'sound' => [
                'critical' => $critical ? 1 : 0,
                'name'     => self::IOS_SOUND,
                'volume'   => 1.0,
            ],
            'interruption-level' => $critical ? 'critical' : 'time-sensitive',
            // Wakes the app when it is merely backgrounded rather than
            // terminated, so it can start the looping alarm that the
            // 30-second payload sound cannot provide on its own.
            'content-available' => 1,
            'category' => 'REACH_ALERT',
        ];
    }

    /**
     * The configured service account, or null when FCM has not been set
     * up. Parsed on each call rather than cached: the parse is cheap,
     * and caching it would mean an admin's settings change did not take
     * effect until the next request.
     */
    private function account(): ?ServiceAccount
    {
        return ServiceAccount::fromJson($this->settings->getFcmServiceAccount());
    }
}
