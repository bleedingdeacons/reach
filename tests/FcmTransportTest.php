<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Alerts\Alert;
use Reach\Alerts\Fcm\FcmClient;
use Reach\Alerts\Fcm\ServiceAccount;
use Reach\Alerts\Transport\FcmTransport;
use Reach\Core\Settings;
use Reach\Devices\Device;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\ReachTestCase;

/**
 * The message shape is the whole point of this class, and most of it is
 * load-bearing in a way that is invisible from the code alone — so these
 * assertions exist to stop a future tidy-up from silently turning a
 * ringing handset into a polite ding.
 *
 * The two that matter most:
 *
 *  - <b>No top-level `notification` block.</b> When one is present and
 *    the app is backgrounded, Android's system tray handles the message
 *    and Hand's own handler never runs — so no full-screen intent and no
 *    looping alarm.
 *  - <b>The iOS sound is named in the payload.</b> A terminated iOS app
 *    runs no code, so whatever noise the handset makes has to be
 *    described in the APNs payload and played by the system.
 */
final class FcmTransportTest extends ReachTestCase
{
    /** @var array<int, array<string, mixed>> Messages POSTed to FCM, decoded. */
    private array $sent = [];

    /** Seeded per test; a device with a key here gets an encrypted payload. */
    private InMemoryDeviceRepository $devices;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$options = [];
        WpState::$transients = [];
        $this->sent = [];
        $this->devices = new InMemoryDeviceRepository();
    }

    private static function accountJson(): string
    {
        return (string) json_encode([
            'project_id'   => 'reach-alerts',
            'client_email' => 'pusher@reach-alerts.iam.gserviceaccount.com',
            'private_key'  => 'key-material',
        ]);
    }

    private function configuredSettings(): Settings
    {
        $settings = new Settings();
        $settings->setFcmServiceAccount(self::accountJson());

        return $settings;
    }

    /**
     * The real {@see FcmClient}, with the HTTP layer stubbed and an
     * access token already in cache.
     *
     * FcmClient is final and FcmTransport type-hints it concretely, so
     * there is no double to substitute — but there is no need for one.
     * Seeding the token transient skips the JWT assertion entirely (so
     * these tests need no openssl.cnf, unlike FcmClientTest), and reading
     * the message back off the recorded POST exercises the real seam
     * between the two classes rather than a stand-in for it.
     *
     * @param int $sendStatus the status FCM answers the send with
     */
    private function client(int $sendStatus = 200): FcmClient
    {
        $account = ServiceAccount::fromJson(self::accountJson());
        $this->assertNotNull($account);
        WpState::$transients['reach_fcm_token_' . $account->fingerprint()] = 'ya29.cached';

        $sent = &$this->sent;
        $this->stubHttp(static function (string $url, array $args = []) use (&$sent, $sendStatus) {
            $decoded = json_decode((string) ($args['body'] ?? ''), true);
            if (is_array($decoded) && isset($decoded['message']) && is_array($decoded['message'])) {
                $sent[] = $decoded['message'];
            }

            return ['response' => ['code' => $sendStatus], 'body' => '{}'];
        });

        return new FcmClient();
    }

    private function device(string $platform = 'android', string $push = Device::PUSH_FCM): Device
    {
        return new Device(
            id: 7,
            memberEmail: 'jo@example.com',
            memberId: 42,
            label: 'Duty handset',
            platform: $platform,
            pushProvider: $push,
            pushToken: $push === Device::PUSH_NONE ? '' : 'device-token',
            createdAt: 1_000,
        );
    }

    /** @param array<string, string> $payload */
    private function alert(
        string $priority = Alert::PRIORITY_NORMAL,
        array $payload = [],
        string $title = 'Callback wanted',
        string $body = 'Male 12th-stepper wanted in BS5',
    ): Alert {
        return new Alert(
            id: 12,
            kind: 'call_request',
            source: 'reach',
            priority: $priority,
            title: $title,
            body: $body,
            reference: 'CR-000123',
            payload: $payload,
            targetEmail: '',
            createdAt: time(),
            expiresAt: time() + 900,
        );
    }

    /**
     * Deliver one alert and hand back the FCM `message` body it produced.
     *
     * @return array<string, mixed>
     */
    private function deliver(Alert $alert, Device $device, ?Settings $settings = null): array
    {
        $transport = new FcmTransport($this->client(), $settings ?? $this->configuredSettings(), $this->devices);
        $this->assertTrue($transport->deliver($alert, $device));
        $this->assertCount(1, $this->sent);

        return $this->sent[0];
    }

    // ── payload encryption ────────────────────────────────────────────

    /**
     * The sealed blob, having first checked it is travelling alone.
     *
     * @param array<string, mixed> $message
     */
    private function sealedFor(array $message): string
    {
        $data = $message['data'];

        // One key, and only one. This is the assertion the whole change
        // is for: a field left outside the blob is a field readable by
        // whoever handles the push on the way, and the way to stop one
        // being added back is to refuse the whole shape rather than to
        // list the names as they occur to us.
        $this->assertSame(['ciphertext'], array_keys($data), 'nothing may travel beside the sealed payload');

        return $data['ciphertext'];
    }

    /**
     * Decrypt as the handset would, to prove the handset can.
     *
     * Deliberately hand-rolled from the wire format rather than routed
     * through {@see \Reach\Alerts\PayloadCipher} — this is the half of
     * the contract Hand implements, in a different language in a
     * different repository, and a helper shared with the code under test
     * would pass just as happily if both ends changed together.
     *
     * @return array<string, string>
     */
    private function open(string $sealed, string $base64Key): array
    {
        $raw = base64_decode($sealed, true);
        $this->assertIsString($raw);

        $compressed = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            (string) base64_decode($base64Key, true),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );

        $this->assertIsString($compressed, 'the handset must be able to decrypt with the key it was issued');

        $plain = gzdecode($compressed);
        $this->assertIsString($plain, 'the handset must be able to decompress what it decrypted');

        return (array) json_decode($plain, true);
    }

    public function testAnAndroidHandsetGetsTheWholePayloadEncrypted(): void
    {
        $device = $this->device('android');
        $key = base64_encode(random_bytes(32));
        $this->devices->payloadKeys[$device->id] = $key;

        $alert = $this->alert(
            title: 'Callback wanted CR-000123',
            body: 'Male 12th-stepper wanted in BS5',
            payload: ['area' => 'BS5'],
        );

        $sealed = $this->sealedFor($this->deliver($alert, $device));

        // The whole point: nothing readable crosses Google.
        $this->assertStringNotContainsString('Callback wanted', $sealed);
        $this->assertStringNotContainsString('BS5', $sealed);
        $this->assertStringNotContainsString('call_request', $sealed);

        // And everything survives the trip, opened the way Hand opens it.
        $opened = $this->open($sealed, $key);

        $this->assertSame('12', $opened['alert_id']);
        $this->assertSame('call_request', $opened['kind']);
        $this->assertSame('reach', $opened['source']);
        $this->assertSame('normal', $opened['priority']);
        $this->assertSame('Callback wanted CR-000123', $opened['title']);
        $this->assertSame('Male 12th-stepper wanted in BS5', $opened['body']);
        $this->assertSame('CR-000123', $opened['reference']);
        $this->assertSame(FcmTransport::ANDROID_CHANNEL, $opened['channel']);
        $this->assertSame('reach_alert', $opened['sound']);
        // The raising plugin's extras go inside too.
        $this->assertSame('BS5', $opened['area']);
    }

    public function testTheFieldsTheHandsetOnceNeededInTheClearAreSealedToo(): void
    {
        // These used to travel readable on the grounds that a handset had
        // to know what it was holding before it could open it. It does
        // not: it holds one key and opens one blob.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        $data = $this->deliver($this->alert(), $device)['data'];

        foreach (['alert_id', 'kind', 'source', 'priority', 'channel', 'sound'] as $field) {
            $this->assertArrayNotHasKey($field, $data, $field . ' must be inside the sealed payload');
        }
    }

    public function testAPluginsExtrasCannotEscapeTheSealedPayload(): void
    {
        // A plugin puts whatever it likes in the payload and the transport
        // merges it into the map it seals. Nothing in that merge may end
        // up outside the blob, or a plugin would be able to put readable
        // text on the push by choosing a name nothing strips.
        $device = $this->device('android');
        $key = base64_encode(random_bytes(32));
        $this->devices->payloadKeys[$device->id] = $key;

        $alert = $this->alert(payload: ['ciphertext' => 'spoofed', 'area' => 'BS5']);

        $sealed = $this->sealedFor($this->deliver($alert, $device));

        $this->assertNotSame('spoofed', $sealed);
        $this->assertSame('spoofed', $this->open($sealed, $key)['ciphertext']);
    }

    public function testAnIosHandsetIsNotEncrypted(): void
    {
        // Its lock screen is rendered by the system from the aps
        // dictionary, so ciphertext would put base64 on the lock screen
        // rather than hide anything. Waiting on a service extension.
        $device = $this->device('ios');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        $message = $this->deliver($this->alert(title: 'Callback wanted'), $device);

        $this->assertArrayNotHasKey('ciphertext', $message['data']);
        $this->assertSame('Callback wanted', $message['data']['title']);
    }

    public function testAnAndroidHandsetWithNoKeyIsNotSentTo(): void
    {
        // Deliberately not a plaintext fallback. A silent downgrade means
        // a handset quietly receiving readable text through Google for as
        // long as nobody notices — and nobody would, because everything
        // would keep working. Refusing is loud, and re-enrolling fixes it.
        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->deliver($this->alert(), $this->device('android')));
        $this->assertSame([], $this->sent, 'nothing may go to a handset that cannot be encrypted for');
    }

    public function testAnUnusableKeyIsAlsoARefusal(): void
    {
        // Same outcome for the same reason: this handset cannot be sent to
        // in the only form it should be sent to.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = 'not-a-32-byte-key';

        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->deliver($this->alert(), $device));
        $this->assertSame([], $this->sent);
    }

    public function testTheEncryptedMessageStaysInsideFcmsSizeCap(): void
    {
        // The worst case AlertRequest allows, in full: title 200, body
        // 1000, reference 64 and 2000 bytes of payload. Uncompressed that
        // seals to 4616 bytes and would be rejected by FCM, which is why
        // PayloadCipher gzips first. If this ever stops fitting, either
        // the caps have moved or the compression has gone.
        //
        // Random hex rather than repeated characters, deliberately. A
        // worst case built from `str_repeat` compresses to almost
        // nothing and would keep passing long after the real margin had
        // gone; this is content the gzip cannot help with.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        $alert = $this->alert(
            title: bin2hex(random_bytes(100)),
            body: bin2hex(random_bytes(500)),
            payload: ['area' => bin2hex(random_bytes(1000))],
        );

        $sealed = $this->sealedFor($this->deliver($alert, $device));

        // Key included: FCM's limit is on the data block as a whole, so
        // the ten characters of `ciphertext` come out of the same budget
        // the payload does. Measuring the blob alone would pass a message
        // that FCM rejects.
        $this->assertLessThan(
            4096,
            strlen('ciphertext') + strlen($sealed),
            'the sealed payload and its key together must fit an FCM message',
        );
    }

    public function testAPayloadTooLargeToPushIsRefusedRatherThanSent(): void
    {
        // Not reachable through AlertRequest, whose caps put the worst
        // case at a quarter of the limit. The guard exists because those
        // caps live in a different file and nothing but this connects
        // them — and because failing inside FCM would mean an alert that
        // was accepted and then quietly never arrived.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        // Incompressible, so it survives the gzip at roughly full size.
        $alert = $this->alert(payload: ['blob' => bin2hex(random_bytes(6000))]);

        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->deliver($alert, $device));
        $this->assertSame([], $this->sent);
    }

    /**
     * The refusal counts the data key, not just the sealed value.
     *
     * FCM's limit is on the data block as a whole, so the ten characters
     * of `ciphertext` come out of the same 4096 the payload does. A guard
     * that measured only the value would accept a message ten bytes over
     * and leave FCM to reject it — which is the failure it exists to
     * prevent, and it would take a payload within ten bytes of the limit
     * to notice.
     *
     * Rather than pin a byte count that gzip's output length would make
     * brittle, this finds the largest payload the transport will actually
     * send and checks what that costs in FCM's own accounting. If the key
     * stopped being counted, the largest accepted message would come to
     * 4106 and this would fail.
     */
    public function testTheRefusalCountsTheDataKeyAsWellAsTheValue(): void
    {
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        // Incompressible, so payload length and sealed length move
        // together and the search below has something monotonic to bite
        // on. Deliberately past AlertRequest's own 2000-byte cap: this
        // guard exists for the case those caps do not cover.
        $largest = null;
        $low = 2_000;
        $high = 8_000;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $this->sent = [];

            $alert = $this->alert(payload: ['blob' => substr(bin2hex(random_bytes($mid)), 0, $mid)]);

            if ($transport->deliver($alert, $device)) {
                $largest = $this->sent[0]['data']['ciphertext'];
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $this->assertIsString($largest, 'some payload in this range must still be deliverable');
        $this->assertLessThanOrEqual(
            4096,
            strlen('ciphertext') + strlen($largest),
            'the largest message this will send must fit FCM, key included',
        );
    }

    public function testSupportsAConfiguredMobileHandset(): void
    {
        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertTrue($transport->supports($this->device('android')));
        $this->assertTrue($transport->supports($this->device('ios')));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedPlatforms(): array
    {
        return [
            'maccatalyst' => ['maccatalyst'],
            'windows'     => ['windows'],
        ];
    }

    /**
     * @dataProvider unsupportedPlatforms
     */
    public function testDesktopHeadsAreDeclinedAndPollInstead(string $platform): void
    {
        // They enrol happily and simply never claim this transport.
        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->supports($this->device($platform)));
    }

    public function testADeviceWithoutPushIsDeclined(): void
    {
        $transport = new FcmTransport($this->client(), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->supports($this->device('android', Device::PUSH_NONE)));
    }

    public function testNothingIsSupportedUntilFcmIsConfigured(): void
    {
        // An empty service account is a supported state, not a broken
        // one — handsets poll as well as listen.
        $transport = new FcmTransport($this->client(), new Settings(), $this->devices);

        $this->assertFalse($transport->supports($this->device('android')));
    }

    public function testDeliverDeclinesWithoutAServiceAccount(): void
    {
        $transport = new FcmTransport($this->client(), new Settings(), $this->devices);

        $this->assertFalse($transport->deliver($this->alert(), $this->device('ios')));
        $this->assertSame([], $this->sent);
    }

    public function testDeliverReportsAFailedSendRatherThanThrowing(): void
    {
        // The alert is already stored; a push that did not land must not
        // stop the dispatcher reaching the other handsets in the list.
        $transport = new FcmTransport($this->client(500), $this->configuredSettings(), $this->devices);

        $this->assertFalse($transport->deliver($this->alert(), $this->device('ios')));
    }

    public function testTheMessageCarriesNoTopLevelNotificationBlock(): void
    {
        // The single most important assertion in this file. A
        // `notification` key here means Android's system tray handles the
        // message while Hand is backgrounded, and Hand never gets to
        // raise its full-screen intent or start the looping alarm.
        $message = $this->deliver($this->alert(), $this->device('ios'));

        $this->assertArrayNotHasKey('notification', $message);
        $this->assertArrayHasKey('data', $message);
    }

    public function testAndroidGetsHighPriorityAndTheAlertsOwnTtl(): void
    {
        // Without `priority: high` the message is held for a maintenance
        // window and can arrive an hour late. The TTL matches the alert's
        // expiry — there is no value in FCM retrying an alert that has
        // already gone stale.
        $message = $this->deliver($this->alert(), $this->device('ios'));

        $this->assertSame('high', $message['android']['priority']);
        $this->assertMatchesRegularExpression('/^\d+s$/', $message['android']['ttl']);
        $this->assertLessThanOrEqual(900, (int) rtrim($message['android']['ttl'], 's'));
    }

    public function testAnExpiredAlertStillGetsAPositiveTtl(): void
    {
        // FCM rejects a ttl of 0s outright, which would turn a late
        // delivery into no delivery at all.
        $stale = new Alert(1, 'k', 'reach', 'normal', 't', '', '', [], '', 1_000, time() - 3_600);

        $message = $this->deliver($stale, $this->device('ios'));

        $this->assertSame('1s', $message['android']['ttl']);
    }

    public function testTheDeviceTokenAddressesTheMessage(): void
    {
        $message = $this->deliver($this->alert(), $this->device('ios'));

        $this->assertSame('device-token', $message['token']);
    }

    public function testTheDataBlockIsEntirelyStrings(): void
    {
        // FCM's data block is a string→string map and silently rejects
        // anything else — the worst failure mode available here.
        $message = $this->deliver($this->alert(payload: ['area' => 'BS5']), $this->device('ios'));

        foreach ($message['data'] as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value, "data.{$key} must be a string");
        }
    }

    public function testTheDataBlockDescribesTheAlertAndTheChannel(): void
    {
        $data = $this->deliver($this->alert(), $this->device('ios'))['data'];

        $this->assertSame('12', $data['alert_id']);
        $this->assertSame('call_request', $data['kind']);
        $this->assertSame('CR-000123', $data['reference']);
        // Must match the channel Hand creates, or the alert lands on the
        // default channel with the default sound and none of the alarm
        // behaviour.
        $this->assertSame(FcmTransport::ANDROID_CHANNEL, $data['channel']);
        $this->assertSame('reach_alert', $data['sound']);
    }

    public function testAPluginsPayloadCannotOverrideWhatTheAlertIs(): void
    {
        // The payload is merged first precisely so the alert's own fields
        // win a name collision.
        $data = $this->deliver(
            $this->alert(payload: ['kind' => 'spoofed', 'channel' => 'other', 'area' => 'BS5']),
            $this->device('ios'),
        )['data'];

        $this->assertSame('call_request', $data['kind']);
        $this->assertSame(FcmTransport::ANDROID_CHANNEL, $data['channel']);
        // Extras that do not collide still travel.
        $this->assertSame('BS5', $data['area']);
    }

    public function testApnsHeadersAskForImmediateDeliveryUntilTheAlertExpires(): void
    {
        $alert = $this->alert();

        $headers = $this->deliver($alert, $this->device('ios'))['apns']['headers'];

        $this->assertSame('10', $headers['apns-priority']);
        $this->assertSame('alert', $headers['apns-push-type']);
        $this->assertSame((string) $alert->expiresAt, $headers['apns-expiration']);
    }

    public function testTheApnsPayloadNamesTheSoundBecauseNothingElseCan(): void
    {
        $aps = $this->deliver($this->alert(), $this->device('ios'))['apns']['payload']['aps'];

        $this->assertSame('reach_alert.wav', $aps['sound']['name']);
        $this->assertSame(1, $aps['sound']['volume']);
        $this->assertSame('Callback wanted', $aps['alert']['title']);
        $this->assertSame('Male 12th-stepper wanted in BS5', $aps['alert']['body']);
        // Wakes a merely-backgrounded app so it can start the looping
        // alarm the 30-second payload sound cannot provide alone.
        $this->assertSame(1, $aps['content-available']);
        $this->assertSame('REACH_ALERT', $aps['category']);
    }

    public function testTheAlertDataIsRepeatedOutsideTheApsDictionary(): void
    {
        // iOS hands the app everything except `aps` when it is opened
        // from a notification, so the structured data has to appear twice.
        $message = $this->deliver($this->alert(), $this->device('ios'));

        $this->assertSame($message['data'], $message['apns']['payload']['reach']);
    }

    public function testCriticalIsOffByDefaultEvenForAnUrgentAlert(): void
    {
        // Sending the critical flag without Apple's entitlement gets the
        // notification rejected rather than downgraded — it would silence
        // the very alerts it is meant to make louder.
        $aps = $this->deliver($this->alert(Alert::PRIORITY_URGENT), $this->device('ios'))['apns']['payload']['aps'];

        $this->assertSame(0, $aps['sound']['critical']);
        // time-sensitive still breaks through a Focus mode, which is most
        // of the benefit for none of the paperwork.
        $this->assertSame('time-sensitive', $aps['interruption-level']);
    }

    public function testAnUrgentAlertGoesCriticalOnceTheEntitlementIsConfigured(): void
    {
        $settings = $this->configuredSettings();
        $settings->setApnsCriticalEnabled(true);

        $aps = $this->deliver(
            $this->alert(Alert::PRIORITY_URGENT),
            $this->device('ios'),
            $settings,
        )['apns']['payload']['aps'];

        $this->assertSame(1, $aps['sound']['critical']);
        $this->assertSame('critical', $aps['interruption-level']);
    }

    public function testAnOrdinaryAlertIsNeverCriticalEvenWithTheEntitlement(): void
    {
        // Critical overrides the ringer switch and Do Not Disturb. That is
        // for an urgent alert, not for every callback request.
        $settings = $this->configuredSettings();
        $settings->setApnsCriticalEnabled(true);

        $aps = $this->deliver(
            $this->alert(Alert::PRIORITY_NORMAL),
            $this->device('ios'),
            $settings,
        )['apns']['payload']['aps'];

        $this->assertSame(0, $aps['sound']['critical']);
        $this->assertSame('time-sensitive', $aps['interruption-level']);
    }

    public function testASettingsChangeTakesEffectWithoutARestart(): void
    {
        // The account is parsed on each call rather than cached, so an
        // admin pasting a key file does not have to wait for anything.
        $settings = new Settings();
        $transport = new FcmTransport($this->client(), $settings, $this->devices);
        $this->assertFalse($transport->supports($this->device()));

        $settings->setFcmServiceAccount(self::accountJson());

        $this->assertTrue($transport->supports($this->device()));
    }
}
