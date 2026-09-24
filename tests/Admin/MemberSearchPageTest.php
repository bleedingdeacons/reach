<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\MemberSearchPage;
use Reach\Geocoding\Coordinates;
use Reach\Resolution\NearestMembersResolver;
use Reach\Tests\Fixtures\FakeMemberViewFactory;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\Fixtures\MemberViewStub;
use Reach\Tests\Fixtures\StubGeocoder;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberView;
use Unity\Members\PreferredContact;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Tests for the admin "Find a 12th Stepper" screen.
 *
 * The screen neither redirects nor exits, so everything runs for real: the
 * whole render path goes through an output buffer and the assertions are made
 * on the HTML. The resolver is built for real too — it is final, and it is the
 * same object the public find page uses, which is the property this screen
 * exists to preserve. Only its two collaborators are doubled (an in-memory
 * member repository and a map-backed geocoder).
 *
 * This is the screen that puts mobile numbers on an admin's page, so it gets
 * the strictest capability assertions in this directory: denying
 * {@see PersonalDataPolicy::VIEW_CAPABILITY} alone, with everything else still
 * granted, must produce no output at all and in particular no number.
 *
 * Fixtures use example.test addresses and obviously-fake numbers throughout —
 * these screens are a personal-data surface and a realistic-looking phone
 * number in a committed test file is a liability, not a fixture.
 */

covers(\Reach\Admin\MemberSearchPage::class);

/** A number that is clearly not anyone's: Ofcom's drama range. */
const FAKE_MOBILE = '07700 900123';

/** The landline half of the same range. */
const FAKE_LANDLINE = '01632 960123';

/**
 * @param array<int, Member>              $members
 * @param array<string, Coordinates>|null $places
 */
function resolver(array $members, ?array $places = null): NearestMembersResolver
{
    // "Atlantis" is deliberately absent so the unresolvable branch has
    // something to fail on.
    return new NearestMembersResolver(
        new InMemoryMemberRepository($members),
        new StubGeocoder($places ?? [
            'BS1'      => new Coordinates(51.45, -2.58),
            'BS1 1AA'  => new Coordinates(51.46, -2.58),
            'BS1 1AB'  => new Coordinates(51.47, -2.58),
        ]),
    );
}

/** @param array<int, string> $accepts */
function twelfthStepper(int $id = 7, string $area = 'BS1 1AA', array $accepts = []): Member
{
    return new MemberStub(
        id: $id,
        anonymousName: 'Fixture ' . $id,
        area: $area,
        accepts: $accepts,
        twelfthStepper: true,
    );
}

/**
 * Collapse the whitespace the templates indent their cells with, so a
 * table-cell assertion can name the cell rather than its indentation.
 */
function normalise(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', $html);
}

beforeEach(function () {
    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @param array<int, Member>            $members
     * @param array<int, MemberView>        $views
     * @param array<string, Coordinates>|null $places
     */
    $this->page = function (array $members = [], array $views = [], ?array $places = null): MemberSearchPage {
        return new MemberSearchPage(
            resolver($members, $places),
            new FakeMemberViewFactory($views),
        );
    };

    $this->render = function (MemberSearchPage $page): string {
        ob_start();
        try {
            $page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    };

    $_GET = [];
});

afterEach(function () {
    $_GET = [];
});

// ── registration ──────────────────────────────────────────────────
test('register hooks the admin menu', function () {
    ($this->page)()->register();

    $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
});

test('add menu attaches under the reach menu behind the personal data capability', function () {
    ($this->page)()->addMenu();

    $this->assertCount(1, WpState::$menus);
    $this->assertSame('submenu', WpState::$menus[0]['type']);
    $this->assertSame('reach', WpState::$menus[0]['parent']);
    $this->assertSame(MemberSearchPage::PAGE_SLUG, WpState::$menus[0]['slug']);
    $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
});

// ── capability guard ──────────────────────────────────────────────
/**
 * The point of the gate: a search surfaces mobile numbers, so revoking
 * Scrutiny's personal-data capability has to close the screen even for a
 * user who can otherwise do everything.
 */
test('the search renders nothing without the personal data capability', function () {
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(members: [twelfthStepper()]));

    $this->assertSame('', $html);
    $this->assertStringNotContainsString(FAKE_MOBILE, $html);
});

test('the search renders for a user who lacks manage options', function () {
    WpState::$deniedCaps = ['manage_options'];

    $this->assertStringContainsString('Find a 12th Stepper', ($this->render)(($this->page)()));
});

