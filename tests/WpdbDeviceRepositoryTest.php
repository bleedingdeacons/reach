<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Devices\Device;
use Reach\Devices\WpdbDeviceRepository;
use Reach\Tests\ReachTestCase;

// Shared wpdb stub and the `wpdb` class alias. See the note in
// WpdbAlertRepositoryTest.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * The devices table is what a bearer token authenticates against, so the
 * assertions that matter most here are the negative ones: a revoked row
 * must never come back from a lookup, because "revoked" and "unknown"
 * have to be indistinguishable at every call site. Making that a
 * property of the query rather than a check the caller remembers to make
 * is the design, and it is what these tests pin down.
 */
final class WpdbDeviceRepositoryTest extends ReachTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id'            => 7,
            'token_hash'    => str_repeat('a', 64),
            'member_email'  => 'jo@example.com',
            'member_id'     => 42,
            'label'         => 'Duty handset',
            'platform'      => 'android',
            'push_provider' => 'fcm',
            'push_token'    => 'fcm-token',
            'created_at'    => 1_700_000_000,
            'last_seen_at'  => 1_700_000_500,
            'revoked_at'    => null,
        ];
    }

    public function testInstallCreatesTheTableWithTheThreeIndexesItActuallyUses(): void
    {
        $GLOBALS['__reach_dbdelta'] = [];
        $db = new WpdbStub();

        WpdbDeviceRepository::install($db);

        $sql = $GLOBALS['__reach_dbdelta'][0];
        $this->assertStringContainsString('CREATE TABLE wp_reach_devices', $sql);
        // Authenticate a bearer token …
        $this->assertStringContainsString('UNIQUE KEY token_hash (token_hash)', $sql);
        // … find one responder's handsets …
        $this->assertStringContainsString('KEY member_email (member_email)', $sql);
        // … and list every live handset for a broadcast.
        $this->assertStringContainsString('KEY revoked_at (revoked_at)', $sql);
    }

    public function testTokenHashIsFixedWidthAndPushTokenIsGenerous(): void
    {
        // The hash is always a hex SHA-256, so CHAR(64) keeps the unique
        // index compact. FCM registration tokens have no documented
        // maximum and have grown twice — a truncated one is a handset
        // that silently never rings.
        $GLOBALS['__reach_dbdelta'] = [];
        WpdbDeviceRepository::install(new WpdbStub());

        $sql = $GLOBALS['__reach_dbdelta'][0];
        $this->assertStringContainsString('token_hash CHAR(64) NOT NULL', $sql);
        $this->assertStringContainsString('push_token VARCHAR(512)', $sql);
    }

    public function testTableNameUsesThePrefix(): void
    {
        $db = new WpdbStub();
        $db->prefix = 'blog7_';

        $this->assertSame('blog7_reach_devices', WpdbDeviceRepository::tableName($db));
    }

    public function testCreateStoresTheHashAndReturnsTheEnrolledDevice(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $device = $repo->create(
            str_repeat('b', 64),
            'jo@example.com',
            42,
            'Duty handset',
            'android',
            'fcm',
            'fcm-token',
            1_700_000_000,
        );

        $this->assertCount(1, $db->inserted);
        $this->assertSame('wp_reach_devices', $db->inserted[0]['table']);
        $data = $db->inserted[0]['data'];
        $this->assertSame(str_repeat('b', 64), $data['token_hash']);
        // A new handset counts as seen at enrolment, not never.
        $this->assertSame(1_700_000_000, $data['last_seen_at']);
        $this->assertArrayNotHasKey('revoked_at', $data);

        $this->assertSame(1, $device->id);
        $this->assertSame('jo@example.com', $device->memberEmail);
        $this->assertFalse($device->isRevoked());
        $this->assertTrue($device->wantsPush());
    }

    public function testCreateNeverStoresTheBearerTokenItself(): void
    {
        // Only the hash is kept, so a database dump cannot be replayed as
        // a set of live handset credentials.
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $repo->create(hash('sha256', 'the-secret-token'), 'jo@example.com', 42, '', 'ios', '', '', 1_000);

        $this->assertStringNotContainsString(
            'the-secret-token',
            (string) wp_json_encode($db->inserted[0]['data']),
        );
    }

    public function testFindByTokenHashRefusesRevokedRowsInTheQueryItself(): void
    {
        // A revoked token must be indistinguishable from an unknown one
        // at every call site, and the surest way to guarantee that is to
        // never return the row.
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $repo->findByTokenHash(str_repeat('a', 64));

        $q = $db->queries[0];
        $this->assertStringContainsString("WHERE token_hash = '" . str_repeat('a', 64) . "'", $q);
        $this->assertStringContainsString('AND revoked_at IS NULL', $q);
    }

    public function testFindByTokenHashHydratesALiveRow(): void
    {
        $db = new WpdbStub();
        $db->nextRow = $this->row();
        $repo = new WpdbDeviceRepository($db);

        $device = $repo->findByTokenHash(str_repeat('a', 64));

        $this->assertNotNull($device);
        $this->assertSame(7, $device->id);
        $this->assertSame(42, $device->memberId);
        $this->assertSame('Duty handset', $device->label);
        $this->assertSame('android', $device->platform);
        $this->assertSame(1_700_000_500, $device->lastSeenAt);
        $this->assertNull($device->revokedAt);
        $this->assertFalse($device->isRevoked());
    }

    public function testFindByTokenHashReturnsNullOnMiss(): void
    {
        $db = new WpdbStub();
        $db->nextRow = null;

        $this->assertNull((new WpdbDeviceRepository($db))->findByTokenHash('nope'));
    }

    public function testFindByIdReturnsARowRegardlessOfRevocation(): void
    {
        // Unlike the token lookup: the admin page needs to show a revoked
        // handset and when it was cut off.
        $db = new WpdbStub();
        $db->nextRow = $this->row(['revoked_at' => 1_700_009_000]);
        $repo = new WpdbDeviceRepository($db);

        $device = $repo->findById(7);

        $this->assertNotNull($device);
        $this->assertTrue($device->isRevoked());
        $this->assertSame(1_700_009_000, $device->revokedAt);
        $this->assertStringNotContainsString('revoked_at IS NULL', $db->queries[0]);
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        $db = new WpdbStub();
        $db->nextRow = null;

        $this->assertNull((new WpdbDeviceRepository($db))->findById(99));
    }

    public function testFindByMemberEmailListsOnlyLiveHandsets(): void
    {
        $db = new WpdbStub();
        $db->nextResults = [$this->row(), $this->row(['id' => 8])];
        $repo = new WpdbDeviceRepository($db);

        $devices = $repo->findByMemberEmail('jo@example.com');

        $this->assertCount(2, $devices);
        $q = $db->queries[0];
        $this->assertStringContainsString("WHERE member_email = 'jo@example.com'", $q);
        $this->assertStringContainsString('AND revoked_at IS NULL', $q);
        $this->assertStringContainsString('ORDER BY id ASC', $q);
    }

    public function testFindAllLiveIsTheBroadcastList(): void
    {
        $db = new WpdbStub();
        $db->nextResults = [$this->row()];
        $repo = new WpdbDeviceRepository($db);

        $this->assertCount(1, $repo->findAllLive());
        $this->assertStringContainsString('WHERE revoked_at IS NULL', $db->queries[0]);
    }

    public function testHydrationSurvivesANonArrayResult(): void
    {
        // $wpdb answers null on a failed query rather than raising.
        $db = new WpdbStub();
        $db->nextResults = [];

        $this->assertSame([], (new WpdbDeviceRepository($db))->findAllLive());
    }

    public function testListPutsLiveHandsetsFirstThenNewest(): void
    {
        // The admin page should open on what is currently enrolled rather
        // than on a wall of history. id DESC stabilises pagination when
        // rows share a timestamp.
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $repo->list(50, 0);

        $this->assertStringContainsString(
            'ORDER BY (revoked_at IS NULL) DESC, created_at DESC, id DESC',
            $db->queries[0],
        );
    }

    public function testListClampsLimitAndOffset(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $repo->list(99_999, -5);

        $this->assertStringContainsString('LIMIT 500 OFFSET 0', $db->queries[0]);
    }

    public function testCountAllReturnsTheVar(): void
    {
        $db = new WpdbStub();
        $db->nextVar = 9;

        $this->assertSame(9, (new WpdbDeviceRepository($db))->countAll());
        $this->assertStringContainsString('SELECT COUNT(*) FROM wp_reach_devices', $db->queries[0]);
    }

    public function testTouchUpdatesOnlyTheLastSeenStamp(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $this->assertTrue($repo->touch(7, 1_700_000_900));

        $this->assertCount(1, $db->updated);
        $this->assertSame('wp_reach_devices', $db->updated[0]['table']);
        $this->assertSame(['last_seen_at' => 1_700_000_900], $db->updated[0]['data']);
        $this->assertSame(['id' => 7], $db->updated[0]['where']);
    }

    public function testUpdatePushTokenWritesBothHalvesTogether(): void
    {
        // Provider and token are one fact — wantsPush() needs both — so
        // they must never be written apart.
        $db = new WpdbStub();
        $repo = new WpdbDeviceRepository($db);

        $this->assertTrue($repo->updatePushToken(7, 'fcm', 'new-token'));

        $this->assertSame(
            ['push_provider' => 'fcm', 'push_token' => 'new-token'],
            $db->updated[0]['data'],
        );
        $this->assertSame(['id' => 7], $db->updated[0]['where']);
    }

    public function testRevokeTouchesOnlyAStillLiveRow(): void
    {
        // Idempotent, and it preserves the moment a handset was actually
        // cut off rather than the moment someone clicked twice.
        $db = new WpdbStub();
        $db->nextQueryResult = 1;
        $repo = new WpdbDeviceRepository($db);

        $this->assertTrue($repo->revoke(7, 1_700_009_000));

        $this->assertStringContainsString(
            'UPDATE wp_reach_devices SET revoked_at = 1700009000 WHERE id = 7 AND revoked_at IS NULL',
            $db->queries[0],
        );
    }

    public function testRevokingAnAlreadyRevokedHandsetReportsNoChange(): void
    {
        $db = new WpdbStub();
        $db->nextQueryResult = 0;

        $this->assertFalse((new WpdbDeviceRepository($db))->revoke(7, 1_000));
    }

    public function testRevokeAllForMemberCutsOffEveryLiveHandset(): void
    {
        // What runs when a responder stops being eligible, so it has to
        // reach every handset in one statement rather than one at a time.
        $db = new WpdbStub();
        $db->nextQueryResult = 3;
        $repo = new WpdbDeviceRepository($db);

        $this->assertSame(3, $repo->revokeAllForMember('jo@example.com', 1_700_009_000));

        $q = $db->queries[0];
        $this->assertStringContainsString('SET revoked_at = 1700009000', $q);
        $this->assertStringContainsString("WHERE member_email = 'jo@example.com'", $q);
        $this->assertStringContainsString('AND revoked_at IS NULL', $q);
    }

    public function testRevokeAllForMemberReportsZeroWhenTheUpdateFails(): void
    {
        $db = new WpdbStub();
        $db->nextQueryResult = false;

        $this->assertSame(0, (new WpdbDeviceRepository($db))->revokeAllForMember('jo@example.com', 1_000));
    }

    public function testAnEnrolledHandsetWithoutATokenYetFallsBackToPolling(): void
    {
        // The app enrols before Firebase hands a token over, and that gap
        // must not produce a push to nowhere.
        $db = new WpdbStub();
        $db->nextRow = $this->row(['push_provider' => 'fcm', 'push_token' => '']);

        $device = (new WpdbDeviceRepository($db))->findById(7);

        $this->assertNotNull($device);
        $this->assertFalse($device->wantsPush());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function platforms(): array
    {
        return [
            'android'          => ['android', 'android'],
            'ios'              => ['ios', 'ios'],
            'maccatalyst'      => ['maccatalyst', 'maccatalyst'],
            'windows'          => ['windows', 'windows'],
            'cased and padded' => ['  Android  ', 'android'],
            'unrecognised'     => ['blackberry', ''],
            'empty'            => ['', ''],
        ];
    }

    /**
     * @dataProvider platforms
     */
    public function testPlatformNormalisation(string $claimed, string $expected): void
    {
        // '' is a bad request to every caller: the platform decides the
        // delivery path, so guessing would silently enrol a handset that
        // never receives anything.
        $this->assertSame($expected, Device::normalisePlatform($claimed));
    }
}
