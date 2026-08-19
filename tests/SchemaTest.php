<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Core\Schema;

// Reuse the shared wpdb stub (and the `wpdb` class alias) from the
// call-attempts repository test, so every test in this suite agrees on
// what `wpdb` resolves to regardless of file load order.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * Tables have to exist on sites that *update* Reach, not only on sites
 * that activate it.
 *
 * The activation hook was the only thing creating them, and WordPress
 * does not fire it when a plugin is updated in place - which is how these
 * sites take releases. A version that added a table therefore shipped
 * code expecting a table nobody had created, and the symptom was ugly:
 * enrolment appeared to succeed and handed back a token for a row that
 * did not exist.
 */
final class SchemaTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WpState::$options = [];

        // ensureInstalled() reaches for the global $wpdb and runs each
        // installer. The SQL they emit is pinned by the repository tests;
        // what matters here is only whether they are reached at all.
        // dbDelta is already a no-op from the shared bootstrap stubs.
        $GLOBALS['wpdb'] = new WpdbStub();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    public function testInstallsWhenNoVersionHasEverBeenRecorded(): void
    {
        Schema::ensureInstalled();

        $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
    }

    public function testInstallsWhenTheStoredVersionIsBehind(): void
    {
        // The case that matters: a site that has had Reach for a while and
        // has just taken a release which added a table.
        update_option(Schema::OPTION, Schema::VERSION - 1, true);

        Schema::ensureInstalled();

        $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
    }

    public function testDoesNothingWhenAlreadyCurrent(): void
    {
        update_option(Schema::OPTION, Schema::VERSION, true);

        Schema::ensureInstalled();

        $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
    }

    public function testAheadIsLeftAlone(): void
    {
        // A downgrade must not drag the recorded version backwards: the
        // tables on disk are still the newer shape, and claiming otherwise
        // would make the next upgrade skip its dbDelta.
        update_option(Schema::OPTION, Schema::VERSION + 5, true);

        Schema::ensureInstalled();

        $this->assertSame(Schema::VERSION + 5, (int) get_option(Schema::OPTION, 0));
    }

    public function testMarkInstalledRecordsTheCurrentVersion(): void
    {
        Schema::markInstalled();

        $this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION, 0));
    }

    public function testVersionIsAheadOfTheHandTablesRelease(): void
    {
        // A guard rail rather than a behaviour: the Hand tables were
        // schema 2 and target_device_id was 3. If a table or column is
        // added without bumping VERSION, the change reaches new installs
        // and silently skips every existing one - which is exactly the
        // bug this class exists to prevent, reintroduced.
        $this->assertGreaterThanOrEqual(3, Schema::VERSION);
    }
}