// ── the search form ───────────────────────────────────────────────
test('an empty screen shows the form and runs no search', function () {
    $html = ($this->render)(($this->page)(members: [twelfthStepper()]));

    $this->assertStringContainsString('name="location"', $html);
    $this->assertStringContainsString('value="' . MemberSearchPage::PAGE_SLUG . '"', $html);
    $this->assertStringNotContainsString('wp-list-table', $html, 'no search, no results table');
    $this->assertStringNotContainsString(FAKE_MOBILE, $html);
});

test('the form offers the three gender filters by their stored option values', function () {
    $html = ($this->render)(($this->page)());

    // The stored ACF option value, not the label — sending "Male" matches
    // nothing, because the resolver does not strip the accepts- prefix.
    foreach (['accepts-male', 'accepts-female', 'accepts-non-binary'] as $value) {
        $this->assertStringContainsString('value="' . $value . '"', $html);
    }
    $this->assertStringContainsString('Non-Binary', $html);
});

test('the submitted search is echoed back into the form', function () {
    $_GET = ['location' => 'Bedminster', 'accepts' => ['accepts-female']];

    $html = ($this->render)(($this->page)());

    $this->assertStringContainsString('value="Bedminster"', $html);
    $this->assertMatchesRegularExpression('/value="accepts-female"\s+checked="checked"/', $html);
    $this->assertStringNotContainsString('value="accepts-male"' . "\n" . ' checked', $html);
});

test('an accepts value that is not one we offer is dropped', function (mixed $raw) {
    $_GET = ['location' => 'BS1', 'accepts' => $raw];

    // Every fixture member accepts men only. If an unusable filter value
    // reached the resolver it would match nobody, so the woman-only member
    // coming back proves the filter was dropped rather than applied.
    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7, accepts: ['accepts-male'])],
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
    ));

    $this->assertStringContainsString('Bob T.', $html);
})->with('unusableAccepts');

/** @return array<string, array{0: mixed}> */
dataset('unusableAccepts', function (): array {
    return [
        'a label rather than an option value' => [['Male']],
        'a value we do not offer'             => [['accepts-other']],
        'an empty string'                     => [['']],
        'not a list at all'                   => ['accepts-male'],
        'a nested array'                      => [[['accepts-male']]],
    ];
});

test('a gender filter that we do offer is applied', function () {
    $_GET = ['location' => 'BS1', 'accepts' => ['accepts-female']];

    $html = ($this->render)(($this->page)(
        members: [
            twelfthStepper(id: 7, area: 'BS1 1AA', accepts: ['accepts-male']),
            twelfthStepper(id: 8, area: 'BS1 1AB', accepts: ['accepts-female']),
        ],
        views: [
            new MemberViewStub(id: 7, anonymousName: 'Bob T.'),
            new MemberViewStub(id: 8, anonymousName: 'Carol M.'),
        ],
    ));

    $this->assertStringContainsString('Carol M.', $html);
    $this->assertStringNotContainsString('Bob T.', $html);
});

// ── results ───────────────────────────────────────────────────────
test('an unresolvable area says so instead of an empty table', function () {
    $_GET = ['location' => 'Atlantis'];

    $html = ($this->render)(($this->page)(members: [twelfthStepper()]));

    $this->assertStringContainsString('Could not find the area', $html);
    $this->assertStringContainsString('Atlantis', $html);
    $this->assertStringNotContainsString('wp-list-table', $html);
});

test('a resolved area with no matching members says so', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(members: []));

    $this->assertStringContainsString('No 12th-steppers match this search.', $html);
});

test('a result row carries the name area distance accepts and number', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7, area: 'BS1 1AA', accepts: ['accepts-male'])],
        views: [new MemberViewStub(
            id: 7,
            anonymousName: 'Bob T.',
            mobileNumber: FAKE_MOBILE,
            area: 'BS1 1AA',
            accepts: ['accepts-male'],
        )],
    ));

    $this->assertStringContainsString('>Bob T.</a>', $html);
    $this->assertStringContainsString('BS1 1AA', $html);
    $this->assertStringContainsString('Male', $html);
    // The href is percent-encoded and the link text is not: esc_url()
    // turns the space in the number into %20, while esc_html() leaves it
    // alone. Asserting the raw number in both positions described output
    // WordPress would never emit, and passed only while the test double
    // returned its input untouched.
    $this->assertStringContainsString(
        '<a href="tel:' . str_replace(' ', '%20', FAKE_MOBILE) . '">' . FAKE_MOBILE . '</a>',
        $html
    );
    $this->assertMatchesRegularExpression('/\d+\.\d km/', $html, 'distance is shown to one decimal place');
});

