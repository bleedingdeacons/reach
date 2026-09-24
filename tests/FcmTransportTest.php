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

function accountJson(): string
{
    return (string) json_encode([
        'project_id'   => 'reach-alerts',
        'client_email' => 'pusher@reach-alerts.iam.gserviceaccount.com',
        'private_key'  => 'key-material',
    ]);
}

function configuredSettings(): Settings
{
    $settings = new Settings();
    $settings->setFcmServiceAccount(accountJson());

    return $settings;
}

function device(string $platform = 'android', string $push = Device::PUSH_FCM): Device
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
/**
 * <b>Built from the level, with the priority derived.</b> The two are
 * one dial wearing two names — see {@see Alert::PRIORITY_NORMAL} — and
 * a fixture that let them be set apart could assert a combination the
 * repository will never write.
 */
function alert(
    string $level = Alert::LEVEL_YELLOW,
    array $payload = [],
    string $title = 'Callback wanted',
    string $body = 'Male 12th-stepper wanted in BS5',
    string $response = Alert::RESPONSE_FIRST,
    bool $hasContact = false,
): Alert {
    return new Alert(
        id: 12,
        kind: 'call_request',
        source: 'reach',
        priority: Alert::priorityFor($level),
        title: $title,
        body: $body,
        reference: 'CR-000123',
        payload: $payload,
        targetEmail: '',
        createdAt: time(),
        expiresAt: time() + 900,
        hasContact: $hasContact,
        level: $level,
        response: $response,
    );
}

beforeEach(function () {
    $this->sent = [];

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
    $this->client = function (int $sendStatus = 200): FcmClient {
        $account = ServiceAccount::fromJson(accountJson());
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
    };

    /**
     * Deliver one alert and hand back the FCM `message` body it produced.
     *
     * @return array<string, mixed>
     */
    $this->deliver = function (Alert $alert, Device $device, ?Settings $settings = null): array {
        // Both platforms are sealed now, so a delivery that succeeds needs
        // a key. Seeded here rather than in each of the twenty tests that
        // only care about the message shape. The tests about refusal build
        // their transport directly and never come through this helper,
        // which is what keeps them meaning what they say.
        ($this->keyFor)($device);

        $transport = new FcmTransport(($this->client)(), $settings ?? configuredSettings(), $this->devices);
        $this->assertTrue($transport->deliver($alert, $device));
        $this->assertCount(1, $this->sent);

        return $this->sent[0];
    };

    // ── payload encryption ────────────────────────────────────────────

    /**
     * This device's payload key, minting one if the test has not.
     */
    $this->keyFor = function (Device $device): string {
        return $this->devices->payloadKeys[$device->id] ??= base64_encode(random_bytes(32));
    };

    /**
     * Deliver, then open the blob the way the handset does.
     *
     * Most of what these tests assert about is now inside the envelope on
     * both platforms, so reading it back out is the ordinary case rather
     * than a special one.
     *
     * @return array<string, string>
     */
    $this->opened = function (Alert $alert, Device $device): array {
        $key = ($this->keyFor)($device);

        return ($this->open)(($this->sealedFor)(($this->deliver)($alert, $device)), $key);
    };

    /**
     * The sealed blob, having first checked it is travelling alone.
     *
     * @param array<string, mixed> $message
     */
    $this->sealedFor = function (array $message): string {
        $data = $message['data'];

        // One key, and only one. This is the assertion the whole change
        // is for: a field left outside the blob is a field readable by
        // whoever handles the push on the way, and the way to stop one
        // being added back is to refuse the whole shape rather than to
        // list the names as they occur to us.
        $this->assertSame(['ciphertext'], array_keys($data), 'nothing may travel beside the sealed payload');

        return $data['ciphertext'];
    };

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
    $this->open = function (string $sealed, string $base64Key): array {
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
    };

    WpState::$options = [];
    WpState::$transients = [];
    $this->sent = [];
    // Seeded per test; a device with a key here gets an encrypted payload.
    $this->devices = new InMemoryDeviceRepository();
});

