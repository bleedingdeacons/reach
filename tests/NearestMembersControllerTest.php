<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\Core\Settings;
use Reach\Geocoding\Coordinates;
use Reach\Geocoding\Geocoder;
use Reach\Resolution\NearestMembersResolver;
use Reach\Rest\NearestMembersController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use ReflectionClass;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Unit tests for {@see NearestMembersController}.
 *
 * The focus is the audit-exposure path: every PII view must be logged
 * to Scrutiny with the *requesting* visitor's anonymous name in the
 * detail field, never a raw email. The four branches under test are:
 *
 *  - happy path: requester is a known 12th-stepper member → anonymous name appears
 *  - intergroup-officer collision: matched member is not a 12th-stepper → 'unknown'
 *  - unknown visitor: no member matches the verified email → 'unknown'
 *  - unresolvable location: resolver short-circuits, no audit rows written
 *
 * Dependencies are constructed for real where they are cheap and final
 * (Settings, AttemptTokenMinter, ResponsivenessScorer, NearestMembersResolver),
 * faked where they are interfaces (AuditLogger, CallAttemptRepository,
 * Geocoder, MemberRepository), and injected via reflection where the
 * class is final and the constructor would otherwise require setting
 * up a real signed cookie (CurrentSession). The reflection trick is
 * isolated to a single helper at the bottom of this file.
 */
final class NearestMembersControllerTest extends TestCase
{
    public function testHappySnapshotIncludesRequesterAnonymousNameInAuditDetail(): void
    {
        $requester = $this->stubMember(
            id: 1, name: 'Alice K.', twelfth: true,
            email: 'alice@example.com', area: 'BS1 1AA',
        );
        $exposedA = $this->stubMember(
            id: 2, name: 'Bob T.', twelfth: true,
            email: 'bob@example.com', area: 'BS1 1AB',
        );
        $exposedB = $this->stubMember(
            id: 3, name: 'Carol M.', twelfth: true,
            email: 'carol@example.com', area: 'BS1 1AC',
        );

        $audit = new RecordingAuditLogger();
        $response = $this->controllerWith(
            members: [$requester, $exposedA, $exposedB],
            audit: $audit,
            sessionEmail: 'alice@example.com',
        )->getNearest($this->request('BS1', limit: 10));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        // Three members in range (requester is a 12th-stepper in this
        // fixture and is also in the result set) × one audited field
        // each (mobile_number) = three audit-log rows. area + accepts
        // are filter criteria the caller already supplied, and
        // personal_email is not exposed by Reach at all, so neither
        // is in the audited-fields list.
        $this->assertCount(3, $audit->entries);

        // Every row must carry the viewer's *anonymous name*, never
        // their email, in the structured format the Scrutiny admin
        // parses into a "Caller: <name>" link.
        foreach ($audit->entries as $entry) {
            $this->assertSame('view', $entry['action']);
            $this->assertSame('member', $entry['entityType']);
            $this->assertSame(
                'caller:Alice K.#1',
                $entry['detail'],
                'Audit detail must record viewer by anonymous name + id only',
            );
            $this->assertStringNotContainsString(
                'alice@example.com',
                $entry['detail'],
                'Raw email must never leak into the audit detail',
            );
        }

        // Sanity: every audited row is the mobile_number field, one
        // per member exposed.
        $perMember = [];
        foreach ($audit->entries as $entry) {
            $perMember[$entry['entityId']][] = $entry['fieldName'];
        }
        foreach ($perMember as $id => $fields) {
            $this->assertSame(
                ['mobile_number'],
                $fields,
                "Member #$id should have exactly one PII-field audit row",
            );
        }
    }

