<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\WpdbAlertContactRepository;
use Reach\Tests\ReachTestCase;

// Shared wpdb stub and the `wpdb` class alias. See the note in
// WpdbAlertRepositoryTest.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * The contact is the one field in the alerts feature that may hold
 * personal data, so what matters here is not the SQL shape but the
 * handling: it is encrypted before it reaches the column, it round-trips
 * only under the salt it was written with, and a failure to encrypt
 * refuses rather than storing something that reads back as "no contact
 * held".
 */
final class WpdbAlertContactRepositoryTest extends ReachTestCase
{
    public function testInstallCreatesTheTableKeyedByAlert(): void
    {
        $GLOBALS['__reach_dbdelta'] = [];
        $db = new WpdbStub();

        WpdbAlertContactRepository::install($db);

        $sql = $GLOBALS['__reach_dbdelta'];
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('CREATE TABLE wp_reach_alert_contacts', $sql[0]);
        $this->assertStringContainsString('PRIMARY KEY  (alert_id)', $sql[0]);
        // TEXT, not VARCHAR: a too-small column would truncate ciphertext
        // into something undecryptable, and would do it silently.
        $this->assertStringContainsString('contact TEXT NOT NULL', $sql[0]);
    }

    public function testTableNameUsesThePrefix(): void
    {
        $db = new WpdbStub();
        $db->prefix = 'blog7_';

        $this->assertSame('blog7_reach_alert_contacts', WpdbAlertContactRepository::tableName($db));
    }

    public function testSaveEncryptsBeforeWriting(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);

        $this->assertTrue($repo->save(5, 'Sam, 07700 900123', 1_700_000_000));

        $q = $db->queries[0];
        // REPLACE, not INSERT: re-sending an alert's contact overwrites,
        // and the primary key on alert_id makes that exact.
        $this->assertStringContainsString('REPLACE INTO wp_reach_alert_contacts', $q);
        // The plaintext must not appear anywhere in the statement.
        $this->assertStringNotContainsString('900123', $q);
        $this->assertStringNotContainsString('Sam', $q);
    }

    public function testSaveRoundTripsThroughFind(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);
        $repo->save(5, 'Sam, 07700 900123', 1_000);

        // Feed the stored ciphertext back as the column value.
        $db->nextVar = $this->storedCiphertext($db->queries[0]);

        $this->assertSame('Sam, 07700 900123', $repo->find(5));
    }

    public function testFindReturnsEmptyOnceTheSaltHasRotated(): void
    {
        // Rotating wp_salt('auth') after a suspected breach is meant to
        // make stored contacts unreadable. Tolerable here precisely
        // because an alert whose contact can no longer be read is an alert
        // that can be raised again.
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);
        $repo->save(5, 'Sam, 07700 900123', 1_000);
        $db->nextVar = $this->storedCiphertext($db->queries[0]);

        $this->salts['auth'] = 'rotated-salt-' . str_repeat('z', 48);

        $this->assertSame('', $repo->find(5));
    }

    public function testFindReturnsEmptyForTamperedCiphertext(): void
    {
        // GCM authenticates, so a flipped byte fails closed rather than
        // decrypting to garbage.
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);
        $repo->save(5, 'Sam, 07700 900123', 1_000);

        $stored = base64_decode($this->storedCiphertext($db->queries[0]), true);
        $this->assertIsString($stored);
        $stored[strlen($stored) - 1] = $stored[strlen($stored) - 1] === 'A' ? 'B' : 'A';
        $db->nextVar = base64_encode($stored);

        $this->assertSame('', $repo->find(5));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function absentColumns(): array
    {
        return [
            'no row'      => [null],
            'empty value' => [''],
            'not a string' => [0],
        ];
    }

    /**
     * @dataProvider absentColumns
     */
    public function testFindReturnsEmptyWhenNothingIsStored(mixed $stored): void
    {
        $db = new WpdbStub();
        $db->nextVar = $stored;

        $this->assertSame('', (new WpdbAlertContactRepository($db))->find(5));
        $this->assertStringContainsString('WHERE alert_id = 5 LIMIT 1', $db->queries[0]);
    }

    public function testSaveTruncatesAnOverlongContact(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);

        $repo->save(5, str_repeat('a', 900), 1_000);
        $db->nextVar = $this->storedCiphertext($db->queries[0]);

        // Truncated to the column's cap rather than refused: a clipped
        // contact still gets the responder to the caller.
        $this->assertSame(500, strlen($repo->find(5)));
    }

    public function testSaveOfABlankContactDeletesInstead(): void
    {
        // "No contact" and "an empty contact" must not be two states, or
        // clearing one would leave a row that reads as details held.
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);

        $this->assertTrue($repo->save(5, '   ', 1_000));

        $this->assertSame([], $db->queries);
        $this->assertCount(1, $db->deletes);
        $this->assertSame('wp_reach_alert_contacts', $db->deletes[0]['table']);
        $this->assertSame(['alert_id' => 5], $db->deletes[0]['where']);
    }

    public function testSaveReportsFailureWhenNothingWasWritten(): void
    {
        $db = new WpdbStub();
        $db->nextQueryResult = 0;

        $this->assertFalse((new WpdbAlertContactRepository($db))->save(5, 'Sam', 1_000));
    }

    public function testHasReportsWhetherARowExists(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);

        $db->nextVar = 1;
        $this->assertTrue($repo->has(5));
        $this->assertStringContainsString('SELECT COUNT(*) FROM wp_reach_alert_contacts', $db->queries[0]);

        $db->nextVar = 0;
        $this->assertFalse($repo->has(5));
    }

    public function testDeleteReportsWhetherARowWentAway(): void
    {
        $db = new WpdbStub();
        $repo = new WpdbAlertContactRepository($db);

        $db->nextDeleteResult = 1;
        $this->assertTrue($repo->delete(5));

        $db->nextDeleteResult = 0;
        $this->assertFalse($repo->delete(5));
    }

    public function testPurgeJoinsTheAlertsTableToFindExpiredContacts(): void
    {
        // The contacts table holds no expiry of its own — the alert owns
        // that — so the purge has to join rather than filter.
        $db = new WpdbStub();
        $db->nextQueryResult = 4;
        $repo = new WpdbAlertContactRepository($db);

        $this->assertSame(4, $repo->purgeForExpiredAlertsBefore(1_700_000_000));

        $q = $db->queries[0];
        $this->assertStringContainsString('DELETE c FROM wp_reach_alert_contacts c', $q);
        $this->assertStringContainsString('INNER JOIN wp_reach_alerts a ON a.id = c.alert_id', $q);
        $this->assertStringContainsString('WHERE a.expires_at < 1700000000', $q);
    }

    public function testPurgeReportsZeroWhenTheDeleteFails(): void
    {
        $db = new WpdbStub();
        $db->nextQueryResult = false;

        $this->assertSame(0, (new WpdbAlertContactRepository($db))->purgeForExpiredAlertsBefore(1_000));
    }

    /**
     * Pull the base64 payload back out of a recorded REPLACE statement.
     */
    private function storedCiphertext(string $sql): string
    {
        $this->assertSame(1, preg_match("/VALUES \(\d+, '([^']*)'/", $sql, $m));

        return $m[1];
    }
}
