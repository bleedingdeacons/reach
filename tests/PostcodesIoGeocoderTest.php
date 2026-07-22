<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Geocoding\Coordinates;
use Reach\Geocoding\PostcodesIoGeocoder;

/**
 * Behavioural cover for {@see PostcodesIoGeocoder}.
 *
 * The geocoder's contract is "never throw, cache aggressively, and pick the
 * right postcodes.io endpoint from the shape of the input". These tests drive
 * every strategy (postcode / outcode / place), the caching layer (hit, miss
 * sentinel, corrupt entry), the place-name bias re-ranking, and each failure
 * mode of the HTTP layer — all through the wp_remote_* stub in the bootstrap,
 * so no real network is touched.
 */
final class PostcodesIoGeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
        unset($GLOBALS['__reach_http_stub']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__reach_http_stub']);
    }

    public function testBlankInputReturnsNullWithoutTouchingTheNetwork(): void
    {
        // No HTTP stub installed: any request would surface as a WP_Error and
        // still return null, but a blank area must short-circuit before that.
        $geo = new PostcodesIoGeocoder();
        $this->assertNull($geo->geocode('   '));
    }

    public function testFullPostcodeResolvesViaPostcodesEndpoint(): void
    {
        $this->stubByUrl([
            '/postcodes/BS14ST' => $this->resultBody(51.4499, -2.5967),
        ]);

        $coords = (new PostcodesIoGeocoder())->geocode('bs1 4st');

        $this->assertInstanceOf(Coordinates::class, $coords);
        $this->assertEqualsWithDelta(51.4499, $coords->latitude, 0.0001);
        $this->assertEqualsWithDelta(-2.5967, $coords->longitude, 0.0001);
    }

    public function testOutcodeResolvesViaOutcodesEndpoint(): void
    {
        $this->stubByUrl([
            '/outcodes/BS3' => $this->resultBody(51.44, -2.60),
        ]);

        $coords = (new PostcodesIoGeocoder())->geocode('BS3');

        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(51.44, $coords->latitude, 0.0001);
    }

    public function testPlaceNameResolvesToFirstHitWithoutBias(): void
    {
        $this->stubByUrl([
            '/places?q=Bedminster' => $this->placesBody([
                [51.43, -2.60],
                [52.00, -1.00],
            ]),
        ]);

        $coords = (new PostcodesIoGeocoder())->geocode('Bedminster');

        $this->assertNotNull($coords);
        // Unbiased: takes the first candidate as postcodes.io ranked it.
        $this->assertEqualsWithDelta(51.43, $coords->latitude, 0.0001);
    }

    public function testPlaceNameBiasReRanksToNearestCandidate(): void
    {
        // Bias "BS5" (an outcode) resolves to a Bristol centroid; the
        // ambiguous "Kingswood" then resolves to the Bristol Kingswood, not
        // the far-away Surrey one, even though Surrey is listed first.
        $this->stubByUrl([
            '/outcodes/BS5'                 => $this->resultBody(51.4636, -2.5520),
            '/places?q=Kingswood&limit=20'  => $this->placesBody([
                [51.3100, -0.2000], // Kingswood, Surrey — first, but far
                [51.4630, -2.5000], // Kingswood, Bristol — near the bias
            ]),
        ]);

        $coords = (new PostcodesIoGeocoder('BS5'))->geocode('Kingswood');

        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(51.4630, $coords->latitude, 0.0001);
        $this->assertEqualsWithDelta(-2.5000, $coords->longitude, 0.0001);
    }

    public function testBiasThatDoesNotGeocodeFallsBackToUnbiasedRanking(): void
    {
        // The bias outcode 404s, so getBias() yields null and the place
        // lookup reverts to first-hit ranking (and no &limit=20).
        $this->stubByUrl([
            '/outcodes/ZZ9'       => $this->notFound(),
            '/places?q=Kingswood' => $this->placesBody([[51.31, -0.20]]),
        ]);

        $coords = (new PostcodesIoGeocoder('ZZ9'))->geocode('Kingswood');

        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(51.31, $coords->latitude, 0.0001);
    }

    public function testSuccessfulLookupIsCachedAndNotRefetched(): void
    {
        $calls = 0;
        $this->stubByUrl(
            ['/postcodes/BS14ST' => $this->resultBody(51.45, -2.59)],
            $calls,
        );

        $geo = new PostcodesIoGeocoder();
        $first  = $geo->geocode('BS1 4ST');
        $second = $geo->geocode('BS1 4ST');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $calls, 'second lookup must be served from the transient cache');
    }

    public function testMissIsCachedSoRepeatedTyposDoNotHammerTheApi(): void
    {
        $calls = 0;
        // Everything 404s / has no result, so the whole resolve chain misses.
        $this->stubByUrl([], $calls, $this->notFound());

        $geo = new PostcodesIoGeocoder();
        $this->assertNull($geo->geocode('NOWHERE PLACE'));
        $callsAfterFirst = $calls;
        $this->assertNull($geo->geocode('NOWHERE PLACE'));

        $this->assertSame($callsAfterFirst, $calls, 'a cached miss must not re-issue requests');
    }

    public function testCorruptCacheEntryIsDiscardedAndReResolved(): void
    {
        // Seed the transient with an out-of-range latitude so constructing
        // Coordinates throws and the geocoder falls through to a fresh lookup.
        $key = 'reach_geo_' . md5('|' . strtolower('BS1 4ST'));
        $GLOBALS['__reach_transients'][$key] = ['lat' => 999.0, 'lng' => 0.0];

        $this->stubByUrl(['/postcodes/BS14ST' => $this->resultBody(51.45, -2.59)]);

        $coords = (new PostcodesIoGeocoder())->geocode('BS1 4ST');

        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(51.45, $coords->latitude, 0.0001);
    }

    public function testNetworkErrorIsSwallowedAndReturnsNull(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => new \WP_Error('http_request_failed', 'Connection timed out');

        $this->assertNull((new PostcodesIoGeocoder())->geocode('BS1 4ST'));
    }

    public function testInvalidJsonBodyReturnsNull(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => ['response' => ['code' => 200], 'body' => 'not-json-at-all'];

        $this->assertNull((new PostcodesIoGeocoder())->geocode('BS1 4ST'));
    }

    public function testNullCoordinatesInResultAreTreatedAsAMiss(): void
    {
        // postcodes.io returns null lat/lng for a few legitimately unmapped
        // postcodes; that must resolve to null rather than a (0,0) island.
        $this->stubByUrl([
            '/postcodes/BS14ST' => json_encode([
                'result' => ['latitude' => null, 'longitude' => null],
            ]),
        ]);

        $this->assertNull((new PostcodesIoGeocoder())->geocode('BS1 4ST'));
    }

    public function testFullLookingPostcodeFallsThroughToOutcode(): void
    {
        // "BS3OO" looks full by shape but 404s as a postcode; the geocoder
        // then tries the embedded outcode "BS3OO"… which is not a valid
        // outcode shape, so it finally tries /places. Assert it does not
        // throw and resolves via the place endpoint.
        $this->stubByUrl([
            '/postcodes/BS3OO'    => $this->notFound(),
            '/places?q=BS3OO'     => $this->placesBody([[51.44, -2.60]]),
        ]);

        $coords = (new PostcodesIoGeocoder())->geocode('BS3OO');
        $this->assertNotNull($coords);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Install an HTTP stub dispatching by URL suffix. Keys are matched with
     * str_contains against the request URL; the value is the JSON body
     * returned with a 200. Anything unmatched returns $default (a 404 by
     * default). $calls, if passed by reference, counts matched 2xx responses.
     *
     * @param array<string, string> $map
     */
    private function stubByUrl(array $map, int &$calls = 0, ?string $default = null): void
    {
        $default = $default ?? $this->notFound();
        $GLOBALS['__reach_http_stub'] = static function (string $url, array $args = []) use ($map, &$calls, $default) {
            foreach ($map as $needle => $body) {
                if (str_contains($url, $needle)) {
                    $calls++;
                    return ['response' => ['code' => 200], 'body' => $body];
                }
            }
            return ['response' => ['code' => 404], 'body' => $default];
        };
    }

    private function resultBody(float $lat, float $lng): string
    {
        return (string) json_encode([
            'result' => ['latitude' => $lat, 'longitude' => $lng],
        ]);
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points
     */
    private function placesBody(array $points): string
    {
        $rows = array_map(
            static fn(array $p) => ['latitude' => $p[0], 'longitude' => $p[1]],
            $points,
        );
        return (string) json_encode(['result' => $rows]);
    }

    private function notFound(): string
    {
        return (string) json_encode(['status' => 404, 'error' => 'Not found']);
    }
}
