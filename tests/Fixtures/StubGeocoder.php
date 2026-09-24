<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Geocoding\Coordinates;
use Reach\Geocoding\Geocoder;

/**
 * A Geocoder that answers from a fixed map.
 *
 * Anything not in the map is unresolvable, which is how a test drives the
 * "could not find that area" branch without pretending to reach postcodes.io.
 *
 * Two near-identical copies of this used to live inside test files
 * (NearestMembersResolverTest's StubGeocoder and NearestMembersControllerTest's
 * ControllerStubGeocoder), each named around the other because a class declared
 * in a test file cannot be autoloaded and would otherwise clash. They went when
 * the suite moved to Pest: the resolver's extra tests reached the controller
 * test's copy by require_once-ing that whole test file, which a Pest spec cannot
 * be. This one is a fixture, so it autoloads and every test shares it.
 */
final class StubGeocoder implements Geocoder
{
    /** @param array<string, Coordinates> $entries */
    public function __construct(private array $entries = [])
    {
    }

    public function geocode(string $area): ?Coordinates
    {
        return $this->entries[$area] ?? null;
    }
}