/**
 * @param array<int, Member>     $members
 * @param array<int, MemberView> $views
 */
test('the result count agrees with itself', function (array $members, array $views, string $expected) {
    $_GET = ['location' => 'BS1'];

    $this->assertMatchesRegularExpression($expected, ($this->render)(($this->page)($members, $views)));
})->with('resultCounts');

/** @return array<string, array{0: array<int, Member>, 1: array<int, MemberView>, 2: string}> */
dataset('resultCounts', function (): array {
    return [
        'none' => [[], [], '/0\s+12th-steppers found\./'],
        'one'  => [
            [new MemberStub(id: 7, anonymousName: 'Bob T.', area: 'BS1 1AA', twelfthStepper: true)],
            [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
            '/1\s+12th-stepper found\./',
        ],
        'several' => [
            [
                new MemberStub(id: 7, anonymousName: 'Bob T.', area: 'BS1 1AA', twelfthStepper: true),
                new MemberStub(id: 8, anonymousName: 'Carol M.', area: 'BS1 1AB', twelfthStepper: true),
            ],
            [
                new MemberViewStub(id: 7, anonymousName: 'Bob T.'),
                new MemberViewStub(id: 8, anonymousName: 'Carol M.'),
            ],
            '/2\s+12th-steppers found\./',
        ],
    ];
});

/**
 * A member covering several neighbourhoods stores them pipe-separated. The
 * area column has to show the entry the reported distance belongs to, not
 * the raw field — otherwise the row reads "Kingswood|Hanham, 2.1 km" and
 * the number belongs to neither.
 */
test('the area shown is the one the distance was measured to', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7, area: 'Kingswood|Hanham')],
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', area: 'Kingswood|Hanham')],
        places: [
            'BS1'       => new Coordinates(51.45, -2.58),
            'Kingswood' => new Coordinates(51.90, -2.58),
            // Nearer to the origin, so this is the entry that wins.
            'Hanham'    => new Coordinates(51.46, -2.58),
        ],
    ));

    $this->assertStringContainsString('>Hanham</td>', normalise($html));
    $this->assertStringNotContainsString('Kingswood|Hanham', $html);
});

/**
 * The defensive arm of the distance cell: a view the resolver never scored
 * gets a dash rather than a distance belonging to somebody else.
 */
test('a view the resolver never scored shows no distance', function () {
    $_GET = ['location' => 'BS1'];

    $page = new MemberSearchPage(
        resolver([twelfthStepper(id: 7, area: 'BS1 1AA')]),
        new FakeMemberViewFactory(
            [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
            [new MemberViewStub(id: 99, anonymousName: 'Nobody Asked')],
        ),
    );

    $html = normalise(($this->render)($page));

    $this->assertStringContainsString('Nobody Asked', $html);
    $this->assertStringContainsString('nowrap;"> &mdash; </td>', $html);
    // The member who *was* scored still gets a real distance.
    $this->assertMatchesRegularExpression('/\d+\.\d km/', $html);
});

// ── individual cells ──────────────────────────────────────────────
test('a member with no anonymous name is labelled rather than blank', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(id: 7, anonymousName: '  ')],
    ));

    $this->assertStringContainsString('(no name)', $html);
});

/**
 * get_edit_post_link() answers null when the current user cannot edit the
 * member. The name still has to appear — as plain text rather than a link
 * that would only lead to a permissions error.
 */
test('a member the admin cannot edit is named without a link', function () {
    $_GET = ['location' => 'BS1'];
    when('get_edit_post_link')->justReturn(null);

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
    ));

    $this->assertStringContainsString('<td>Bob T.</td>', normalise($html));
    $this->assertStringNotContainsString('<a href="https://example.test/wp-admin/post.php', $html);
});

test('a member with no number on file shows a dash not an empty link', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', mobileNumber: '   ')],
    ));

    $this->assertStringContainsString('<em>&mdash;</em>', $html);
    $this->assertStringNotContainsString('tel:', $html);
});

/**
 * The landline gets its own dialable column: a member who asked to be
 * rung at home is no use to an admin whose only column is the mobile.
 */