test('an android handset gets the whole payload encrypted', function () {
    $device = device('android');
    $key = base64_encode(random_bytes(32));
    $this->devices->payloadKeys[$device->id] = $key;

    $alert = alert(
        title: 'Callback wanted CR-000123',
        body: 'Male 12th-stepper wanted in BS5',
        payload: ['area' => 'BS5'],
    );

    $sealed = ($this->sealedFor)(($this->deliver)($alert, $device));

    // The whole point: nothing readable crosses Google.
    $this->assertStringNotContainsString('Callback wanted', $sealed);
    $this->assertStringNotContainsString('BS5', $sealed);
    $this->assertStringNotContainsString('call_request', $sealed);

    // And everything survives the trip, opened the way Hand opens it.
    $opened = ($this->open)($sealed, $key);

    $this->assertSame('12', $opened['alert_id']);
    $this->assertSame('call_request', $opened['kind']);
    $this->assertSame('reach', $opened['source']);
    $this->assertSame('normal', $opened['priority']);
    $this->assertSame('Callback wanted CR-000123', $opened['title']);
    $this->assertSame('Male 12th-stepper wanted in BS5', $opened['body']);
    $this->assertSame('CR-000123', $opened['reference']);
    $this->assertSame('yellow', $opened['level']);
    $this->assertSame('first', $opened['response']);
    $this->assertSame(FcmTransport::ANDROID_CHANNEL_WARNING, $opened['channel']);
    $this->assertSame('reach_alert', $opened['sound']);
    // The raising plugin's extras go inside too.
    $this->assertSame('BS5', $opened['area']);
});

test('the contact flag travels so a pushed alert can offer to fetch it', function () {
    // <b>The flag, never the details.</b> Whether a contact exists is
    // not personal data; the contact itself is, and still needs a
    // separate authenticated request that Reach audits.
    //
    // Without this the push said nothing, so a handset that learned
    // of an alert by push showed no Show contact button — and the
    // poll copy that knew better arrived second and was discarded as
    // a duplicate. On Android, where push usually wins, that made the
    // whole caller-details flow unreachable.
    $device = device('android');
    $key = base64_encode(random_bytes(32));
    $this->devices->payloadKeys[$device->id] = $key;

    $opened = ($this->open)(
        ($this->sealedFor)(($this->deliver)(alert(hasContact: true), $device)),
        $key,
    );

    $this->assertSame('1', $opened['has_contact']);
});

test('an alert with no contact says so rather than saying nothing', function () {
    // Explicitly '0' rather than absent. Hand reads the key as
    // "1"/"true" and anything else as false, so either would work
    // today — but a handset can only distinguish "no contact" from
    // "an older server that never said" if the field is always there.
    $device = device('android');
    $key = base64_encode(random_bytes(32));
    $this->devices->payloadKeys[$device->id] = $key;

    $opened = ($this->open)(
        ($this->sealedFor)(($this->deliver)(alert(), $device)),
        $key,
    );

    $this->assertSame('0', $opened['has_contact']);
});

test('a plugin cannot forge the contact flag', function () {
    // The alert's own fields win on a key collision, so a payload
    // claiming has_contact cannot make a handset offer to fetch
    // details that are not there.
    $device = device('android');
    $key = base64_encode(random_bytes(32));
    $this->devices->payloadKeys[$device->id] = $key;

    $opened = ($this->open)(
        ($this->sealedFor)(($this->deliver)(
            alert(payload: ['has_contact' => '1']),
            $device,
        )),
        $key,
    );

    $this->assertSame('0', $opened['has_contact']);
});

test('the fields the handset once needed in the clear are sealed too', function () {
    // These used to travel readable on the grounds that a handset had
    // to know what it was holding before it could open it. It does
    // not: it holds one key and opens one blob.
    $device = device('android');
    $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

    $data = ($this->deliver)(alert(), $device)['data'];

    foreach (['alert_id', 'kind', 'source', 'priority', 'channel', 'sound'] as $field) {
        $this->assertArrayNotHasKey($field, $data, $field . ' must be inside the sealed payload');
    }
});

