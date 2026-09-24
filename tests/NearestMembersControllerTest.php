<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\Geocoding\Coordinates;
use Reach\Geocoding\Geocoder;
use Reach\Resolution\NearestMembersResolver;
use Reach\Rest\NearestMembersController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Reach\Core\RateLimiter;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Members\PreferredContact;
use Unity\Members\ResponderCertification;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Reach\Tests\Fixtures\StubGeocoder;

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
 * (AttemptTokenMinter, ResponsivenessScorer, NearestMembersResolver),
 * faked where they are interfaces (AuditLogger, CallAttemptRepository,
 * Geocoder, MemberRepository), and injected via reflection where the
 * class is final and the constructor would otherwise require setting
 * up a real signed cookie (CurrentSession). The reflection trick is
 * isolated to a single helper at the bottom of this file.
 */

/** Numbers that are clearly nobody's: Ofcom's drama ranges. */
const FAKE_MOBILE = '07700 900123';

const FAKE_LANDLINE = '01632 960123';

/** The controller's own cap, read rather than restated. */
function searchCap(): int
{
    $constant = new \ReflectionClassConstant(NearestMembersController::class, 'SEARCH_MAX');

    return (int) $constant->getValue();
}

/**
 * Build a CurrentSession holding a signed-in session for $email.
 *
 * This used to reflect a Session straight into CurrentSession's
 * private cache, on the grounds that the cookie path was
 * SessionCookie's business rather than this test's. That is no
 * longer safe: resolving a session is now also an authorisation
 * decision, so a helper that skips the resolve hands the controller
 * a viewer who has never been past the eligibility gate — and these
 * tests would then pass whatever that gate did. A real signed
 * cookie costs one HMAC and exercises the decision for real.
 *
 * $members is the same repository the controller searches, so the
 * viewer resolves against the members already seeded for the test.
 * A viewer not among them is added, because a session whose email
 * matches no member is now refused outright and every test here is
 * about something else.
 */
function sessionWithEmail(string $email, InMemoryMemberRepository $members): CurrentSession
{
    $session = new Session(
        email:     $email,
        provider:  'google',
        sub:       'oauth-sub-' . md5($email),
        issuedAt:  time(),
        expiresAt: time() + 3600,
        id:        Session::newId(),
    );

    $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

    $viewer = $members->findByEmail($email);
    if ($viewer === null) {
        $members = new InMemoryMemberRepository(
            array_merge($members->findAll(), [new MemberStub($email)]),
        );
    }

    return new CurrentSession(new SessionCookie(), $members, new SessionRevocationList());
}

beforeEach(function () {
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<int, Member> $members
     */
    $this->controllerWith = function (
        array $members,
        AuditLogger $audit,
        string $sessionEmail,
    ): NearestMembersController {
        $repo = new InMemoryMemberRepository($members);
        // The stub geocoder knows the search origin 'BS1' and every
        // member's area string. 'nowhere' is deliberately absent so
        // testUnresolvableLocation can exercise the failure branch.
        $geo = new StubGeocoder([
            'BS1'      => new Coordinates(51.45, -2.58),
            'BS1 1AA'  => new Coordinates(51.46, -2.58),
            'BS1 1AB'  => new Coordinates(51.47, -2.58),
            'BS1 1AC'  => new Coordinates(51.48, -2.58),
        ]);

        return new NearestMembersController(
            new NearestMembersResolver($repo, $geo),
            $audit,
            sessionWithEmail($sessionEmail, $repo),
            new NoopCallAttemptRepository(),
            new ResponsivenessScorer(),
            new AttemptTokenMinter(),
            new RateLimiter(),
            new SessionCsrf(),
        );
    };

    $this->request = function (string $location, int $limit = 10): WP_REST_Request {
        return new WP_REST_Request([
            'location' => $location,
            'accepts'  => [],
            'limit'    => $limit,
        ]);
    };

    $this->stubMember = function (
        int $id,
        string $name,
        bool $twelfth,
        string $email,
        string $area,
        bool $responder = false,
        ResponderCertification $certification = ResponderCertification::None,
        string $mobile = '',
        string $landline = '',
        PreferredContact $preferredContact = PreferredContact::Mobile,
    ): Member {
        return new MemberStub(
            id: $id,
            anonymousName: $name,
            personalEmail: $email,
            twelfthStepper: $twelfth,
            area: $area,
            telephoneResponder: $responder,
            responderCertification: $certification,
            mobileNumber: $mobile,
            landlineNumber: $landline,
            preferredContact: $preferredContact,
        );
    };
});

