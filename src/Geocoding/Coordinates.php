<?php

declare(strict_types=1);

namespace Reach\Geocoding;

if (!defined('ABSPATH')) {
    exit;
}

use InvalidArgumentException;

/**
 * Immutable WGS84 latitude/longitude pair.
 *
 * Construction validates that both components lie within their canonical
 * ranges; anything outside that range is treated as a programmer error
 * rather than silently clamped, so a bad geocode result fails loudly
 * rather than placing a member in the middle of the ocean.
 */
final class Coordinates
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException(
                sprintf('Latitude %F is out of range [-90, 90]', $latitude)
            );
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException(
                sprintf('Longitude %F is out of range [-180, 180]', $longitude)
            );
        }
    }

    public function toArray(): array
    {
        return [
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