test('a plugins extras cannot escape the sealed payload', function () {
    // A plugin puts whatever it likes in the payload and the transport
    // merges it into the map it seals. Nothing in that merge may end
    // up outside the blob, or a plugin would be able to put readable
    // text on the push by choosing a name nothing strips.
    $device = device('android');
    $key = base64_encode(random_bytes(32));
    $this->devices->payloadKeys[$device->id] = $key;

    $alert = alert(payload: ['ciphertext' => 'spoofed', 'area' => 'BS5']);

    $sealed = ($this->sealedFor)(($this->deliver)($alert, $device));

    $this->assertNotSame('spoofed', $sealed);
    $this->assertSame('spoofed', ($this->open)($sealed, $key)['ciphertext']);
});

test('an ios handset is encrypted too', function () {
    // It used to be exempt: an iOS lock screen is drawn by the system
    // from `aps` before any of Hand runs, so ciphertext alone would
    // have hidden nothing. Hand's notification service extension is
    // what changed that — mutable-content launches it, and it opens
    // the blob and rewrites the words before the lock screen renders.
    $device = device('ios');
    $key = ($this->keyFor)($device);

    $message = ($this->deliver)(alert(title: 'Callback wanted'), $device);

    $sealed = ($this->sealedFor)($message);
    $this->assertSame('Callback wanted', ($this->open)($sealed, $key)['title']);

    // And nothing readable stayed behind in the part the system draws.
    $aps = $message['apns']['payload']['aps'];
    $this->assertSame('Reach alert', $aps['alert']['title']);
    $this->assertSame('Open Hand for the details.', $aps['alert']['body']);
    $this->assertSame(1, $aps['mutable-content'], 'without this the extension never runs');
});

test('an ios handset with no key is not sent to', function () {
    // The same refusal Android has always had, which iOS was outside
    // for as long as it was sent plaintext. A silent downgrade would
    // mean caller-adjacent text going through Google in the clear for
    // as long as nobody noticed, and nobody would.
    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->deliver(alert(), device('ios')));
    $this->assertSame([], $this->sent, 'nothing may go to a handset that cannot be encrypted for');
});

test('an android handset with no key is not sent to', function () {
    // Deliberately not a plaintext fallback. A silent downgrade means
    // a handset quietly receiving readable text through Google for as
    // long as nobody notices — and nobody would, because everything
    // would keep working. Refusing is loud, and re-enrolling fixes it.
    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->deliver(alert(), device('android')));
    $this->assertSame([], $this->sent, 'nothing may go to a handset that cannot be encrypted for');
});

test('an unusable key is also a refusal', function () {
    // Same outcome for the same reason: this handset cannot be sent to
    // in the only form it should be sent to.
    $device = device('android');
    $this->devices->payloadKeys[$device->id] = 'not-a-32-byte-key';

    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->deliver(alert(), $device));
    $this->assertSame([], $this->sent);
});

test('the encrypted message stays inside fcms size cap', function () {
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
    $device = device('android');
    $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

    $alert = alert(
        title: bin2hex(random_bytes(100)),
        body: bin2hex(random_bytes(500)),
        payload: ['area' => bin2hex(random_bytes(1000))],
    );

    $sealed = ($this->sealedFor)(($this->deliver)($alert, $device));

    // Key included: FCM's limit is on the data block as a whole, so
    // the ten characters of `ciphertext` come out of the same budget
    // the payload does. Measuring the blob alone would pass a message
    // that FCM rejects.
    $this->assertLessThan(
        4096,
        strlen('ciphertext') + strlen($sealed),
        'the sealed payload and its key together must fit an FCM message',
    );
});

