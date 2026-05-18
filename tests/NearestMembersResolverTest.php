<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Geocoding\Coordinates;
use Reach\Geocoding\Geocoder;
use Reach\Resolution\NearestMembersResolver;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Exercises the resolver against an in-memory fixture. Geocoder and
 * MemberRepository are both faked here — the goal is to lock down the
 * filter/sort/limit pipeline, not to test postcodes.io or WP_Query.
 */
final class NearestMembersResolverTest extends TestCase
{
    public function testReturnsTwelfthSteppersOnly(): void
    {
        $repo = new InMemoryMemberRepository([
            $this->stubMember(1, 'A', true,  ['phone'], 'BS1 1AA'),
            $this->stubMember(2, 'B', false, ['phone'], 'BS1 1AB'), // not a 12th-stepper
        ]);
        $geo = new StubGeocoder([
            'BS1' => new Coordinates(51.45, -2.58),
            'BS1 1AA' => new Coordinates(51.46, -2.58),
            'BS1 1AB' => new Coordinates(51.47, -2.58),
        ]);

        $result = (new NearestMembersResolver($repo, $geo))->resolve('BS1', [], 10);

        $this->assertTrue($result->resolved);
        $this->assertCount(1, $result->members);
        $this->assertSame(1, $result->members[0]->member->getId());
    }

    public function testFiltersByAcceptsCaseInsensitively(): void
    {
        $repo = new InMemoryMemberRepository([
            $this->stubMember(1, 'A', true, ['Phone'],    'BS1 1AA'),
            $this->stubMember(2, 'B', true, ['email'],   'BS1 1AB'),
            $this->stubMember(3, 'C', true, ['text', 'phone'], 'BS1 1AC'),
        ]);
        $geo = new StubGeocoder([
            'BS1'     => new Coordinates(51.45, -2.58),
            'BS1 1AA' => new Coordinates(51.46, -2.58),
            'BS1 1AB' => new Coordinates(51.47, -2.58),
            'BS1 1AC' => new Coordinates(51.48, -2.58),
        ]);

        $result = (new NearestMembersResolver($repo, $geo))->resolve('BS1', ['PHONE'], 10);

        $ids = array_map(fn($sm) => $sm->member->getId(), $result->members);
        $this->assertSame([1, 3], $ids);
    }

    public function testSortsByDistanceAndAppliesLimit(): void
    {
        $repo = new InMemoryMemberRepository([
            // Increasing latitude → increasing distance from origin at 51.0.
            $this->stubMember(1, 'A', true, ['phone'], 'P1'),
            $this->stubMember(2, 'B', true, ['phone'], 'P2'),
            $this->stubMember(3, 'C', true, ['phone'], 'P3'),
            $this->stubMember(4, 'D', true, ['phone'], 'P4'),
        ]);
        $geo = new StubGeocoder([
            'origin' => new Coordinates(51.0, -2.5),
            'P1' => new Coordinates(51.4, -2.5), // furthest
            'P2' => new Coordinates(51.1, -2.5),
            'P3' => new Coordinates(51.2, -2.5),
            'P4' => new Coordinates(51.3, -2.5),
        ]);

        $result = (new NearestMembersResolver($repo, $geo))->resolve('origin', [], 2);

        $ids = array_map(fn($sm) => $sm->member->getId(), $result->members);
        $this->assertSame([2, 3], $ids, 'Expected the two nearest, ascending.');
    }

    public function testReturnsUnresolvableWhenLocationCannotBeGeocoded(): void
    {
        $repo = new InMemoryMemberRepository([]);
        $geo = new StubGeocoder([]); // no entries → every lookup is a miss

        $result = (new NearestMembersResolver($repo, $geo))->resolve('nowhere', [], 10);

        $this->assertFalse($result->resolved);
        $this->assertSame('nowhere', $result->unresolvedLocation);
    }

    public function testMaxKmExcludesDistantMembers(): void
    {
        $repo = new InMemoryMemberRepository([
            $this->stubMember(1, 'A', true, ['phone'], 'P1'),
            $this->stubMember(2, 'B', true, ['phone'], 'P2'),
        ]);
        // Origin at (51.0, -2.5). P1 ~11km north, P2 ~110km north.
        $geo = new StubGeocoder([
            'origin' => new Coordinates(51.0, -2.5),
            'P1'     => new Coordinates(51.1, -2.5),
            'P2'     => new Coordinates(52.0, -2.5),
        ]);

        $result = (new NearestMembersResolver($repo, $geo))
            ->resolve('origin', [], 10, 50.0);

        $this->assertCount(1, $result->members);
        $this->assertSame(1, $result->members[0]->member->getId());
    }

    public function testSkipsMembersWithBlankOrUnresolvableArea(): void
    {
        $repo = new InMemoryMemberRepository([
            $this->stubMember(1, 'A', true, ['phone'], 'BS1 1AA'),
            $this->stubMember(2, 'B', true, ['phone'], ''),         // blank area
            $this->stubMember(3, 'C', true, ['phone'], 'gibberish'), // not geocodable
        ]);
        $geo = new StubGeocoder([
            'BS1'     => new Coordinates(51.45, -2.58),
            'BS1 1AA' => new Coordinates(51.46, -2.58),
        ]);

        $result = (new NearestMembersResolver($repo, $geo))->resolve('BS1', [], 10);

        $this->assertCount(1, $result->members);
        $this->assertSame(1, $result->members[0]->member->getId());
    }

    private function stubMember(int $id, string $name, bool $twelfth, array $accepts, string $area): Member
    {
        return new class($id, $name, $twelfth, $accepts, $area) implements Member {
            public function __construct(
                private int $id, private string $name, private bool $twelfth,
                private array $accepts, private string $area,
            ) {}
            public function getId(): int { return $this->id; }
            public function getAnonymousName(): string { return $this->name; }
            public function showAnonymousName(): bool { return true; }
            public function showMemberProfile(): bool { return true; }
            public function getAnonymousProfile(): string { return ''; }
            public function getIntergroupPosition(): int { return 0; }
            public function getIntergroupPositionRotation(): string { return ''; }
            public function getHomeGroup(): int { return 0; }
            public function isGSR(): bool { return false; }
            public function getMeetingPO(): mixed { return null; }
            public function getPersonalEmail(): string { return ''; }
            public function getMobileNumber(): string { return ''; }
            public function isTwelfthStepper(): bool { return $this->twelfth; }
            public function isTelephoneResponder(): bool { return false; }
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

final class InMemoryMemberRepository implements MemberRepository
{
    public function __construct(private array $members) {}
    public function findById(int $id): ?Member
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $id) return $m;
        }
        return null;
    }
    public function findByEmail(string $email): ?Member
    {
        foreach ($this->members as $m) {
            if (strcasecmp($m->getPersonalEmail(), $email) === 0) return $m;
        }
        return null;
    }
    public function findAll(array $args = []): array { return $this->members; }
    public function count(array $args = []): int { return count($this->members); }
    public function create(string $anonymousName): int { return 0; }
    public function save(Member $member): bool { return true; }
    public function delete(int $id): bool { return true; }
    public function update(Member $member): bool { return true; }
}

final class StubGeocoder implements Geocoder
{
    /** @param array<string, Coordinates> $entries */
    public function __construct(private array $entries) {}
    public function geocode(string $area): ?Coordinates
    {
        return $this->entries[$area] ?? null;
    }
}
