<?php

declare(strict_types=1);

namespace Reach\Resolution;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Distance\Haversine;
use Reach\Geocoding\Coordinates;
use Reach\Geocoding\Geocoder;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Resolve the nearest Unity members to a given location, filtered by the
 * genders they accept 12th-step calls from.
 *
 * The pipeline:
 *
 *   1. Pull every member through the repository's findAll().
 *   2. Drop anyone who isn't a 12th-stepper.
 *   3. Decide whether each member is "preferred" — i.e. the genders they
 *      accept 12th-step calls from intersect the requested filter.
 *      In the default mode a non-preferred member is dropped here; in
 *      include-non-preferred mode they are kept and tagged instead, so
 *      they can still be offered as a nearby fallback.
 *   4. Drop anyone whose area can't be geocoded (no coordinates → no distance
 *      → no useful place in a "nearest" answer).
 *   5. Apply the optional max-distance cutoff.
 *   6. Sort by distance ascending, then preferred-first as a tie-break.
 *   7. Take the first $limit.
 *
 * Filtering in PHP (rather than via a meta_query) is intentional. The
 * twelfth-stepper boolean and accepts list are ACF-backed, the working
 * set is small (one intergroup's members, hundreds at most), and the
 * cost is dominated by the network round trips to postcodes.io — not by
 * the in-memory scan. Pushing the filter into SQL would buy back
 * milliseconds while making the code substantially more brittle.
 */
final class NearestMembersResolver
{
    use \Reach\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    public function __construct(
        private readonly MemberRepository $members,
        private readonly Geocoder $geocoder,
    ) {
    }

    /**
     * @param string $location      Free-text area for the search origin (postcode or place).
     * @param array<int, string> $accepts  Gender filter; empty means "any".
     *                              A member matches if their own accepts list
     *                              (the genders they take 12th-step calls from)
     *                              intersects this list. Case-insensitive.
     *                              Allowed values: Male, Female, Non-Binary.
     * @param int    $limit         Maximum number of members to return. Clamped to >= 1.
     * @param float|null $maxKm     Optional hard cutoff: drop anyone further than this
     *                              from the origin. Null means no cap.
     * @param bool   $includeNonPreferred  When false (default) a member whose accepts
     *                              list doesn't intersect the filter is dropped — the
     *                              historical "filter" behaviour. When true those
     *                              members are kept (subject to the same location and
     *                              distance rules) and tagged as not preferred, so the
     *                              caller can surface nearby fallbacks. Has no visible
     *                              effect when $accepts is empty: every member is
     *                              preferred under an empty filter.
     *
     * @return ResolutionResult
     */
    public function resolve(
        string $location,
        array $accepts,
        int $limit,
        ?float $maxKm = null,
        bool $includeNonPreferred = false,
    ): ResolutionResult {
        $limit = max(1, $limit);

        $origin = $this->geocoder->geocode($location);
        if ($origin === null) {
            return ResolutionResult::unresolvableLocation($location);
        }

        $wantedGenders = $this->normaliseGenders($accepts);

        $candidates = [];

        foreach ($this->members->findAll() as $member) {
            if (!$member instanceof Member) {
                continue;
            }
            if (!$member->isTwelfthStepper()) {
                continue;
            }

            $preferred = $this->genderMatches($member->getAccepts(), $wantedGenders);
            if (!$preferred && !$includeNonPreferred) {
                // Default mode: a member who doesn't accept any of the
                // requested genders is filtered out entirely.
                continue;
            }

            $area = trim($member->getArea());
            if ($area === '') {
                continue;
            }

            $memberCoords = $this->geocoder->geocode($area);
            if ($memberCoords === null) {
                self::logDebug('Resolver: member area not geocodable', [
                    'member_id' => $member->getId(),
                ]);
                continue;
            }

            $distance = Haversine::kilometres($origin, $memberCoords);

            if ($maxKm !== null && $distance > $maxKm) {
                continue;
            }

            $candidates[] = new ScoredMember($member, $memberCoords, $distance, $preferred);
        }

        // Distance ascending is the primary key; preferred-first breaks
        // ties so that, all else equal, a member who accepts the
        // requested genders is offered ahead of a fallback who doesn't.
        usort(
            $candidates,
            static function (ScoredMember $a, ScoredMember $b): int {
                return [$a->distanceKm, $b->preferred] <=> [$b->distanceKm, $a->preferred];
            }
        );

        $top = array_slice($candidates, 0, $limit);

        return ResolutionResult::success($origin, $top);
    }

    /**
     * Lower-case and de-duplicate the requested gender list so the
     * intersection check below is case-insensitive and order-independent.
     *
     * @param array<int, string> $accepts
     * @return array<int, string>
     */
    private function normaliseGenders(array $accepts): array
    {
        $clean = [];
        foreach ($accepts as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            if ($value !== '') {
                $clean[$value] = true;
            }
        }
        return array_keys($clean);
    }

    /**
     * A member matches if either (a) the caller did not specify any
     * gender filter, or (b) the member's accepts list intersects the
     * filter. A member who accepts no genders never matches a filtered
     * query — they have no one they'll take a 12th-step call from.
     *
     * @param array<int, string> $memberAccepts
     * @param array<int, string> $wantedGenders
     */
    private function genderMatches(array $memberAccepts, array $wantedGenders): bool
    {
        if ($wantedGenders === []) {
            return true;
        }
        foreach ($memberAccepts as $accept) {
            if (!is_string($accept)) {
                continue;
            }
            if (in_array(strtolower(trim($accept)), $wantedGenders, true)) {
                return true;
            }
        }
        return false;
    }
}
