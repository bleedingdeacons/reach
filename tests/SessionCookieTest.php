<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Session\Session;
use Reach\Session\SessionCookie;

/**
 * Round-trip a session through sign(), then tamper with bits of the
 * resulting token to confirm every kind of tampering is rejected.
 *
 * `read()` itself touches setcookie() and $_COOKIE, which is awkward
 * in a unit context; we exercise the signature path directly here.
 * The actual cookie I/O is thin enough to be left to integration.
 */
final class SessionCookieTest extends TestCase
{
    public function testSignedTokenVerifies(): void
    {
        $cookie = new SessionCookie();
        $session = new Session('a@example.com', 'google', 'sub-123', time(), time() + 3600);
        $token = $cookie->sign($session);

        // Format: <payload>.<sig>
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token);

        // Re-read through the cookie jar.
        $_COOKIE[SessionCookie::COOKIE_NAME] = $token;
        $read = $cookie->read();

        $this->assertNotNull($read);
        $this->assertSame('a@example.com', $read->email);
        $this->assertSame('google', $read->provider);
        $this->assertSame('sub-123', $read->sub);
    }

    public function testTamperedPayloadRejected(): void
    {
        $cookie = new SessionCookie();
        $session = new Session('a@example.com', 'google', 'sub-123', time(), time() + 3600);
        $token = $cookie->sign($session);

        [$payload, $sig] = explode('.', $token);
        // Flip the first character of the payload.
        $tampered = ($payload[0] === 'A' ? 'B' : 'A') . substr($payload, 1) . '.' . $sig;

        $_COOKIE[SessionCookie::COOKIE_NAME] = $tampered;
        $this->assertNull($cookie->read());
    }

    public function testTamperedSignatureRejected(): void
    {
        $cookie = new SessionCookie();
        $session = new Session('a@example.com', 'google', 'sub-123', time(), time() + 3600);
        $token = $cookie->sign($session);

        [$payload, $sig] = explode('.', $token);
        $tampered = $payload . '.' . ($sig[0] === 'A' ? 'B' : 'A') . substr($sig, 1);

        $_COOKIE[SessionCookie::COOKIE_NAME] = $tampered;
        $this->assertNull($cookie->read());
    }

    public function testExpiredSessionRejected(): void
    {
        $cookie = new SessionCookie();
        $session = new Session('a@example.com', 'google', 'sub-123', time() - 7200, time() - 3600);
        $token = $cookie->sign($session);

        $_COOKIE[SessionCookie::COOKIE_NAME] = $token;
        $this->assertNull($cookie->read());
    }

    public function testMalformedCookieRejected(): void
    {
        $cookie = new SessionCookie();
        foreach (['', 'no-dot', 'a.b.c', 'a.', '.b'] as $bad) {
            $_COOKIE[SessionCookie::COOKIE_NAME] = $bad;
            $this->assertNull($cookie->read(), 'Should reject: ' . $bad);
        }
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[SessionCookie::COOKIE_NAME]);
    }
}
