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
     * The handset's key, and the plaintext it should be able to recover.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function sealedFor(array $message): array
    {
        $data = $message['data'];

        $this->assertArrayHasKey('ciphertext', $data);
        $this->assertArrayNotHasKey('title', $data, 'the readable fields must be gone, not merely duplicated');
        $this->assertArrayNotHasKey('body', $data);
        $this->assertArrayNotHasKey('reference', $data);

        return [$data['ciphertext'], $data];
    }

    /** Decrypt as the handset would, to prove the handset can. */
    private function open(string $sealed, string $base64Key): array
    {
        $raw = base64_decode($sealed, true);
        $this->assertIsString($raw);

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            (string) base64_decode($base64Key, true),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );

        $this->assertIsString($plain, 'the handset must be able to decrypt with the key it was issued');

        return (array) json_decode($plain, true);
    }

    public function testAnAndroidHandsetWithAKeyGetsAnEncryptedPayload(): void
    {
        $device = $this->device('android');
        $key = base64_encode(random_bytes(32));
        $this->devices->payloadKeys[$device->id] = $key;

        $alert = $this->alert(title: 'Callback wanted CR-000123', body: 'Male 12th-stepper wanted in BS5');

        [$sealed] = $this->sealedFor($this->deliver($alert, $device));

        // The whole point: nothing readable crosses Google.
        $this->assertStringNotContainsString('Callback wanted', $sealed);
        $this->assertStringNotContainsString('BS5', $sealed);

        $opened = $this->open($sealed, $key);

        $this->assertSame('Callback wanted CR-000123', $opened['title']);
        $this->assertSame('Male 12th-stepper wanted in BS5', $opened['body']);
    }

    public function testTheFieldsTheHandsetNeedsBeforeDecryptingStayInTheClear(): void
    {
        // A handset has to know what it is holding before it can open it:
        // which alert to acknowledge, whether this is the removal notice
        // that must never alarm, how urgent it is, and when it expires.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        [, $data] = $this->sealedFor($this->deliver($this->alert(), $device));

        foreach (['alert_id', 'kind', 'source', 'priority', 'channel', 'sound'] as $field) {
            $this->assertArrayHasKey($field, $data, $field . ' is needed before the payload can be opened');
        }
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
        // FCM caps a data message at 4KB and encryption adds about a
        // third to what it covers. This is the worst case AlertRequest
        // allows, so if it ever stops fitting the caps have moved.
        $device = $this->device('android');
        $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

        $alert = $this->alert(
            title: str_repeat('t', 200),
            body: str_repeat('b', 1000),
        );

        $message = $this->deliver($alert, $device);
        $encoded = (string) json_encode($message['data']);

        $this->assertLessThan(4096, strlen($encoded), 'the data block must fit an FCM message');
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