    public function testNonTwelfthStepperViewerIsStillNamed(): void
    {
        // The verified email matches a non-12th-step member (e.g. an
        // intergroup officer using Reach under the
        // requireScrutinyCapability flag). The audit row should name
        // them under their anonymous name — there is no 12th-stepper
        // gate on the viewer-resolution step, mirroring the call-
        // attempt audit so the same person appears under the same
        // identifier across the search → call lifecycle. The raw
        // email still never leaks.
        $officer = $this->stubMember(
            id: 1, name: 'Intergroup Officer', twelfth: false,
            email: 'officer@example.com', area: 'BS1 1AA',
        );
        $exposed = $this->stubMember(
            id: 2, name: 'Bob T.', twelfth: true,
            email: 'bob@example.com', area: 'BS1 1AB',
        );

        $audit = new RecordingAuditLogger();
        $this->controllerWith(
            members: [$officer, $exposed],
            audit: $audit,
            sessionEmail: 'officer@example.com',
        )->getNearest($this->request('BS1', limit: 10));

        $this->assertNotEmpty($audit->entries);
        foreach ($audit->entries as $entry) {
            $this->assertSame(
                'caller:Intergroup Officer#1',
                $entry['detail'],
            );
            $this->assertStringNotContainsString('officer@', $entry['detail']);
        }
    }

    public function testUnknownEmailIsRecordedAsUnknownNotLeaked(): void
    {
        // The Reach session is valid (the OAuth provider verified the
        // email) but no Unity member record matches. The audit row
        // must not contain the unmatched email anywhere in detail.
        $exposed = $this->stubMember(
            id: 2, name: 'Bob T.', twelfth: true,
            email: 'bob@example.com', area: 'BS1 1AB',
        );

        $audit = new RecordingAuditLogger();
        $this->controllerWith(
            members: [$exposed],
            audit: $audit,
            sessionEmail: 'stranger@example.com',
        )->getNearest($this->request('BS1', limit: 10));

        $this->assertNotEmpty($audit->entries);
        foreach ($audit->entries as $entry) {
            $this->assertSame(
                'caller:unknown',
                $entry['detail'],
            );
            $this->assertStringNotContainsString(
                'stranger@example.com',
                $entry['detail'],
                'Unmatched email must never leak into the audit detail',
            );
        }
    }

