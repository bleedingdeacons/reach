<?php

declare(strict_types=1);

namespace Reach\Tests;

use InvalidArgumentException;
use Reach\Auth\Base64Url;
use Reach\CallAttempts\CallAttempt;
use Reach\CallRequests\CallRequest;
use Reach\Geocoding\Coordinates;

/**
 * Cover the small immutable value objects and helper traits that carry no
 * WordPress or network dependency: coordinate range validation, the
 * base64url codec, and the DTO accessors. Cheap to run, and they pin
 * behaviour (range rejection, serial formatting) that other classes quietly
 * rely on. VerifiedIdentity and ProviderRegistry moved to the Guardian
 * library, and are tested there.
 */

beforeEach(function () {
    /**
     * A tiny object exposing the protected Base64Url trait methods so the
     * codec can be tested without going through one of its many callers.
     */
    $this->base64UrlCodec = function (): object {
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
    };
});

// --- Coordinates ------------------------------------------------------
test('coordinates accept in range values and expose them', function () {
    $c = new Coordinates(51.4499, -2.5967);
    $this->assertSame(51.4499, $c->latitude);
    $this->assertSame(-2.5967, $c->longitude);
    $this->assertSame(['latitude' => 51.4499, 'longitude' => -2.5967], $c->toArray());
});

test('coordinates accept the exact bounds', function () {
    $this->assertInstanceOf(Coordinates::class, new Coordinates(-90.0, -180.0));
    $this->assertInstanceOf(Coordinates::class, new Coordinates(90.0, 180.0));
});

test('coordinates reject out of range values', function (float $lat, float $lng) {
    $this->expectException(InvalidArgumentException::class);
    new Coordinates($lat, $lng);
})->with('outOfRangeCoordinates');

/** @return array<string, array{0: float, 1: float}> */
dataset('outOfRangeCoordinates', function (): array {
    return [
        'lat too low'  => [-90.1, 0.0],
        'lat too high' => [90.1, 0.0],
        'lng too low'  => [0.0, -180.1],
        'lng too high' => [0.0, 180.1],
    ];
});

// --- Base64Url --------------------------------------------------------
test('base64 url round trips and omits padding', function () {
    $codec = ($this->base64UrlCodec)();
    // 0xFF 0xFE has a '+' and '/' in standard base64 ("//4=") and must
    // come back as '_-' with no '=' padding.
    $encoded = $codec->encode("\xff\xfe");
    $this->assertStringNotContainsString('=', $encoded);
    $this->assertStringNotContainsString('+', $encoded);
    $this->assertStringNotContainsString('/', $encoded);
    $this->assertSame("\xff\xfe", $codec->decode($encoded));
});

test('base64 url decode or null returns null on garbage', function () {
    $codec = ($this->base64UrlCodec)();
    // A character outside the base64url alphabet makes strict decode fail.
    $this->assertNull($codec->decodeOrNull('!!!not-base64!!!'));
    // The empty-string-returning form collapses that failure to ''.
    $this->assertSame('', $codec->decode('!!!not-base64!!!'));
});

// --- CallRequest / CallAttempt DTOs -----------------------------------
test('call request serial and completion flag', function () {
    $open = new CallRequest(123, 'Responder', 'BS5', 'r@example.com', 'google', 1_700_000_000);
    $this->assertSame('CR-000123', $open->serial());
    $this->assertFalse($open->isCompleted());

    $done = new CallRequest(9, 'R', 'BS5', 'r@example.com', 'google', 1_700_000_000, 1_700_000_500, 42, 'Volunteer');
    $this->assertSame('CR-000009', $done->serial());
    $this->assertTrue($done->isCompleted());
});

test('call attempt outcome validation', function () {
    $this->assertTrue(CallAttempt::isValidOutcome(CallAttempt::OUTCOME_REACHED));
    $this->assertTrue(CallAttempt::isValidOutcome('no_answer'));
    $this->assertFalse(CallAttempt::isValidOutcome('made_up_outcome'));
    $this->assertFalse(CallAttempt::isValidOutcome(''));
});
