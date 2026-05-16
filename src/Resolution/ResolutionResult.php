<?php

declare(strict_types=1);

namespace Reach\Resolution;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Geocoding\Coordinates;

/**
 * Outcome of a resolver run.
 *
 * Two flavours: a success carries the geocoded origin and the ordered
 * list of nearest members; a failure carries the unresolved location
 * string so the REST controller can return a meaningful 4xx. Using a
 * single result type with a discriminating flag keeps the controller's
 * handler free of try/catch noise and makes the resolver pure (no
 * exceptions for control flow).
 */
final class ResolutionResult
{
    /**
     * @param array<int, ScoredMember> $members
     */
    private function __construct(
        public readonly bool $resolved,
        public readonly ?Coordinates $origin,
        public readonly array $members,
        public readonly ?string $unresolvedLocation,
    ) {
    }

    /**
     * @param array<int, ScoredMember> $members
     */
    public static function success(Coordinates $origin, array $members): self
    {
        return new self(true, $origin, $members, null);
    }

    public static function unresolvableLocation(string $location): self
    {
        return new self(false, null, [], $location);
    }
}