    public function testUnresolvableLocationSkipsAuditEntirely(): void
    {
        // When the resolver short-circuits with an unresolvable
        // location, no PII is exposed in the response and therefore
        // no audit row should be written. (This also protects against
        // accidentally introducing a leak in a future refactor where
        // the audit step gets pulled in front of the location check.)
        $exposed = $this->stubMember(
            id: 2, name: 'Bob T.', twelfth: true,
            email: 'bob@example.com', area: 'BS1 1AB',
        );

        $audit = new RecordingAuditLogger();
        $result = $this->controllerWith(
            members: [$exposed],
            audit: $audit,
            sessionEmail: 'alice@example.com',
        )->getNearest($this->request('nowhere', limit: 10));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('reach_unresolvable_location', $result->get_error_code());
        $this->assertCount(0, $audit->entries, 'No audit rows on unresolvable location');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<int, Member> $members
     */
    private function controllerWith(
        array $members,
        AuditLogger $audit,
        string $sessionEmail,
    ): NearestMembersController {
        $repo = new ControllerMemberRepository($members);
        // The stub geocoder knows the search origin 'BS1' and every
        // member's area string. 'nowhere' is deliberately absent so
        // testUnresolvableLocation can exercise the failure branch.
        $geo = new ControllerStubGeocoder([
            'BS1'      => new Coordinates(51.45, -2.58),
            'BS1 1AA'  => new Coordinates(51.46, -2.58),
            'BS1 1AB'  => new Coordinates(51.47, -2.58),
            'BS1 1AC'  => new Coordinates(51.48, -2.58),
        ]);

        return new NearestMembersController(
            new NearestMembersResolver($repo, $geo),
            $audit,
            $this->sessionWithEmail($sessionEmail),
            new Settings(),
            new NoopCallAttemptRepository(),
            new ResponsivenessScorer(),
            new AttemptTokenMinter(),
            $repo,
        );
    }

    private function request(string $location, int $limit = 10): WP_REST_Request
    {
        return new WP_REST_Request([
            'location' => $location,
            'accepts'  => [],
            'limit'    => $limit,
        ]);
    }

    /**
     * Build a CurrentSession that already holds a cached Session for
     * the given email, without going through cookie HMAC verification.
     *
     * The CurrentSession class is final and its cached state is
     * private, so reflection is the cleanest way in. The alternative
     * — minting a real signed cookie and putting it in $_COOKIE —
     * would test the cookie path, which is the SessionCookie unit
     * test's job, not this one's.
     */
    private function sessionWithEmail(string $email): CurrentSession
    {
        $current = new CurrentSession(new SessionCookie());

        $session = new Session(
            email:     $email,
            provider:  'google',
            sub:       'oauth-sub-' . md5($email),
            issuedAt:  time(),
            expiresAt: time() + 3600,
        );

        $ref = new ReflectionClass($current);
        $cached = $ref->getProperty('cached');
        $cached->setAccessible(true);
        $cached->setValue($current, $session);
        $resolved = $ref->getProperty('resolved');
        $resolved->setAccessible(true);
        $resolved->setValue($current, true);

        return $current;
    }

    private function stubMember(
        int $id,
        string $name,
        bool $twelfth,
        string $email,
        string $area,
    ): Member {
        return new class($id, $name, $twelfth, $email, $area) implements Member {
            public function __construct(
                private int $id, private string $name, private bool $twelfth,
                private string $email, private string $area,
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
            public function getPersonalEmail(): string { return $this->email; }
            public function getMobileNumber(): string { return '+44 7700 900000'; }
            public function isTwelfthStepper(): bool { return $this->twelfth; }
            public function isTelephoneResponder(): bool { return false; }
            public function getArea(): string { return $this->area; }
            public function getAccepts(): array { return ['phone']; }
            public function isGdprAccepted(): bool { return true; }
            public function getGdprAcceptedAt(): string { return ''; }
            public function getGdprAcceptanceVersion(): string { return ''; }
            public function getGdprAcceptanceMethod(): string { return ''; }
            public function getGdprAcceptanceStatement(): string { return ''; }
            public function getUpdated(): string { return ''; }
        };
    }
}

/**
 * Captures every audit-log call for later inspection.
 *
 * Implements both log() and logBatch() faithfully — logBatch fans out
 * into per-field rows the same way the production GdprAuditLogger does,
 * so tests can assert on the field-level entries directly.
 */
final class RecordingAuditLogger implements AuditLogger
{
    /** @var array<int, array{action: string, entityType: string, entityId: int, fieldName: string, detail: string}> */
    public array $entries = [];

    public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ''): void
    {
        $this->entries[] = compact('action', 'entityType', 'entityId', 'fieldName', 'detail');
    }

    public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ''): void
    {
        foreach ($fieldNames as $fieldName) {
            $this->log($action, $entityType, $entityId, $fieldName, $detail);
        }
    }
}

/**
 * Test fake of MemberRepository. Distinct from
 * Reach\Tests\InMemoryMemberRepository (the one in
 * NearestMembersResolverTest) so the two files can coexist in the
 * same suite without a class-redeclaration collision.
 */
final class ControllerMemberRepository implements MemberRepository
{
    /** @param array<int, Member> $members */
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

/**
 * Minimal CallAttemptRepository fake. The controller calls
 * forMembersSince() to feed the ResponsivenessScorer; an empty result
 * is fine — no test here exercises responsiveness badging.
 */
final class NoopCallAttemptRepository implements CallAttemptRepository
{
    public function record(
        int $memberId,
        string $viewerEmail,
        string $viewerProvider,
        string $outcome,
        ?string $note,
        int $now,
    ): CallAttempt {
        // Not exercised by these tests; throw if it ever gets hit so
        // the misuse is loud rather than silent.
        throw new \LogicException('NoopCallAttemptRepository::record() should not be called in NearestMembers tests');
    }
    public function forMembersSince(array $memberIds, int $sinceSeconds, int $now): array
    {
        return [];
    }
    public function list(array $filters, int $limit, int $offset): array
    {
        return [];
    }
    public function countWhere(array $filters): int { return 0; }
    public function findById(int $id): ?CallAttempt { return null; }
}

/**
 * Test fake of Geocoder. Distinct name from NearestMembersResolverTest's
 * StubGeocoder so the two files coexist without redeclaration.
 */
final class ControllerStubGeocoder implements Geocoder
{
    /** @param array<string, Coordinates> $entries */
    public function __construct(private array $entries) {}
    public function geocode(string $area): ?Coordinates
    {
        return $this->entries[$area] ?? null;
    }
}
