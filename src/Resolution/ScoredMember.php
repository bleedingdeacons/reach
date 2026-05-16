<?php

declare(strict_types=1);

namespace Reach\Resolution;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Geocoding\Coordinates;
use Unity\Members\Interfaces\Member;

/**
 * A member paired with their geocoded location and distance from the
 * search origin. Held by {@see NearestMembersResolver} during sorting
 * and consumed by the REST controller when projecting the response.
 */
final class ScoredMember
{
    public function __construct(
        public readonly Member $member,
        public readonly Coordinates $coordinates,
        public readonly float $distanceKm,
    ) {
    }
}
