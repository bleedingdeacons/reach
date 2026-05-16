<?php

declare(strict_types=1);

namespace Reach\Distance;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Geocoding\Coordinates;

/**
 * Great-circle distance between two WGS84 points using the haversine
 * formula. Returns kilometres.
 *
 * Accuracy is more than enough for the "who's nearest" use case — the
 * formula assumes a perfect sphere and is off by up to ~0.3% at the
 * extremes, which translates to a few hundred metres at the scale we
 * care about. We do not need Vincenty here.
 */
final class Haversine
{
    /** Mean Earth radius in kilometres, per IUGG. */
    private const EARTH_RADIUS_KM = 6371.0088;

    public static function kilometres(Coordinates $a, Coordinates $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $deltaLat = deg2rad($b->latitude - $a->latitude);
        $deltaLng = deg2rad($b->longitude - $a->longitude);

        $h = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        // Clamp to [0, 1] for numerical safety — at very small distances
        // floating-point error can push the value a hair above 1, which
        // would make asin() return NaN.
        $h = max(0.0, min(1.0, $h));

        return 2 * self::EARTH_RADIUS_KM * asin(sqrt($h));
    }
}