test('happy snapshot includes requester anonymous name in audit detail', function () {
    $requester = ($this->stubMember)(
        id: 1,
        name: 'Alice K.',
        twelfth: true,
        email: 'alice@example.com',
        area: 'BS1 1AA',
    );
    $exposedA = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );
    $exposedB = ($this->stubMember)(
        id: 3,
        name: 'Carol M.',
        twelfth: true,
        email: 'carol@example.com',
        area: 'BS1 1AC',
    );

    $audit = new SpyAuditLogger();
    $response = ($this->controllerWith)(
        members: [$requester, $exposedA, $exposedB],
        audit: $audit,
        sessionEmail: 'alice@example.com',
    )->getNearest(($this->request)('BS1', limit: 10));

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
});

test('non twelfth stepper viewer is still named', function () {
    // The verified email matches a non-12th-step member (e.g. an
    // intergroup officer using Reach). The audit row should name
    // them under their anonymous name — there is no 12th-stepper
    // gate on the viewer-resolution step, mirroring the call-
    // attempt audit so the same person appears under the same
    // identifier across the search → call lifecycle. The raw
    // email still never leaks.
    // Not a 12th-stepper, but a certified telephone responder —
    // which is the other half of what OutreachEligibility admits,
    // and so the viewer this case is actually about. Written as a
    // plain non-12th-stepper until the eligibility gate began
    // refusing those outright.
    $officer = ($this->stubMember)(
        id: 1,
        name: 'Intergroup Officer',
        twelfth: false,
        email: 'officer@example.com',
        area: 'BS1 1AA',
        responder: true,
        certification: ResponderCertification::Certified,
    );
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );

    $audit = new SpyAuditLogger();
    ($this->controllerWith)(
        members: [$officer, $exposed],
        audit: $audit,
        sessionEmail: 'officer@example.com',
    )->getNearest(($this->request)('BS1', limit: 10));

    $this->assertNotEmpty($audit->entries);
    foreach ($audit->entries as $entry) {
        $this->assertSame(
            'caller:Intergroup Officer#1',
            $entry['detail'],
        );
        $this->assertStringNotContainsString('officer@', $entry['detail']);
    }
});

test('unnamed viewer is recorded as unknown not leaked', function () {
    // The viewer is an eligible member but carries no anonymous
    // name, so there is nothing to put in the audit row. It must
    // say so rather than falling back to their email.
    //
    // This was written as a session whose email matched no member
    // at all; CurrentSession now refuses that outright, so it can
    // no longer reach the audit step.
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );
    $stranger = ($this->stubMember)(
        id: 3,
        name: '',
        twelfth: true,
        email: 'stranger@example.com',
        area: 'BS1 1AC',
    );

    $audit = new SpyAuditLogger();
    ($this->controllerWith)(
        members: [$exposed, $stranger],
        audit: $audit,
        sessionEmail: 'stranger@example.com',
    )->getNearest(($this->request)('BS1', limit: 10));

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
});

test('unresolvable location skips audit entirely', function () {
    // When the resolver short-circuits with an unresolvable
    // location, no PII is exposed in the response and therefore
    // no audit row should be written. (This also protects against
    // accidentally introducing a leak in a future refactor where
    // the audit step gets pulled in front of the location check.)
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );

    $audit = new SpyAuditLogger();
    $result = ($this->controllerWith)(
        members: [$exposed],
        audit: $audit,
        sessionEmail: 'alice@example.com',
    )->getNearest(($this->request)('nowhere', limit: 10));

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('reach_unresolvable_location', $result->get_error_code());
    $this->assertCount(0, $audit->entries, 'No audit rows on unresolvable location');
});

/**
 * A caller ringing a 12th-stepper needs both numbers and to know
 * which one the member asked to be rung on — a landline held on the
 * member record but left out of the response is a number nobody can
 * dial.
 */
