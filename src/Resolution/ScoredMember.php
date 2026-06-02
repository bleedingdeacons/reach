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
 *
 * `$preferred` records whether this member matched the caller's gender
 * filter (the genders they accept 12th-step calls from intersect the
 * requested set). When the resolver runs in include-non-preferred
 * mode it keeps members who fail that match so they can still be
 * offered as nearby fallbacks; `$preferred` lets the controller and
 * UI distinguish a preference match from an in-location-only result.
 * With no gender filter every in-range member is trivially preferred.
 *
 * `$matchedArea` is the specific entry from the member's area field
 * that won when that field is a pipe-separated list (e.g. for a
 * member stored as `Kingswood|Hanham`, this is `Kingswood` when
 * Kingswood is the nearer of the two to the caller). It is null when
 * the member's area is a single entry — there's nothing to pick from,
 * so the controller falls back to the raw `getArea()` value. The
 * field lets the response carry only the area that actually mapped
 * to this member's reported distance, instead of leaking the
 * separator-as-data into the UI.
 */
final class ScoredMember
{
    public function __construct(
        public readonly Member $member,
        public readonly Coordinates $coordinates,
        public readonly float $distanceKm,
        public readonly bool $preferred = true,
        public readonly ?string $matchedArea = null,
    ) {
    }
}
