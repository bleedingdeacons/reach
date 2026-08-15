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

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$options = [];
        WpState::$transients = [];
        $this->sent = [];
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
    private function alert(string $priority = Alert::PRIORITY_NORMAL, array $payload = []): Alert
    {
        return new Alert(
            id: 12,
            kind: 'call_request',
            source: 'reach',
            priority: $priority,
            title: 'Callback wanted',
            body: 'Male 12th-stepper wanted in BS5',
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
        $transport = new FcmTransport($this->client(), $settings ?? $this->configuredSettings());
        $this->assertTrue($transport->deliver($alert, $device));
        $this->assertCount(1, $this->sent);

        return $this->sent[0];
    }

    public function testSupportsAConfiguredMobileHandset(): void
    {
        $transport = new FcmTransport($this->client(), $this->configuredSettings());

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
        $transport = new FcmTransport($this->client(), $this->configuredSettings());

        $this->assertFalse($transport->supports($this->device($platform)));
    }

    public function testADeviceWithoutPushIsDeclined(): void
    {
        $transport = new FcmTransport($this->client(), $this->configuredSettings());

        $this->assertFalse($transport->supports($this->device('android', Device::PUSH_NONE)));
    }

    public function testNothingIsSupportedUntilFcmIsConfigured(): void
    {
        // An empty service account is a supported state, not a broken
        // one — handsets poll as well as listen.
        $transport = new FcmTransport($this->client(), new Settings());

        $this->assertFalse($transport->supports($this->device('android')));
    }

    public function testDeliverDeclinesWithoutAServiceAccount(): void
    {
        $transport = new FcmTransport($this->client(), new Settings());

        $this->assertFalse($transport->deliver($this->alert(), $this->device()));
        $this->assertSame([], $this->sent);
    }

    public function testDeliverReportsAFailedSendRatherThanThrowing(): void
    {
        // The alert is already stored; a push that did not land must not
        // stop the dispatcher reaching the other handsets in the list.
        $transport = new FcmTransport($this->client(500), $this->configuredSettings());

        $this->assertFalse($transport->deliver($this->alert(), $this->device()));
    }

    public function testTheMessageCarriesNoTopLevelNotificationBlock(): void
    {
        // The single most important assertion in this file. A
        // `notification` key here means Android's system tray handles the
        // message while Hand is backgrounded, and Hand never gets to
        // raise its full-screen intent or start the looping alarm.
        $message = $this->deliver($this->alert(), $this->device());

        $this->assertArrayNotHasKey('notification', $message);
        $this->assertArrayHasKey('data', $message);
    }

    public function testAndroidGetsHighPriorityAndTheAlertsOwnTtl(): void
    {
        // Without `priority: high` the message is held for a maintenance
        // window and can arrive an hour late. The TTL matches the alert's
        // expiry — there is no value in FCM retrying an alert that has
        // already gone stale.
        $message = $this->deliver($this->alert(), $this->device());

        $this->assertSame('high', $message['android']['priority']);
        $this->assertMatchesRegularExpression('/^\d+s$/', $message['android']['ttl']);
        $this->assertLessThanOrEqual(900, (int) rtrim($message['android']['ttl'], 's'));
    }

    public function testAnExpiredAlertStillGetsAPositiveTtl(): void
    {
        // FCM rejects a ttl of 0s outright, which would turn a late
        // delivery into no delivery at all.
        $stale = new Alert(1, 'k', 'reach', 'normal', 't', '', '', [], '', 1_000, time() - 3_600);

        $message = $this->deliver($stale, $this->device());

        $this->assertSame('1s', $message['android']['ttl']);
    }

    public function testTheDeviceTokenAddressesTheMessage(): void
    {
        $message = $this->deliver($this->alert(), $this->device());

        $this->assertSame('device-token', $message['token']);
    }

    public function testTheDataBlockIsEntirelyStrings(): void
    {
        // FCM's data block is a string→string map and silently rejects
        // anything else — the worst failure mode available here.
        $message = $this->deliver($this->alert(payload: ['area' => 'BS5']), $this->device());

        foreach ($message['data'] as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value, "data.{$key} must be a string");
        }
    }

    public function testTheDataBlockDescribesTheAlertAndTheChannel(): void
    {
        $data = $this->deliver($this->alert(), $this->device())['data'];

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
            $this->device(),
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
        $transport = new FcmTransport($this->client(), $settings);
        $this->assertFalse($transport->supports($this->device()));

        $settings->setFcmServiceAccount(self::accountJson());

        $this->assertTrue($transport->supports($this->device()));
    }
}