test('the response carries both numbers and the contact preference', function () {
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
        mobile: FAKE_MOBILE,
        landline: FAKE_LANDLINE,
        preferredContact: PreferredContact::Landline,
    );

    $response = ($this->controllerWith)(
        members: [$exposed],
        audit: new SpyAuditLogger(),
        sessionEmail: 'alice@example.com',
    )->getNearest(($this->request)('BS1', limit: 10));

    $this->assertInstanceOf(WP_REST_Response::class, $response);

    $member = $response->get_data()['members'][0];
    $this->assertSame(FAKE_MOBILE, $member['mobile_number']);
    $this->assertSame(FAKE_LANDLINE, $member['landline_number']);
    // The enum's own value, so the find page can order its call
    // buttons off the field without re-deciding the empty case.
    $this->assertSame('Landline', $member['preferred_contact']);
});

/**
 * The landline is audited as a PII view for the members who have
 * one and for nobody else: logging it for every member would double
 * the audit volume of a search while recording an exposure that
 * never happened.
 */
test('the landline is audited only for members who have one', function () {
    $withLandline = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
        mobile: FAKE_MOBILE,
        landline: FAKE_LANDLINE,
    );
    $mobileOnly = ($this->stubMember)(
        id: 3,
        name: 'Carol M.',
        twelfth: true,
        email: 'carol@example.com',
        area: 'BS1 1AC',
        mobile: FAKE_MOBILE,
    );

    $audit = new SpyAuditLogger();
    ($this->controllerWith)(
        members: [$withLandline, $mobileOnly],
        audit: $audit,
        sessionEmail: 'alice@example.com',
    )->getNearest(($this->request)('BS1', limit: 10));

    $perMember = [];
    foreach ($audit->entries as $entry) {
        $perMember[$entry['entityId']][] = $entry['fieldName'];
    }

    $this->assertSame(['mobile_number', 'landline_number'], $perMember[2] ?? []);
    $this->assertSame(['mobile_number'], $perMember[3] ?? []);
});

/**
 * The search cap is what stops one valid session copying the
 * directory. This is the only endpoint that hands out members'
 * phone numbers, and auditing records that it happened rather than
 * bounding how often it may.
 *
 * Driven through the real RateLimiter and its transient store, so
 * the assertion is about the endpoint refusing rather than about a
 * counter being incremented somewhere.
 */
test('refuses once the viewer exceeds the search cap', function () {
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );

    $controller = ($this->controllerWith)(
        members: [$exposed],
        audit: new SpyAuditLogger(),
        sessionEmail: 'alice@example.com',
    );

    $limit = searchCap();

    for ($i = 0; $i < $limit; $i++) {
        $allowed = $controller->getNearest(($this->request)('BS1', limit: 10));
        $this->assertNotInstanceOf(\WP_Error::class, $allowed, "search {$i} should be allowed");
    }

    $refused = $controller->getNearest(($this->request)('BS1', limit: 10));

    $this->assertInstanceOf(\WP_Error::class, $refused);
    $this->assertSame('reach_rate_limited', $refused->get_error_code());
    $this->assertSame(429, $refused->get_error_data()['status'] ?? null);
});

/**
 * The cap is per viewer, not per IP — behind a shared edge an IP cap
 * would penalise a whole intergroup for one abuser.
 */
test('the search cap is scoped to the viewer', function () {
    $exposed = ($this->stubMember)(
        id: 2,
        name: 'Bob T.',
        twelfth: true,
        email: 'bob@example.com',
        area: 'BS1 1AB',
    );

    $limit = searchCap();

    $greedy = ($this->controllerWith)(
        members: [$exposed],
        audit: new SpyAuditLogger(),
        sessionEmail: 'greedy@example.com',
    );
    for ($i = 0; $i <= $limit; $i++) {
        $greedy->getNearest(($this->request)('BS1', limit: 10));
    }

    // A different viewer, from the same "IP", is unaffected.
    $other = ($this->controllerWith)(
        members: [$exposed],
        audit: new SpyAuditLogger(),
        sessionEmail: 'innocent@example.com',
    );

    $this->assertNotInstanceOf(
        \WP_Error::class,
        $other->getNearest(($this->request)('BS1', limit: 10)),
    );
});

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
    public function countWhere(array $filters): int
    {
        return 0;
    }
    public function findById(int $id): ?CallAttempt
    {
        return null;
    }
}