test('the landline is shown as a dialable number of its own', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(
            id: 7,
            anonymousName: 'Bob T.',
            landlineNumber: FAKE_LANDLINE,
        )],
    ));

    $this->assertStringContainsString('>Landline</th>', $html);
    $this->assertStringContainsString(
        '<a href="tel:' . str_replace(' ', '%20', FAKE_LANDLINE) . '">' . FAKE_LANDLINE . '</a>',
        $html
    );
});

test('the number the member asked to be rung on is tagged', function (
    PreferredContact $preference,
    string $taggedNumber,
    string $untaggedNumber,
) {
    $_GET = ['location' => 'BS1'];

    $html = normalise(($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(
            id: 7,
            anonymousName: 'Bob T.',
            mobileNumber: FAKE_MOBILE,
            landlineNumber: FAKE_LANDLINE,
            preferredContact: $preference,
        )],
    )));

    $this->assertStringContainsString(
        '>' . $taggedNumber . '</a> <span class="description">preferred</span>',
        $html
    );
    $this->assertStringNotContainsString(
        '>' . $untaggedNumber . '</a> <span class="description">preferred</span>',
        $html
    );
})->with('preferences');

/** @return array<string, array{0: PreferredContact, 1: string, 2: string}> */
dataset('preferences', function (): array {
    return [
        'prefers the mobile'   => [PreferredContact::Mobile, FAKE_MOBILE, FAKE_LANDLINE],
        'prefers the landline' => [PreferredContact::Landline, FAKE_LANDLINE, FAKE_MOBILE],
    ];
});

/**
 * With one number on file there is nothing to prefer it over, so the row
 * says nothing about the preference — including when the stored value
 * still says Landline for a member whose landline has since been deleted,
 * which ACF leaves behind because it keeps the last saved choice for a
 * field its conditional logic has hidden.
 */
test('one number on file is never tagged preferred', function (
    string $mobile,
    string $landline,
    PreferredContact $preference,
) {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(
            id: 7,
            anonymousName: 'Bob T.',
            mobileNumber: $mobile,
            landlineNumber: $landline,
            preferredContact: $preference,
        )],
    ));

    $this->assertStringNotContainsString('preferred', $html);
    $this->assertStringContainsString('<em>&mdash;</em>', $html, 'the missing number still shows a dash');
})->with('lonelyNumbers');

/** @return array<string, array{0: string, 1: string, 2: PreferredContact}> */
dataset('lonelyNumbers', function (): array {
    return [
        'mobile only'                     => [FAKE_MOBILE, '', PreferredContact::Mobile],
        'landline only'                   => ['', FAKE_LANDLINE, PreferredContact::Landline],
        'a preference for a deleted line' => [FAKE_MOBILE, '  ', PreferredContact::Landline],
    ];
});

/**
 * @param array<int, string> $accepts
 */
test('the accepts column reads as labels', function (array $accepts, string $expected) {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', accepts: $accepts)],
    ));

    $this->assertStringContainsString('<td>' . $expected . '</td>', normalise($html));
})->with('acceptsLists');

/**
 * The accepts list comes back from ACF, which types nothing: a checkbox
 * field edited by hand or migrated badly can hold anything. The cell skips
 * what it cannot read rather than fataling on it.
 */
test('a non string in the accepts list is skipped', function () {
    $_GET = ['location' => 'BS1'];

    $html = ($this->render)(($this->page)(
        members: [twelfthStepper(id: 7)],
        // @phpstan-ignore-next-line — deliberately malformed, as ACF allows.
        views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', accepts: [123, 'accepts-male'])],
    ));

    $this->assertStringContainsString('<td>Male</td>', normalise($html));
});

/** @return array<string, array{0: array<int, string>, 1: string}> */
dataset('acceptsLists', function (): array {
    return [
        'one'                  => [['accepts-male'], 'Male'],
        'several, in order'    => [['accepts-male', 'accepts-non-binary'], 'Male, Non-Binary'],
        // Unrecognised values are shown as stored rather than dropped, so
        // unexpected data stays visible to an admin.
        'an unknown value'     => [['accepts-alien'], 'accepts-alien'],
        'blanks are skipped'   => [['', '   ', 'accepts-female'], 'Female'],
        'nothing at all'       => [[], '—'],
        'nothing but blanks'   => [['  '], '—'],
    ];
});
