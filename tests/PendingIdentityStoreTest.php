<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\PendingIdentityStore;
use Reach\Auth\VerifiedIdentity;

/**
 * The pending store sits on the same single-use-transient mechanics
 * as StateStore. The properties that matter to a consumer are: a
 * valid token gets the full identity back exactly once, the original
 * relay address is preserved as providerEmail, unknown tokens are
 * rejected, and the underlying transient key is unpredictable enough
 * to resist guessing (the user is one /reach/email POST away from a
 * real session, so we don't want an attacker brute-forcing tokens).
 */
final class PendingIdentityStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
    }

    public function testRoundTripPreservesEveryField(): void
    {
        $store = new PendingIdentityStore();
        $identity = new VerifiedIdentity(
            'hash123@privaterelay.facebook.com',
            'facebook',
            'fb-sub-789',
            'hash123@privaterelay.facebook.com',
        );

        $token = $store->issue($identity, 'https://example.com/reach/find');
        $this->assertNotEmpty($token);

        $consumed = $store->consume($token);
        $this->assertNotNull($consumed);

        $this->assertSame('hash123@privaterelay.facebook.com', $consumed['identity']->email);
        $this->assertSame('facebook', $consumed['identity']->provider);
        $this->assertSame('fb-sub-789', $consumed['identity']->sub);
        $this->assertSame('hash123@privaterelay.facebook.com', $consumed['identity']->providerEmail);
        $this->assertSame('https://example.com/reach/find', $consumed['return_to']);
    }

    public function testConsumeIsSingleUse(): void
    {
        $store = new PendingIdentityStore();
        $token = $store->issue(
            new VerifiedIdentity('a@b.com', 'facebook', 'sub-1', null),
            '/reach/find'
        );

        $this->assertNotNull($store->consume($token));
        // Replaying must fail — otherwise an attacker who sniffed the
        // POST body could mint a second session.
        $this->assertNull($store->consume($token));
    }

    public function testUnknownTokenRejected(): void
    {
        $store = new PendingIdentityStore();
        $this->assertNull($store->consume('never-issued'));
    }

    public function testTokensAreUnpredictable(): void
    {
        $store = new PendingIdentityStore();
        $seen = [];
        for ($i = 0; $i < 32; $i++) {
            $token = $store->issue(
                new VerifiedIdentity('a@b.com', 'facebook', 'sub-' . $i, null),
                '/'
            );
            $this->assertNotContains($token, $seen, 'Token collision after ' . count($seen) . ' issues');
            $this->assertSame(48, strlen($token)); // 24 random bytes -> 48 hex chars
            $seen[] = $token;
        }
    }

    public function testNullProviderEmailRoundTripsAsNull(): void
    {
        // Not a Facebook case in practice, but the identity model
        // allows providerEmail to be null and the store must preserve
        // that — otherwise downstream sees an empty-string provider
        // email and audit data gets noisy.
        $store = new PendingIdentityStore();
        $token = $store->issue(
            new VerifiedIdentity('a@b.com', 'facebook', 'sub-1', null),
            '/reach/find'
        );

        $consumed = $store->consume($token);
        $this->assertNotNull($consumed);
        $this->assertNull($consumed['identity']->providerEmail);
    }
}
