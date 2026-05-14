<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\StateStore;

/**
 * The state store is the CSRF anchor for the whole OAuth flow.
 * Its non-negotiable behaviours: unknown state → null, valid state
 * → returns payload exactly once and then disappears.
 */
final class StateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
    }

    public function testIssueAndConsumeRoundTrip(): void
    {
        $store = new StateStore();
        $tokens = $store->issue('google', 'https://example.com/reach/find');

        $this->assertNotEmpty($tokens['state']);
        $this->assertNotEmpty($tokens['nonce']);
        $this->assertNotSame($tokens['state'], $tokens['nonce']);

        $consumed = $store->consume($tokens['state']);
        $this->assertNotNull($consumed);
        $this->assertSame('google', $consumed['provider']);
        $this->assertSame($tokens['nonce'], $consumed['nonce']);
        $this->assertSame('https://example.com/reach/find', $consumed['return_to']);
    }

    public function testConsumeIsSingleUse(): void
    {
        $store = new StateStore();
        $tokens = $store->issue('apple', '/reach/find');

        $this->assertNotNull($store->consume($tokens['state']));
        // Second call must miss — replay attack defence.
        $this->assertNull($store->consume($tokens['state']));
    }

    public function testUnknownStateRejected(): void
    {
        $store = new StateStore();
        $this->assertNull($store->consume('never-issued'));
    }

    public function testStateTokensAreUnpredictable(): void
    {
        $store = new StateStore();
        $seen = [];
        for ($i = 0; $i < 32; $i++) {
            $tokens = $store->issue('google', '/');
            $this->assertNotContains($tokens['state'], $seen, 'State token collision after ' . count($seen) . ' issues');
            $this->assertSame(32, strlen($tokens['state'])); // 16 bytes -> 32 hex chars
            $seen[] = $tokens['state'];
        }
    }
}