test('a payload too large to push is refused rather than sent', function () {
    // Not reachable through AlertRequest, whose caps put the worst
    // case at a quarter of the limit. The guard exists because those
    // caps live in a different file and nothing but this connects
    // them — and because failing inside FCM would mean an alert that
    // was accepted and then quietly never arrived.
    $device = device('android');
    $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

    // Incompressible, so it survives the gzip at roughly full size.
    $alert = alert(payload: ['blob' => bin2hex(random_bytes(6000))]);

    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->deliver($alert, $device));
    $this->assertSame([], $this->sent);
});

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
test('the refusal counts the data key as well as the value', function () {
    $device = device('android');
    $this->devices->payloadKeys[$device->id] = base64_encode(random_bytes(32));

    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

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

        $alert = alert(payload: ['blob' => substr(bin2hex(random_bytes($mid)), 0, $mid)]);

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
});

test('supports a configured mobile handset', function () {
    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertTrue($transport->supports(device('android')));
    $this->assertTrue($transport->supports(device('ios')));
});

/**
 * @return array<string, array{0: string}>
 */
dataset('unsupportedPlatforms', function (): array {
    return [
        'maccatalyst' => ['maccatalyst'],
        'windows'     => ['windows'],
    ];
});

test('desktop heads are declined and poll instead', function (string $platform) {
    // They enrol happily and simply never claim this transport.
    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->supports(device($platform)));
})->with('unsupportedPlatforms');

test('a device without push is declined', function () {
    $transport = new FcmTransport(($this->client)(), configuredSettings(), $this->devices);

    $this->assertFalse($transport->supports(device('android', Device::PUSH_NONE)));
});

test('nothing is supported until fcm is configured', function () {
    // An empty service account is a supported state, not a broken
    // one — handsets poll as well as listen.
    $transport = new FcmTransport(($this->client)(), new Settings(), $this->devices);

    $this->assertFalse($transport->supports(device('android')));
});

test('deliver declines without a service account', function () {
    $transport = new FcmTransport(($this->client)(), new Settings(), $this->devices);

    $this->assertFalse($transport->deliver(alert(), device('ios')));
    $this->assertSame([], $this->sent);
});

test('deliver reports a failed send rather than throwing', function () {
    // The alert is already stored; a push that did not land must not
    // stop the dispatcher reaching the other handsets in the list.
    $transport = new FcmTransport(($this->client)(500), configuredSettings(), $this->devices);

    $this->assertFalse($transport->deliver(alert(), device('ios')));
});

test('the message carries no top level notification block', function () {
    // The single most important assertion in this file. A
    // `notification` key here means Android's system tray handles the
    // message while Hand is backgrounded, and Hand never gets to
    // raise its full-screen intent or start the looping alarm.
    $message = ($this->deliver)(alert(), device('ios'));

    $this->assertArrayNotHasKey('notification', $message);
    $this->assertArrayHasKey('data', $message);
});

test('android gets high priority and the alerts own ttl', function () {
    // Without `priority: high` the message is held for a maintenance
    // window and can arrive an hour late. The TTL matches the alert's
    // expiry — there is no value in FCM retrying an alert that has
    // already gone stale.
    $message = ($this->deliver)(alert(), device('ios'));

    $this->assertSame('high', $message['android']['priority']);
    $this->assertMatchesRegularExpression('/^\d+s$/', $message['android']['ttl']);
    $this->assertLessThanOrEqual(900, (int) rtrim($message['android']['ttl'], 's'));
});

test('an expired alert still gets a positive ttl', function () {
    // FCM rejects a ttl of 0s outright, which would turn a late
    // delivery into no delivery at all.
    $stale = new Alert(1, 'k', 'reach', 'normal', 't', '', '', [], '', 1_000, time() - 3_600);

    $message = ($this->deliver)($stale, device('ios'));

    $this->assertSame('1s', $message['android']['ttl']);
});

test('the device token addresses the message', function () {
    $message = ($this->deliver)(alert(), device('ios'));

    $this->assertSame('device-token', $message['token']);
});

