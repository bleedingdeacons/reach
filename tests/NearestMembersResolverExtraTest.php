<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Geocoding\Coordinates;
use Reach\Resolution\NearestMembersResolver;
use Reach\Resolution\ScoredMember;
use Unity\Members\Interfaces\Member;

require_once __DIR__ . '/NearestMembersControllerTest.php'; // ControllerStubGeocoder, ControllerMemberRepository

/**
 * Behavioural cover for {@see NearestMembersResolver} beyond the base
 * resolver test: the gender filter (drop vs keep-and-tag under
 * include-non-preferred), pipe-separated member areas resolving to the
 * nearest entry, the max-distance cutoff, and the skip rules for members
 * with no area or an ungeocodable one.
 */
final class NearestMembersResolverExtraTest extends TestCase
{
    private function geocoder(): ControllerStubGeocoder
    {
        // Origin at BS1; three areas at increasing distance north.
        return new ControllerStubGeocoder([
            'BS1'       => new Coordinates(51.4500, -2.5900),
            'NEAR'      => new Coordinates(51.4600, -2.5900), // ~1.1 km
            'MID'       => new Coordinates(51.5000, -2.5900), // ~5.6 km
            'FAR'       => new Coordinates(51.7000, -2.5900), // ~22 km
            'Kingswood' => new Coordinates(51.4650, -2.5000), // east, near-ish
            'Hanham'    => new Coordinates(51.4400, -2.4900),
        ]);
    }

    public function testGenderFilterDropsNonMatchingMembersByDefault(): void
    {
        $members = [
            $this->member(1, 'NEAR', ['female'], true),
            $this->member(2, 'MID', ['male'], true),
        ];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        // Caller wants only members who accept female callers.
        $result = $resolver->resolve('BS1', ['Female'], 10);

        $this->assertTrue($result->resolved);
        $ids = array_map(static fn(ScoredMember $s) => $s->member->getId(), $result->members);
        $this->assertSame([1], $ids, 'only the female-accepting member survives the default filter');
    }

    public function testIncludeNonPreferredKeepsAndTagsNonMatching(): void
    {
        $members = [
            $this->member(1, 'MID', ['female'], true),
            $this->member(2, 'NEAR', ['male'], true),
        ];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        $result = $resolver->resolve('BS1', ['Female'], 10, null, true);

        // Both kept; nearer non-preferred member sorts first by distance, but
        // is tagged preferred=false.
        $byId = [];
        foreach ($result->members as $s) {
            $byId[$s->member->getId()] = $s->preferred;
        }
        $this->assertTrue($byId[1]);   // accepts female → preferred
        $this->assertFalse($byId[2]);  // does not → kept but not preferred
    }

    public function testEmptyFilterMakesEveryMemberPreferred(): void
    {
        $members = [$this->member(1, 'NEAR', [], true)];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        $result = $resolver->resolve('BS1', [], 10);
        $this->assertTrue($result->members[0]->preferred);
    }

    public function testPipeSeparatedAreaResolvesToNearestEntry(): void
    {
        // The member covers Kingswood|Hanham; the resolver attributes them to
        // whichever entry is closest to the origin and surfaces that string.
        $members = [$this->member(1, 'Kingswood|Hanham', [], true)];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        $result = $resolver->resolve('BS1', [], 10);

        $this->assertCount(1, $result->members);
        $this->assertContains($result->members[0]->matchedArea, ['Kingswood', 'Hanham']);
    }

    public function testMaxDistanceCutoffDropsFarMembers(): void
    {
        $members = [
            $this->member(1, 'NEAR', [], true),
            $this->member(2, 'FAR', [], true),
        ];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        // 10 km cap: NEAR (~1 km) stays, FAR (~22 km) is dropped.
        $result = $resolver->resolve('BS1', [], 10, 10.0);
        $ids = array_map(static fn(ScoredMember $s) => $s->member->getId(), $result->members);
        $this->assertSame([1], $ids);
    }

    public function testMembersWithNoAreaOrUngeocodableAreaAreSkipped(): void
    {
        $members = [
            $this->member(1, '', [], true),            // empty area
            $this->member(2, 'ATLANTIS', [], true),    // not in the geocoder
            $this->member(3, 'NEAR', [], true),        // fine
        ];
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        $result = $resolver->resolve('BS1', [], 10);
        $ids = array_map(static fn(ScoredMember $s) => $s->member->getId(), $result->members);
        $this->assertSame([3], $ids);
    }

    public function testNonTwelfthSteppersAreExcluded(): void
    {
        $members = [$this->member(1, 'NEAR', [], false)]; // not a 12th-stepper
        $resolver = new NearestMembersResolver(new ControllerMemberRepository($members), $this->geocoder());

        $result = $resolver->resolve('BS1', [], 10);
        $this->assertCount(0, $result->members);
    }

    /**
     * @param array<int, string> $accepts
     */
    private function member(int $id, string $area, array $accepts, bool $twelfth): Member
    {
        return new class ($id, $area, $accepts, $twelfth) implements Member {
            /** @param array<int, string> $accepts */
            public function __construct(
                private int $id,
                private string $area,
                private array $accepts,
                private bool $twelfth,
            ) {
            }
            public function getId(): int { return $this->id; }
            public function getAnonymousName(): string { return 'M' . $this->id; }
            public function showAnonymousName(): bool { return true; }
            public function showMemberProfile(): bool { return true; }
            public function getAnonymousProfile(): string { return ''; }
            public function getIntergroupPosition(): int { return 0; }
            public function getIntergroupPositionRotation(): string { return ''; }
            public function getHomeGroup(): int { return 0; }
            public function isGSR(): bool { return false; }
            public function getMeetingPO(): mixed { return null; }
            public function getPersonalEmail(): string { return 'm' . $this->id . '@example.com'; }
            public function getMobileNumber(): string { return ''; }
            public function isTwelfthStepper(): bool { return $this->twelfth; }
            public function isTelephoneResponder(): bool { return false; }
            public function getResponderCertification(): \Unity\Members\ResponderCertification { return \Unity\Members\ResponderCertification::None; }
            public function getArea(): string { return $this->area; }
            public function getAccepts(): array { return $this->accepts; }
            public function isGdprAccepted(): bool { return true; }
            public function getGdprAcceptedAt(): string { return ''; }
            public function getGdprAcceptanceVersion(): string { return ''; }
            public function getGdprAcceptanceMethod(): string { return ''; }
            public function getGdprAcceptanceStatement(): string { return ''; }
            public function getUpdated(): string { return ''; }
        };
    }
}
