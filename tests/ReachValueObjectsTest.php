<?php

declare(strict_types=1);

namespace Reach\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reach\Auth\Base64Url;
use Reach\Auth\Providers\OAuthProvider;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\VerifiedIdentity;
use Reach\CallAttempts\CallAttempt;
use Reach\CallRequests\CallRequest;
use Reach\Geocoding\Coordinates;

/**
 * Cover the small immutable value objects and helper traits that carry no
 * WordPress or network dependency: coordinate range validation, the
 * base64url codec, the provider registry, and the DTO accessors. Cheap to
 * run, and they pin behaviour (range rejection, case-insensitive provider
 * lookup, serial formatting) that other classes quietly rely on.
 */
final class ReachValueObjectsTest extends TestCase
{
    // --- Coordinates ------------------------------------------------------

    public function testCoordinatesAcceptInRangeValuesAndExposeThem(): void
    {
        $c = new Coordinates(51.4499, -2.5967);
        $this->assertSame(51.4499, $c->latitude);
        $this->assertSame(-2.5967, $c->longitude);
        $this->assertSame(['latitude' => 51.4499, 'longitude' => -2.5967], $c->toArray());
    }

    public function testCoordinatesAcceptTheExactBounds(): void
    {
        $this->assertInstanceOf(Coordinates::class, new Coordinates(-90.0, -180.0));
        $this->assertInstanceOf(Coordinates::class, new Coordinates(90.0, 180.0));
    }

    /**
     * @dataProvider outOfRangeCoordinates
     */
    public function testCoordinatesRejectOutOfRangeValues(float $lat, float $lng): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Coordinates($lat, $lng);
    }

    /** @return array<string, array{0: float, 1: float}> */
    public static function outOfRangeCoordinates(): array
    {
        return [
            'lat too low'  => [-90.1, 0.0],
            'lat too high' => [90.1, 0.0],
            'lng too low'  => [0.0, -180.1],
            'lng too high' => [0.0, 180.1],
        ];
    }

    // --- VerifiedIdentity -------------------------------------------------

    public function testVerifiedIdentityDefaultsProviderEmailToNull(): void
    {
        $id = new VerifiedIdentity('a@example.com', 'google', 'sub-1');
        $this->assertSame('a@example.com', $id->email);
        $this->assertSame('google', $id->provider);
        $this->assertSame('sub-1', $id->sub);
        $this->assertNull($id->providerEmail);
    }

    public function testVerifiedIdentityCarriesProviderEmailWhenAnonymised(): void
    {
        $id = new VerifiedIdentity('real@example.com', 'facebook', 'sub-2', 'relay@privaterelay.facebook.com');
        $this->assertSame('relay@privaterelay.facebook.com', $id->providerEmail);
    }

    // --- ProviderRegistry -------------------------------------------------

    public function testProviderRegistryLooksUpCaseInsensitivelyByName(): void
    {
        $registry = new ProviderRegistry();
        $google = $this->fakeProvider('Google');
        $registry->register($google);

        $this->assertSame($google, $registry->get('google'));
        $this->assertSame($google, $registry->get('GOOGLE'));
        $this->assertNull($registry->get('microsoft'));
        $this->assertSame(['google'], $registry->names());
    }

    public function testProviderRegistryRegistrationIsIdempotentByNormalisedName(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->fakeProvider('Apple'));
        $registry->register($this->fakeProvider('apple'));

        // Same normalised key — one entry, the later registration winning.
        $this->assertCount(1, $registry->names());
    }

    // --- Base64Url --------------------------------------------------------

    public function testBase64UrlRoundTripsAndOmitsPadding(): void
    {
        $codec = $this->base64UrlCodec();
        // 0xFF 0xFE has a '+' and '/' in standard base64 ("//4=") and must
        // come back as '_-' with no '=' padding.
        $encoded = $codec->encode("\xff\xfe");
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertSame("\xff\xfe", $codec->decode($encoded));
    }

    public function testBase64UrlDecodeOrNullReturnsNullOnGarbage(): void
    {
        $codec = $this->base64UrlCodec();
        // A character outside the base64url alphabet makes strict decode fail.
        $this->assertNull($codec->decodeOrNull('!!!not-base64!!!'));
        // The empty-string-returning form collapses that failure to ''.
        $this->assertSame('', $codec->decode('!!!not-base64!!!'));
    }

    // --- CallRequest / CallAttempt DTOs -----------------------------------

    public function testCallRequestSerialAndCompletionFlag(): void
    {
        $open = new CallRequest(123, 'Responder', 'BS5', 'r@example.com', 'google', 1_700_000_000);
        $this->assertSame('CR-000123', $open->serial());
        $this->assertFalse($open->isCompleted());

        $done = new CallRequest(9, 'R', 'BS5', 'r@example.com', 'google', 1_700_000_000, 1_700_000_500, 42, 'Volunteer');
        $this->assertSame('CR-000009', $done->serial());
        $this->assertTrue($done->isCompleted());
    }

    public function testCallAttemptOutcomeValidation(): void
    {
        $this->assertTrue(CallAttempt::isValidOutcome(CallAttempt::OUTCOME_REACHED));
        $this->assertTrue(CallAttempt::isValidOutcome('no_answer'));
        $this->assertFalse(CallAttempt::isValidOutcome('made_up_outcome'));
        $this->assertFalse(CallAttempt::isValidOutcome(''));
    }

    // --- helpers ----------------------------------------------------------

    private function fakeProvider(string $name): OAuthProvider
    {
        return new class ($name) implements OAuthProvider {
            public function __construct(private string $providerName)
            {
            }
            public function name(): string
            {
                return $this->providerName;
            }
            public function isServerSide(): bool
            {
                return true;
            }
            public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
            {
                return 'https://example.test/auth';
            }
            public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
            {
                return null;
            }
            public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
            {
                return null;
            }
        };
    }

    /**
     * A tiny object exposing the protected Base64Url trait methods so the
     * codec can be tested without going through one of its many callers.
     */
    private function base64UrlCodec(): object
    {
        return new class {
            use Base64Url;

            public function encode(string $data): string
            {
                return $this->base64UrlEncode($data);
            }
            public function decode(string $data): string
            {
                return $this->base64UrlDecode($data);
            }
            public function decodeOrNull(string $data): ?string
            {
                return $this->base64UrlDecodeOrNull($data);
            }
        };
    }
}