test('the data block is entirely strings', function () {
    // FCM's data block is a string→string map and silently rejects
    // anything else — the worst failure mode available here.
    $device = device('ios');
    $key = ($this->keyFor)($device);

    // One delivery, read two ways — deliver() asserts a single message
    // went, so a second call here would fail on that rather than on
    // anything this test is about.
    $message = ($this->deliver)(alert(payload: ['area' => 'BS5']), $device);

    foreach ($message['data'] as $name => $value) {
        $this->assertIsString($name);
        $this->assertIsString($value, "data.{$name} must be a string");
    }

    // And inside the envelope, where the same rule applies for the
    // same reason: it is rebuilt into a data map on the handset.
    foreach (($this->open)(($this->sealedFor)($message), $key) as $name => $value) {
        $this->assertIsString($name);
        $this->assertIsString($value, "sealed.{$name} must be a string");
    }
});

test('the data block describes the alert and the channel', function () {
    $data = ($this->opened)(alert(), device('ios'));

    $this->assertSame('12', $data['alert_id']);
    $this->assertSame('call_request', $data['kind']);
    $this->assertSame('CR-000123', $data['reference']);
    $this->assertSame('yellow', $data['level']);
    $this->assertSame('first', $data['response']);
    // Must match a channel Hand creates, or the alert lands on the
    // default channel with the default sound and none of the alarm
    // behaviour.
    $this->assertSame(FcmTransport::ANDROID_CHANNEL_WARNING, $data['channel']);
    $this->assertSame('reach_alert', $data['sound']);
});

/**
 * Three levels, three channels. An Android channel's importance and
 * sound are fixed at creation, so they cannot be one channel
 * reconfigured — see FcmTransport::ANDROID_CHANNEL.
 */
test('the channel follows the level', function (string $level, string $channel) {
    $data = ($this->opened)(alert($level), device('ios'));

    $this->assertSame($channel, $data['channel']);
})->with('levelChannels');

/** @return array<string, array{0: string, 1: string}> */
dataset('levelChannels', function (): array {
    return [
        'red'    => [Alert::LEVEL_RED, FcmTransport::ANDROID_CHANNEL],
        'yellow' => [Alert::LEVEL_YELLOW, FcmTransport::ANDROID_CHANNEL_WARNING],
        'blue'   => [Alert::LEVEL_BLUE, FcmTransport::ANDROID_CHANNEL_NOTICE],
    ];
});

/**
 * An older Hand build reads `priority` and ignores every field it has
 * never heard of, so a red alert still has to reach it as urgent. See
 * Alert::PRIORITY_NORMAL.
 */
test('the derived priority travels for handsets that predate the level', function (
    string $level,
    string $priority
) {
    $data = ($this->opened)(alert($level), device('ios'));

    $this->assertSame($priority, $data['priority']);
})->with('levelPriorities');

/** @return array<string, array{0: string, 1: string}> */
dataset('levelPriorities', function (): array {
    return [
        'red'    => [Alert::LEVEL_RED, 'urgent'],
        'yellow' => [Alert::LEVEL_YELLOW, 'normal'],
        'blue'   => [Alert::LEVEL_BLUE, 'normal'],
    ];
});

test('a blue alert asks ios for nothing and uses the system sound', function () {
    // Sending the helpline siren at a passive interruption level would
    // be a handset making an emergency noise for a reminder.
    $aps = ($this->deliver)(alert(Alert::LEVEL_BLUE), device('ios'))
        ['apns']['payload']['aps'];

    $this->assertSame('default', $aps['sound']);
    $this->assertSame('passive', $aps['interruption-level']);
});

test('a plugins payload cannot override what the alert is', function () {
    // The payload is merged first precisely so the alert's own fields
    // win a name collision.
    $data = ($this->opened)(
        alert(payload: ['kind' => 'spoofed', 'channel' => 'other', 'area' => 'BS5']),
        device('ios'),
    );

    $this->assertSame('call_request', $data['kind']);
    $this->assertSame(FcmTransport::ANDROID_CHANNEL_WARNING, $data['channel']);
    // Extras that do not collide still travel.
    $this->assertSame('BS5', $data['area']);
});

