<?php

declare(strict_types=1);

namespace Reach\Geocoding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve a free-text area string (a UK postcode like "BS1 4ST", a postcode
 * outcode like "BS3", or a place name like "Bedminster") to a single
 * {@see Coordinates} pair.
 *
 * Implementations may return null when the input cannot be resolved. They
 * MUST NOT throw on a routine miss — callers will routinely encounter
 * members with malformed or empty area strings and the resolver needs to
 * skip them without aborting the whole request.
 */
interface Geocoder
{
    /**
     * Geocode a single area string.
     *
     * @param string $area The free-text area; may be empty.
     * @return Coordinates|null Null if the area cannot be resolved.
     */
    public function geocode(string $area): ?Coordinates;
}
