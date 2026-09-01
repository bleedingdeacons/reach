<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\Alert;
use Reach\Alerts\AlertRequest;
use Reach\Alerts\MessageUuid;
use Reach\Alerts\WpdbAlertRepository;
use Reach\Tests\ReachTestCase;

// Reuse the shared wpdb stub (and the `wpdb` class alias) from the
// call-attempts repository test, so every repository test in this suite
// agrees on what `wpdb` resolves to regardless of file load order.
// require_once is idempotent — PHPUnit loading the same file later is a
// no-op.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * The alerts table is the one every handset polls every few seconds, so
 * the SQL it emits is worth locking down rather than trusting to review:
 * the anti-join that hides already-acknowledged alerts, the broadcast-or-
 * mine target filter, and — most of all — the fact that the poll query
 * reads only *whether* a contact exists and never the encrypted column
 * itself. That last one is a personal-data boundary, not a preference.
 */
final class WpdbAlertRepositoryTest extends ReachTestCase
{
    private function db(): WpdbStub
    {
        return new WpdbStub();
    }

    /** @param array<string, mixed> $args */
    private function request(array $args = []): AlertRequest
    {
        $request = AlertRequest::fromArray($args + ['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    }

    public function testInstallCreatesBothTablesWithThePollIndex(): void
    {
        $GLOBALS['__reach_dbdelta'] = [];
        $db = $this->db();

        WpdbAlertRepository::install($db);

        $sql = $GLOBALS['__reach_dbdelta'];
        $this->assertCount(2, $sql);
        $this->assertStringContainsString('CREATE TABLE wp_reach_alerts', $sql[0]);
        // Covers the poll query, which is the hottest thing in the feature.
        $this->assertStringContainsString('KEY target_expiry (target_email, expires_at)', $sql[0]);
        $this->assertStringContainsString('CREATE TABLE wp_reach_alert_acks', $sql[1]);
        // One ack per (alert, device) is what lets one alert ring several
        // handsets and be answered independently by each.
        $this->assertStringContainsString('PRIMARY KEY  (alert_id, device_id)', $sql[1]);
    }

    public function testTableNamesUseThePrefix(): void
    {
        $db = $this->db();
        $db->prefix = 'blog7_';

        $this->assertSame('blog7_reach_alerts', WpdbAlertRepository::tableName($db));
        $this->assertSame('blog7_reach_alert_acks', WpdbAlertRepository::acksTableName($db));
    }

    public function testCreateInsertsTheRowAndReturnsTheStoredAlert(): void
    {
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $alert = $repo->create($this->request([
            'source'    => 'reach',
            'priority'  => 'urgent',
            'body'      => 'Male 12th-stepper wanted in BS5',
            'reference' => 'CR-000123',
            'ttl'       => 900,
        ]), 1_700_000_000);

        $this->assertCount(1, $db->inserted);
        $this->assertSame('wp_reach_alerts', $db->inserted[0]['table']);

        $data = $db->inserted[0]['data'];
        $this->assertSame('call_request', $data['kind']);
        $this->assertSame('urgent', $data['priority']);
        $this->assertSame('CR-000123', $data['reference']);
        $this->assertSame(1_700_000_000, $data['created_at']);
        $this->assertSame(1_700_000_900, $data['expires_at']);

        // The returned model must describe the row that was just written,
        // including the id the database assigned it.
        $this->assertSame(1, $alert->id);
        $this->assertSame('Callback wanted', $alert->title);
        $this->assertSame('Male 12th-stepper wanted in BS5', $alert->body);
        $this->assertTrue($alert->isUrgent());
        $this->assertTrue($alert->isBroadcast());
        $this->assertSame(1_700_000_900, $alert->expiresAt);
    }

    public function testCreateStoresNullRatherThanAnEmptyJsonObjectForNoPayload(): void
    {
        // '{}' and 'null' read back the same through decodePayload(), but
        // NULL is what the column means by "no extras" and is what an
        // index or a human reading the table expects.
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->create($this->request(), 1_000);

        $this->assertNull($db->inserted[0]['data']['payload']);
    }

    public function testCreateJsonEncodesAPayload(): void
    {
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->create($this->request(['payload' => ['area' => 'BS5', 'gender' => 'male']]), 1_000);

        $this->assertSame(
            '{"area":"BS5","gender":"male"}',
            $db->inserted[0]['data']['payload'],
        );
    }

    public function testCreateNeverWritesTheContactToTheAlertsTable(): void
    {
        // The contact is the one field that may hold personal data. It
        // belongs encrypted in its own table, never in the row whose title
        // and body reach a lock screen.
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->create($this->request(['contact' => 'Sam, 07700 900123']), 1_000);

        $data = $db->inserted[0]['data'];
        $this->assertArrayNotHasKey('contact', $data);
        $this->assertStringNotContainsString('900123', (string) wp_json_encode($data));
    }

    public function testFindByIdHydratesARow(): void
    {
        $db = $this->db();
        $db->nextRow = [
            'id' => 12, 'kind' => 'call_request', 'source' => 'reach',
            'priority' => 'urgent', 'level' => 'red', 'response' => 'first',
            'title' => 'Callback wanted',
            'body' => 'BS5', 'reference' => 'CR-000123',
            'payload' => '{"area":"BS5"}', 'target_email' => 'jo@example.com',
            'created_at' => 1_000, 'expires_at' => 2_000,
        ];
        $repo = new WpdbAlertRepository($db);

        $alert = $repo->findById(12);

        $this->assertNotNull($alert);
        $this->assertSame(12, $alert->id);
        $this->assertSame(['area' => 'BS5'], $alert->payload);
        $this->assertSame('jo@example.com', $alert->targetEmail);
        $this->assertFalse($alert->isBroadcast());
        $this->assertTrue($alert->isUrgent());
        // findById does not join the contacts table, so the flag defaults
        // off rather than reading as "no contact held".
        $this->assertFalse($alert->hasContact);
        $this->assertStringContainsString('WHERE id = 12 LIMIT 1', $db->queries[0]);
    }

    public function testARowWrittenBeforeTheLevelExistedKeepsItsUrgency(): void
    {
        // dbDelta stamps existing rows with the new column's default, so
        // the level column is empty on every row already in the table when
        // this ships. Reading those as yellow would silently demote an
        // urgent alert; the priority they *were* written with is the
        // answer. See WpdbAlertRepository::level().
        $db = $this->db();
        $db->nextRow = [
            'id' => 12, 'kind' => 'call_request', 'source' => 'reach',
            'priority' => 'urgent', 'level' => '', 'response' => 'first',
            'title' => 'Callback wanted', 'body' => 'BS5', 'reference' => 'CR-000123',
            'payload' => null, 'target_email' => '',
            'created_at' => 1_000, 'expires_at' => 2_000,
        ];

        $alert = (new WpdbAlertRepository($db))->findById(12);

        $this->assertNotNull($alert);
        $this->assertSame(Alert::LEVEL_RED, $alert->level);
        $this->assertTrue($alert->isUrgent());
    }

    public function testARowWrittenBeforeTheResponseExistedIsFirstToRespond(): void
    {
        // Every alert was first-to-respond before the column existed,
        // because that was the only behaviour there was.
        $db = $this->db();
        $db->nextRow = [
            'id' => 12, 'kind' => 'call_request', 'source' => 'reach',
            'priority' => 'normal', 'level' => '', 'response' => 'first',
            'title' => 'Callback wanted', 'body' => 'BS5', 'reference' => '',
            'payload' => null, 'target_email' => '',
            'created_at' => 1_000, 'expires_at' => 2_000,
        ];

        $alert = (new WpdbAlertRepository($db))->findById(12);

        $this->assertNotNull($alert);
        $this->assertTrue($alert->isFirstToRespond());
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        $db = $this->db();
        $db->nextRow = null;

        $this->assertNull((new WpdbAlertRepository($db))->findById(99));
    }

    public function testPendingForAntiJoinsAcknowledgementsAndFiltersOnTarget(): void
    {
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->pendingFor('jo@example.com', 7, 1_700_000_000, 20);

        $q = $db->queries[0];
        // LEFT JOIN … IS NULL, not NOT IN (SELECT …): the anti-join uses
        // the acks primary key directly.
        $this->assertStringContainsString('LEFT JOIN wp_reach_alert_acks k ON k.alert_id = a.id AND k.device_id = 7', $q);
        $this->assertStringContainsString('WHERE k.alert_id IS NULL', $q);
        $this->assertStringContainsString('a.expires_at > 1700000000', $q);
        // Broadcast alerts and this responder's own, and nothing else.
        $this->assertStringContainsString("(a.target_email = '' OR a.target_email = 'jo@example.com')", $q);
        // …or, for an alert addressed to one handset rather than to a
        // person, by device id *instead of* the address.
        $this->assertStringContainsString('(a.target_device_id > 0 AND a.target_device_id = 7)', $q);
        // Oldest first, so a handset back from a signal blackspot alarms
        // in the order things actually happened.
        $this->assertStringContainsString('ORDER BY a.id ASC', $q);
    }

    public function testInstallCarriesTheDeviceTargetColumn(): void
    {
        $GLOBALS['__reach_dbdelta'] = [];
        $db = $this->db();

        WpdbAlertRepository::install($db);

        // Defaulting to 0 is what lets dbDelta add the column to a table
        // full of existing alerts without any of them changing meaning.
        $this->assertStringContainsString(
            'target_device_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
            $GLOBALS['__reach_dbdelta'][0],
        );
    }

    public function testTheDeviceTargetIsStoredAndReadBack(): void
    {
        $db = $this->db();

        $alert = (new WpdbAlertRepository($db))->create(
            $this->request(['target_device_id' => 9]),
            1_700_000_000,
        );

        $this->assertSame(9, $db->inserted[0]['data']['target_device_id']);
        $this->assertSame(9, $alert->targetDeviceId);
        $this->assertTrue($alert->isDeviceTargeted());
        $this->assertFalse($alert->isBroadcast());
    }

    public function testPendingForLetsADeviceTargetOverrideTheAddress(): void
    {
        // The regression this test exists for: the address filter used to be
        // ANDed with the device filter, so an alert carrying a device id and
        // somebody else's address was pushed to that handset and then hidden
        // from it on the poll. An alert only the push can deliver is exactly
        // what the store-first design exists to rule out — it would vanish on
        // the pull-only heads and after any push failure.
        //
        // The dispatcher and the REST guard have always given the device id
        // precedence; this is the third place that decides, and it has to
        // agree with them.
        $db = $this->db();

        (new WpdbAlertRepository($db))->pendingFor('jo@example.com', 7, 1_700_000_000, 20);

        $q = $db->queries[0];

        // The device branch stands alone: no address predicate qualifies it.
        $this->assertStringContainsString('(a.target_device_id > 0 AND a.target_device_id = 7)', $q);
        // The address branch applies only where no handset is named.
        $this->assertStringContainsString('a.target_device_id = 0', $q);
        $this->assertStringContainsString("(a.target_email = '' OR a.target_email = 'jo@example.com')", $q);
        // …and the two are alternatives, not conditions to satisfy together.
        $this->assertStringNotContainsString(
            "AND (a.target_email = '' OR a.target_email = 'jo@example.com')\n                AND (a.target_device_id",
            $q,
        );
    }

    public function testInstallCarriesTheMessageAndExclusionColumns(): void
    {
        $GLOBALS['__reach_dbdelta'] = [];
        $db = $this->db();

        WpdbAlertRepository::install($db);

        $sql = $GLOBALS['__reach_dbdelta'][0];

        // Both default to the "nothing special" value, which is what lets
        // dbDelta add them to a table full of existing alerts without any
        // of those rows changing meaning: an empty uuid groups with
        // nothing, and an exclusion of 0 withholds from nobody.
        $this->assertStringContainsString("message_uuid CHAR(36) NOT NULL DEFAULT ''", $sql);
        $this->assertStringContainsString('exclude_device_id BIGINT UNSIGNED NOT NULL DEFAULT 0', $sql);

        // Indexed because the notifier looks a whole message up by it on
        // every acknowledgement.
        $this->assertStringContainsString('KEY message_uuid (message_uuid)', $sql);
    }

    public function testTheMessageUuidAndExclusionAreStoredAndReadBack(): void
    {
        $db = $this->db();
        $uuid = MessageUuid::generate();

        $alert = (new WpdbAlertRepository($db))->create(
            $this->request(['message_uuid' => $uuid, 'exclude_device_id' => 4]),
            1_700_000_000,
        );

        $this->assertSame($uuid, $db->inserted[0]['data']['message_uuid']);
        $this->assertSame(4, $db->inserted[0]['data']['exclude_device_id']);
        $this->assertSame($uuid, $alert->messageUuid);
        $this->assertTrue($alert->excludes(4));
        $this->assertFalse($alert->excludes(5));
    }

    public function testPendingForWithholdsAnAlertExcludedFromTheAskingHandset(): void
    {
        // The poll half of the exclusion. The push half is the dispatcher,
        // and it has to be both: an exclusion honoured on one route is an
        // alert that arrives by the other.
        //
        // Outside the target branch on purpose. A notice is broadcast, and
        // "broadcast" is exactly the shape the one handset it is withheld
        // from would otherwise match.
        $db = $this->db();

        (new WpdbAlertRepository($db))->pendingFor('jo@example.com', 7, 1_700_000_000, 20);

        $this->assertStringContainsString('a.exclude_device_id <> 7', $db->queries[0]);
    }

    public function testPendingForDropsAMessageSomebodyHasAlreadyAnswered(): void
    {
        // An answered message is over, for everybody — the poll half of a
        // responder taking a job clearing it off the rest of the rota's
        // screens. Pinned here as well as in the fake because the two have
        // drifted before: see the note on InMemoryAlertRepository.
        $db = $this->db();

        (new WpdbAlertRepository($db))->pendingFor('jo@example.com', 7, 1_700_000_000, 20);

        $q = $db->queries[0];

        // Any acknowledgement against any row of the same message counts,
        // which is why it joins back to the alerts table rather than
        // testing this row's own id.
        $this->assertStringContainsString('NOT EXISTS', $q);
        $this->assertStringContainsString('sibling.message_uuid = a.message_uuid', $q);

        // Exempt: anything informational goes to everybody and is read
        // separately, so one handset closing its own copy must not take it
        // off the others.
        $this->assertStringContainsString("a.response = 'none'", $q);

        // Exempt: every row older than the column shares the empty uuid
        // and is not one message.
        $this->assertStringContainsString("a.message_uuid = ''", $q);
    }

    public function testFindByMessageUuidSelectsTheWholeMessageOldestFirst(): void
    {
        $db = $this->db();
        $uuid = MessageUuid::generate();

        (new WpdbAlertRepository($db))->findByMessageUuid($uuid);

        $q = $db->queries[0];
        $this->assertStringContainsString("WHERE message_uuid = '" . $uuid . "'", $q);
        $this->assertStringContainsString('ORDER BY id ASC', $q);
    }

    public function testFindByMessageUuidAsksNothingForTheEmptyUuid(): void
    {
        // Rows written before the column existed all carry the empty
        // string, and they are not one message.
        $db = $this->db();

        $this->assertSame([], (new WpdbAlertRepository($db))->findByMessageUuid(''));
        $this->assertSame([], $db->queries);
    }

    public function testPendingForReadsOnlyWhetherAContactExists(): void
    {
        // Personal data must not travel on the path every handset runs
        // every few seconds. The join tests for a row; it must never
        // select the encrypted column.
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->pendingFor('jo@example.com', 7, 1_000, 20);

        $q = $db->queries[0];
        $this->assertStringContainsString('(c.alert_id IS NOT NULL) AS has_contact', $q);
        $this->assertStringContainsString('LEFT JOIN wp_reach_alert_contacts c ON c.alert_id = a.id', $q);
        $this->assertStringNotContainsString('c.contact', $q);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function pendingLimitProvider(): array
    {
        return [
            'zero clamps up'      => [0, 1],
            'negative clamps up'  => [-5, 1],
            'in range passes'     => [20, 20],
            'huge clamps down'    => [5_000, 100],
        ];
    }

    /**
     * @dataProvider pendingLimitProvider
     */
    public function testPendingForClampsTheLimit(int $asked, int $expected): void
    {
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->pendingFor('jo@example.com', 7, 1_000, $asked);

        $this->assertStringContainsString('LIMIT ' . $expected, $db->queries[0]);
    }

    public function testPendingForHydratesTheContactFlagFromTheJoin(): void
    {
        $db = $this->db();
        $db->nextResults = [
            [
                'id' => 1, 'kind' => 'call_request', 'source' => 'reach',
                'priority' => 'normal', 'title' => 'One', 'body' => '',
                'reference' => '', 'payload' => null, 'target_email' => '',
                'created_at' => 1, 'expires_at' => 9, 'has_contact' => '1',
            ],
            [
                'id' => 2, 'kind' => 'call_request', 'source' => 'reach',
                'priority' => 'normal', 'title' => 'Two', 'body' => '',
                'reference' => '', 'payload' => null, 'target_email' => '',
                'created_at' => 2, 'expires_at' => 9, 'has_contact' => '0',
            ],
        ];
        $repo = new WpdbAlertRepository($db);

        $alerts = $repo->pendingFor('jo@example.com', 7, 1_000, 20);

        $this->assertCount(2, $alerts);
        $this->assertTrue($alerts[0]->hasContact);
        $this->assertFalse($alerts[1]->hasContact);
    }

    public function testPendingForSurvivesANonArrayResult(): void
    {
        // $wpdb answers null on a failed query rather than raising, and a
        // polling handset getting an empty list beats a fatal.
        $db = $this->db();
        $db->nextResults = [null, 'nonsense'];
        $repo = new WpdbAlertRepository($db);

        $this->assertSame([], $repo->pendingFor('jo@example.com', 7, 1_000, 20));
    }

    public function testAcknowledgeInsertsIgnoringARepeat(): void
    {
        $db = $this->db();
        $db->nextQueryResult = 1;
        $repo = new WpdbAlertRepository($db);

        $this->assertTrue($repo->acknowledge(5, 7, 'jo@example.com', 1_700_000_000));

        $q = $db->queries[0];
        // INSERT IGNORE puts the idempotence at the storage layer, where
        // two handsets racing cannot overwrite the first ack's timestamp.
        $this->assertStringContainsString('INSERT IGNORE INTO wp_reach_alert_acks', $q);
        $this->assertStringContainsString("VALUES (5, 7, 'jo@example.com', 1700000000)", $q);
    }

    public function testAcknowledgeReturnsFalseWhenTheRowAlreadyExisted(): void
    {
        $db = $this->db();
        $db->nextQueryResult = 0;

        $this->assertFalse((new WpdbAlertRepository($db))->acknowledge(5, 7, 'jo@example.com', 1_000));
    }

    public function testAcknowledgementsForHydratesAndOrders(): void
    {
        $db = $this->db();
        $db->nextResults = [
            ['device_id' => '7', 'member_email' => 'jo@example.com', 'acked_at' => '1700000000'],
            'not a row',
        ];
        $repo = new WpdbAlertRepository($db);

        $acks = $repo->acknowledgementsFor(5);

        $this->assertSame(
            [['device_id' => 7, 'member_email' => 'jo@example.com', 'acked_at' => 1_700_000_000]],
            $acks,
        );
        $this->assertStringContainsString('ORDER BY acked_at ASC, device_id ASC', $db->queries[0]);
    }

    public function testListOrdersNewestFirstAndClampsPaging(): void
    {
        $db = $this->db();
        $repo = new WpdbAlertRepository($db);

        $repo->list(99_999, -5);

        $q = $db->queries[0];
        // id DESC stabilises rows sharing a timestamp, so paging the admin
        // list cannot skip or duplicate.
        $this->assertStringContainsString('ORDER BY created_at DESC, id DESC', $q);
        $this->assertStringContainsString('LIMIT 500 OFFSET 0', $q);
    }

    public function testCountAllReturnsTheVar(): void
    {
        $db = $this->db();
        $db->nextVar = 42;

        $this->assertSame(42, (new WpdbAlertRepository($db))->countAll());
        $this->assertStringContainsString('SELECT COUNT(*) FROM wp_reach_alerts', $db->queries[0]);
    }

    public function testPurgeDeletesAcknowledgementsBeforeTheAlertsTheyPointAt(): void
    {
        // There is no foreign key here — WP core tables carry none and
        // dbDelta cannot express one — so the order is the only thing
        // stopping the ack rows from being stranded against ids that no
        // longer exist.
        $db = $this->db();
        $db->nextQueryResult = 3;
        $repo = new WpdbAlertRepository($db);

        $this->assertSame(3, $repo->purgeExpiredBefore(1_700_000_000));

        $this->assertCount(2, $db->queries);
        $this->assertStringContainsString('DELETE k FROM wp_reach_alert_acks k', $db->queries[0]);
        $this->assertStringContainsString('DELETE FROM wp_reach_alerts WHERE expires_at < 1700000000', $db->queries[1]);
    }

    public function testPurgeReportsZeroWhenTheDeleteFails(): void
    {
        $db = $this->db();
        $db->nextQueryResult = false;

        $this->assertSame(0, (new WpdbAlertRepository($db))->purgeExpiredBefore(1_000));
    }

    public function testHydrateIgnoresAPayloadThatIsNotAJsonObject(): void
    {
        $db = $this->db();
        $db->nextRow = [
            'id' => 1, 'kind' => 'k', 'source' => 's', 'priority' => 'normal',
            'title' => 't', 'body' => '', 'reference' => '',
            'payload' => 'not json', 'target_email' => '',
            'created_at' => 1, 'expires_at' => 2,
        ];

        $alert = (new WpdbAlertRepository($db))->findById(1);

        $this->assertNotNull($alert);
        $this->assertSame([], $alert->payload);
    }

    public function testHydrateKeepsOnlyScalarStringKeyedPayloadEntries(): void
    {
        // The column is written by this class but read defensively: a
        // hand-edited row must not put an array or an object where the app
        // expects a string.
        $db = $this->db();
        $db->nextRow = [
            'id' => 1, 'kind' => 'k', 'source' => 's', 'priority' => 'normal',
            'title' => 't', 'body' => '', 'reference' => '',
            'payload' => '{"area":"BS5","count":3,"ratio":1.5,"nested":{"a":1},"0":"positional"}',
            'target_email' => '', 'created_at' => 1, 'expires_at' => 2,
        ];

        $alert = (new WpdbAlertRepository($db))->findById(1);

        $this->assertNotNull($alert);
        $this->assertSame(['area' => 'BS5', 'count' => '3', 'ratio' => '1.5'], $alert->payload);
    }

    public function testHydrateTreatsAnEmptyPayloadColumnAsNoExtras(): void
    {
        $db = $this->db();
        $db->nextRow = [
            'id' => 1, 'kind' => 'k', 'source' => 's', 'priority' => 'normal',
            'title' => 't', 'body' => '', 'reference' => '', 'payload' => '',
            'target_email' => '', 'created_at' => 1, 'expires_at' => 2,
        ];

        $alert = (new WpdbAlertRepository($db))->findById(1);

        $this->assertNotNull($alert);
        $this->assertSame([], $alert->payload);
    }

    public function testAlertExpiryIsInclusiveOfTheExpiryInstant(): void
    {
        $alert = new Alert(1, 'k', 's', 'normal', 't', '', '', [], '', 1_000, 2_000);

        $this->assertFalse($alert->isExpired(1_999));
        $this->assertTrue($alert->isExpired(2_000));
    }
}