test('apns headers ask for immediate delivery until the alert expires', function () {
    $alert = alert();

    $headers = ($this->deliver)($alert, device('ios'))['apns']['headers'];

    $this->assertSame('10', $headers['apns-priority']);
    $this->assertSame('alert', $headers['apns-push-type']);
    $this->assertSame((string) $alert->expiresAt, $headers['apns-expiration']);
});

test('the apns payload names the sound because nothing else can', function () {
    $aps = ($this->deliver)(alert(), device('ios'))['apns']['payload']['aps'];

    $this->assertSame('reach_alert.wav', $aps['sound']['name']);
    $this->assertSame(1, $aps['sound']['volume']);
    // Not the alert's own words: those are sealed, and the extension
    // writes them in before the lock screen is drawn. What is here is
    // what a responder sees if that never happens.
    $this->assertSame('Reach alert', $aps['alert']['title']);
    $this->assertSame('Open Hand for the details.', $aps['alert']['body']);
    // Wakes a merely-backgrounded app so it can start the looping
    // alarm the 30-second payload sound cannot provide alone.
    $this->assertSame(1, $aps['content-available']);
    // Launches the extension that opens the payload.
    $this->assertSame(1, $aps['mutable-content']);
    $this->assertSame('REACH_ALERT', $aps['category']);
});

test('the sealed blob travels in the apns payload and nothing else does', function () {
    // iOS hands the app everything except `aps` when it is opened from
    // a notification, so the payload has to appear beside the aps
    // dictionary as well as in the data block — and the extension
    // reads it from exactly there.
    //
    // This assertion used to say the opposite: that a full plaintext
    // copy of the alert was repeated under a `reach` key. That copy
    // was the last readable path, and removing it is the point of the
    // change this test now guards.
    $message = ($this->deliver)(alert(), device('ios'));
    $payload = $message['apns']['payload'];

    $this->assertSame(['aps', 'ciphertext'], array_keys($payload));
    $this->assertSame($message['data']['ciphertext'], $payload['ciphertext']);
    $this->assertArrayNotHasKey('reach', $payload, 'the plaintext copy must not come back');
});

test('critical is off by default even for an urgent alert', function () {
    // Sending the critical flag without Apple's entitlement gets the
    // notification rejected rather than downgraded — it would silence
    // the very alerts it is meant to make louder.
    $aps = ($this->deliver)(alert(Alert::LEVEL_RED), device('ios'))['apns']['payload']['aps'];

    $this->assertSame(0, $aps['sound']['critical']);
    // time-sensitive still breaks through a Focus mode, which is most
    // of the benefit for none of the paperwork.
    $this->assertSame('time-sensitive', $aps['interruption-level']);
});

test('an urgent alert goes critical once the entitlement is configured', function () {
    $settings = configuredSettings();
    $settings->setApnsCriticalEnabled(true);

    $aps = ($this->deliver)(
        alert(Alert::LEVEL_RED),
        device('ios'),
        $settings,
    )['apns']['payload']['aps'];

    $this->assertSame(1, $aps['sound']['critical']);
    $this->assertSame('critical', $aps['interruption-level']);
});

test('an ordinary alert is never critical even with the entitlement', function () {
    // Critical overrides the ringer switch and Do Not Disturb. That is
    // for an urgent alert, not for every callback request.
    $settings = configuredSettings();
    $settings->setApnsCriticalEnabled(true);

    $aps = ($this->deliver)(
        alert(Alert::LEVEL_YELLOW),
        device('ios'),
        $settings,
    )['apns']['payload']['aps'];

    $this->assertSame(0, $aps['sound']['critical']);
    $this->assertSame('time-sensitive', $aps['interruption-level']);
});

test('a settings change takes effect without a restart', function () {
    // The account is parsed on each call rather than cached, so an
    // admin pasting a key file does not have to wait for anything.
    $settings = new Settings();
    $transport = new FcmTransport(($this->client)(), $settings, $this->devices);
    $this->assertFalse($transport->supports(device()));

    $settings->setFcmServiceAccount(accountJson());

    $this->assertTrue($transport->supports(device()));
});
