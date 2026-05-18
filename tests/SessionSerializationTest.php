<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Session\Session;

/**
 * Session is in the cookie wire format. Anything that breaks the
 * round-trip is a production incident — sessions sitting in users'
 * browsers were minted by the *previous* version of this class and
 * must still deserialise after a deploy. The tests pin:
 *
 *  - new field round-trips when set,
 *  - old-shape cookies (no `pem` field) still deserialise,
 *  - the `pem` key is omitted when not set, so the wire format
 *    doesn't gain weight for the common case.
 */
final class SessionSerializationTest extends TestCase
{
    public function testProviderEmailRoundTripsThroughArray(): void
    {
        $session = new Session(
            'real@example.com',
            'facebook',
            'fb-sub-1',
            1000,
            5000,
            'hash@privaterelay.facebook.com',
        );

        $payload = $session->toArray();
        $this->assertSame('hash@privaterelay.facebook.com', $payload['pem'] ?? null);

        $restored = Session::fromArray($payload);
        $this->assertNotNull($restored);
        $this->assertSame('real@example.com', $restored->email);
        $this->assertSame('hash@privaterelay.facebook.com', $restored->providerEmail);
    }

    public function testProviderEmailOmittedWhenUnset(): void
    {
        // The common case — Google/Microsoft/Apple sign-ins. We don't
        // want to bloat every cookie with a `"pem":null` field for the
        // 99% case.
        $session = new Session('real@example.com', 'google', 'g-sub-1', 1000, 5000);
        $payload = $session->toArray();
        $this->assertArrayNotHasKey('pem', $payload);
    }

    public function testLegacyCookieWithoutPemFieldDeserialises(): void
    {
        // The exact shape that sessions issued before this change
        // carry. The new code must accept it unchanged — otherwise
        // every user is signed out at deploy time.
        $legacy = [
            'email'    => 'real@example.com',
            'provider' => 'google',
            'sub'      => 'g-sub-1',
            'iat'      => 1000,
            'exp'      => 5000,
        ];

        $session = Session::fromArray($legacy);
        $this->assertNotNull($session);
        $this->assertSame('real@example.com', $session->email);
        $this->assertNull($session->providerEmail);
    }

    public function testEmptyStringPemIsTreatedAsNull(): void
    {
        // Defensive — a future writer might emit "" instead of
        // omitting the field. We don't want a session to claim its
        // providerEmail is empty-string; that's worse than null
        // because downstream code might branch on isset().
        $session = Session::fromArray([
            'email'    => 'a@b.com',
            'provider' => 'facebook',
            'sub'      => 'x',
            'iat'      => 1,
            'exp'      => 2,
            'pem'      => '',
        ]);
        $this->assertNotNull($session);
        $this->assertNull($session->providerEmail);
    }
}
