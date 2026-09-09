<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\JwtVerifier;

/**
 * The floor under {@see JwtVerifier}'s cache-busting JWKS refetch.
 *
 * An unknown `kid` makes verify() retry with forceRefresh, which deletes the
 * cached key set and fetches it again. That path is reachable by anyone:
 * /oauth/apple accepts any id_token, and reading its header costs nothing —
 * so a token carrying a random kid used to drop Apple's keys from cache and
 * force an outbound HTTPS call on *every* request. Held cold from outside,
 * that puts a 5-second-timeout fetch on the critical path of every genuine
 * Apple sign-in and points an unauthenticated amplifier at the provider.
 *
 * Deliberately built without openssl_pkey_new(). These assertions are about
 * how many times the key set is fetched, not about signatures, and the RSA
 * fixtures the other verifier tests generate are skipped on any PHP build
 * without an openssl.cnf — which would have taken this cover with them on
 * exactly the machines most likely to run it.
 */
final class JwksRefreshFloorTest extends ReachTestCase
{
    private string $jwksUrl = 'https://example.test/jwks.json';

    /** @var int Number of times the key set has been fetched. */
    private int $fetches = 0;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        $this->fetches = 0;

        // A well-formed key set that never contains the kid under test, so
        // every lookup here is a miss and takes the refresh path.
        $body = (string) json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => 'a-kid-that-is-never-asked-for',
                'n'   => 'AQAB',
                'e'   => 'AQAB',
            ]],
        ]);

        $url = $this->jwksUrl;
        $this->stubHttp(function (string $requested) use ($body, $url) {
            if ($requested !== $url) {
                return new \WP_Error('no_stub', 'No stub for ' . $requested);
            }

            $this->fetches++;

            return ['response' => ['code' => 200], 'body' => $body];
        });
    }

    public function testAnUnknownKidDoesNotRefetchTheKeySetOnEveryRequest(): void
    {
        $verifier = new JwtVerifier();

        // Twenty tokens, each with a kid nobody has ever published.
        for ($i = 0; $i < 20; $i++) {
            $verifier->verify(
                $this->tokenWithKid('bogus-kid-' . $i),
                $this->jwksUrl,
                'https://issuer.test',
                'client-abc'
            );
        }

        // One fetch to populate the cache, and at most one cache-busting
        // refetch within the floor. Before the floor existed this was two per
        // request — a miss, then a forced refetch — so 40.
        $this->assertLessThanOrEqual(
            2,
            $this->fetches,
            'An unknown kid must not be able to force a refetch per request.'
        );
    }

    public function testTheFirstUnknownKidStillGetsOneRefetch(): void
    {
        // The floor throttles the refetch; it must not remove it. A genuine
        // key rotation is exactly this case — a kid that is not in the cached
        // set and *is* in the published one — so the first miss has to reach
        // the provider.
        $verifier = new JwtVerifier();
        $verifier->verify(
            $this->tokenWithKid('rotated-in'),
            $this->jwksUrl,
            'https://issuer.test',
            'client-abc'
        );

        $this->assertSame(2, $this->fetches, 'Expected the initial fetch plus one forced refresh.');
    }

    public function testTheFloorIsPerKeySetNotGlobal(): void
    {
        // One provider's misses must not deny another provider its refetch:
        // the floor is keyed on the JWKS URL.
        $other = 'https://other.test/jwks.json';
        $fetched = [];

        $this->stubHttp(static function (string $url) use (&$fetched) {
            $fetched[] = $url;

            return [
                'response' => ['code' => 200],
                'body'     => (string) json_encode(['keys' => []]),
            ];
        });

        $verifier = new JwtVerifier();
        $verifier->verify($this->tokenWithKid('x'), $this->jwksUrl, 'https://issuer.test', 'client-abc');
        $verifier->verify($this->tokenWithKid('x'), $other, 'https://issuer.test', 'client-abc');

        $this->assertContains($this->jwksUrl, $fetched);
        $this->assertContains($other, $fetched, 'A second key set has its own floor.');
    }

    /**
     * A structurally valid RS256 token with the given kid.
     *
     * The signature is nonsense, which is fine: the kid lookup happens before
     * any signature check, and that lookup is what these tests are about.
     */
    private function tokenWithKid(string $kid): string
    {
        $header  = self::b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
        $payload = self::b64url((string) json_encode([
            'iss' => 'https://issuer.test',
            'aud' => 'client-abc',
            'iat' => time(),
            'exp' => time() + 3600,
        ]));

        return $header . '.' . $payload . '.' . self::b64url('not-a-real-signature');
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
