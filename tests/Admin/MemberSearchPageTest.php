<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reach\Admin\MemberSearchPage;
use Reach\Geocoding\Coordinates;
use Reach\Resolution\NearestMembersResolver;
use Reach\Tests\Fixtures\FakeMemberViewFactory;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\Fixtures\MemberViewStub;
use Reach\Tests\Fixtures\StubGeocoder;
use Reach\Tests\ReachTestCase;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberView;
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
 *
 * @covers \Reach\Admin\MemberSearchPage
 */
final class MemberSearchPageTest extends ReachTestCase
{
    /** A number that is clearly not anyone's: Ofcom's drama range. */
    private const FAKE_MOBILE = '07700 900123';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function register_hooks_the_admin_menu(): void
    {
        $this->page()->register();

        $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
    }

    /** @test */
    public function add_menu_attaches_under_the_reach_menu_behind_the_personal_data_capability(): void
    {
        $this->page()->addMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('submenu', WpState::$menus[0]['type']);
        $this->assertSame('reach', WpState::$menus[0]['parent']);
        $this->assertSame(MemberSearchPage::PAGE_SLUG, WpState::$menus[0]['slug']);
        $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
    }

    // ── capability guard ──────────────────────────────────────────────

    /**
     * The point of the gate: a search surfaces mobile numbers, so revoking
     * Scrutiny's personal-data capability has to close the screen even for a
     * user who can otherwise do everything.
     *
     * @test
     */
    public function the_search_renders_nothing_without_the_personal_data_capability(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(members: [$this->twelfthStepper()]));

        $this->assertSame('', $html);
        $this->assertStringNotContainsString(self::FAKE_MOBILE, $html);
    }

    /** @test */
    public function the_search_renders_for_a_user_who_lacks_manage_options(): void
    {
        WpState::$deniedCaps = ['manage_options'];

        $this->assertStringContainsString('Find a 12th Stepper', $this->render($this->page()));
    }

    // ── the search form ───────────────────────────────────────────────

    /** @test */
    public function an_empty_screen_shows_the_form_and_runs_no_search(): void
    {
        $html = $this->render($this->page(members: [$this->twelfthStepper()]));

        $this->assertStringContainsString('name="location"', $html);
        $this->assertStringContainsString('value="' . MemberSearchPage::PAGE_SLUG . '"', $html);
        $this->assertStringNotContainsString('wp-list-table', $html, 'no search, no results table');
        $this->assertStringNotContainsString(self::FAKE_MOBILE, $html);
    }

    /** @test */
    public function the_form_offers_the_three_gender_filters_by_their_stored_option_values(): void
    {
        $html = $this->render($this->page());

        // The stored ACF option value, not the label — sending "Male" matches
        // nothing, because the resolver does not strip the accepts- prefix.
        foreach (['accepts-male', 'accepts-female', 'accepts-non-binary'] as $value) {
            $this->assertStringContainsString('value="' . $value . '"', $html);
        }
        $this->assertStringContainsString('Non-Binary', $html);
    }

    /** @test */
    public function the_submitted_search_is_echoed_back_into_the_form(): void
    {
        $_GET = ['location' => 'Bedminster', 'accepts' => ['accepts-female']];

        $html = $this->render($this->page());

        $this->assertStringContainsString('value="Bedminster"', $html);
        $this->assertMatchesRegularExpression('/value="accepts-female"\s+checked="checked"/', $html);
        $this->assertStringNotContainsString('value="accepts-male"' . "\n" . ' checked', $html);
    }

    /**
     * @test
     * @dataProvider unusableAccepts
     */
    public function an_accepts_value_that_is_not_one_we_offer_is_dropped(mixed $raw): void
    {
        $_GET = ['location' => 'BS1', 'accepts' => $raw];

        // Every fixture member accepts men only. If an unusable filter value
        // reached the resolver it would match nobody, so the woman-only member
        // coming back proves the filter was dropped rather than applied.
        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7, accepts: ['accepts-male'])],
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
        ));

        $this->assertStringContainsString('Bob T.', $html);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableAccepts(): array
    {
        return [
            'a label rather than an option value' => [['Male']],
            'a value we do not offer'             => [['accepts-other']],
            'an empty string'                     => [['']],
            'not a list at all'                   => ['accepts-male'],
            'a nested array'                      => [[['accepts-male']]],
        ];
    }

    /** @test */
    public function a_gender_filter_that_we_do_offer_is_applied(): void
    {
        $_GET = ['location' => 'BS1', 'accepts' => ['accepts-female']];

        $html = $this->render($this->page(
            members: [
                $this->twelfthStepper(id: 7, area: 'BS1 1AA', accepts: ['accepts-male']),
                $this->twelfthStepper(id: 8, area: 'BS1 1AB', accepts: ['accepts-female']),
            ],
            views: [
                new MemberViewStub(id: 7, anonymousName: 'Bob T.'),
                new MemberViewStub(id: 8, anonymousName: 'Carol M.'),
            ],
        ));

        $this->assertStringContainsString('Carol M.', $html);
        $this->assertStringNotContainsString('Bob T.', $html);
    }

    // ── results ───────────────────────────────────────────────────────

    /** @test */
    public function an_unresolvable_area_says_so_instead_of_an_empty_table(): void
    {
        $_GET = ['location' => 'Atlantis'];

        $html = $this->render($this->page(members: [$this->twelfthStepper()]));

        $this->assertStringContainsString('Could not find the area', $html);
        $this->assertStringContainsString('Atlantis', $html);
        $this->assertStringNotContainsString('wp-list-table', $html);
    }

    /** @test */
    public function a_resolved_area_with_no_matching_members_says_so(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(members: []));

        $this->assertStringContainsString('No 12th-steppers match this search.', $html);
    }

    /** @test */
    public function a_result_row_carries_the_name_area_distance_accepts_and_number(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7, area: 'BS1 1AA', accepts: ['accepts-male'])],
            views: [new MemberViewStub(
                id: 7,
                anonymousName: 'Bob T.',
                mobileNumber: self::FAKE_MOBILE,
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
            '<a href="tel:' . str_replace(' ', '%20', self::FAKE_MOBILE) . '">' . self::FAKE_MOBILE . '</a>',
            $html
        );
        $this->assertMatchesRegularExpression('/\d+\.\d km/', $html, 'distance is shown to one decimal place');
    }

    /**
     * @test
     * @dataProvider resultCounts
     * @param array<int, Member>     $members
     * @param array<int, MemberView> $views
     */
    public function the_result_count_agrees_with_itself(array $members, array $views, string $expected): void
    {
        $_GET = ['location' => 'BS1'];

        $this->assertMatchesRegularExpression($expected, $this->render($this->page($members, $views)));
    }

    /** @return array<string, array{0: array<int, Member>, 1: array<int, MemberView>, 2: string}> */
    public static function resultCounts(): array
    {
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
    }

    /**
     * A member covering several neighbourhoods stores them pipe-separated. The
     * area column has to show the entry the reported distance belongs to, not
     * the raw field — otherwise the row reads "Kingswood|Hanham, 2.1 km" and
     * the number belongs to neither.
     *
     * @test
     */
    public function the_area_shown_is_the_one_the_distance_was_measured_to(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7, area: 'Kingswood|Hanham')],
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', area: 'Kingswood|Hanham')],
            places: [
                'BS1'       => new Coordinates(51.45, -2.58),
                'Kingswood' => new Coordinates(51.90, -2.58),
                // Nearer to the origin, so this is the entry that wins.
                'Hanham'    => new Coordinates(51.46, -2.58),
            ],
        ));

        $this->assertStringContainsString('>Hanham</td>', $this->normalise($html));
        $this->assertStringNotContainsString('Kingswood|Hanham', $html);
    }

    /**
     * The defensive arm of the distance cell: a view the resolver never scored
     * gets a dash rather than a distance belonging to somebody else.
     *
     * @test
     */
    public function a_view_the_resolver_never_scored_shows_no_distance(): void
    {
        $_GET = ['location' => 'BS1'];

        $page = new MemberSearchPage(
            $this->resolver([$this->twelfthStepper(id: 7, area: 'BS1 1AA')]),
            new FakeMemberViewFactory(
                [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
                [new MemberViewStub(id: 99, anonymousName: 'Nobody Asked')],
            ),
        );

        $html = $this->normalise($this->render($page));

        $this->assertStringContainsString('Nobody Asked', $html);
        $this->assertStringContainsString('nowrap;"> &mdash; </td>', $html);
        // The member who *was* scored still gets a real distance.
        $this->assertMatchesRegularExpression('/\d+\.\d km/', $html);
    }

    // ── individual cells ──────────────────────────────────────────────

    /** @test */
    public function a_member_with_no_anonymous_name_is_labelled_rather_than_blank(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7)],
            views: [new MemberViewStub(id: 7, anonymousName: '  ')],
        ));

        $this->assertStringContainsString('(no name)', $html);
    }

    /**
     * get_edit_post_link() answers null when the current user cannot edit the
     * member. The name still has to appear — as plain text rather than a link
     * that would only lead to a permissions error.
     *
     * @test
     */
    public function a_member_the_admin_cannot_edit_is_named_without_a_link(): void
    {
        $_GET = ['location' => 'BS1'];
        Functions\when('get_edit_post_link')->justReturn(null);

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7)],
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.')],
        ));

        $this->assertStringContainsString('<td>Bob T.</td>', $this->normalise($html));
        $this->assertStringNotContainsString('<a href="https://example.test/wp-admin/post.php', $html);
    }

    /** @test */
    public function a_member_with_no_number_on_file_shows_a_dash_not_an_empty_link(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7)],
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', mobileNumber: '   ')],
        ));

        $this->assertStringContainsString('<em>&mdash;</em>', $html);
        $this->assertStringNotContainsString('tel:', $html);
    }

    /**
     * @test
     * @dataProvider acceptsLists
     * @param array<int, string> $accepts
     */
    public function the_accepts_column_reads_as_labels(array $accepts, string $expected): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7)],
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', accepts: $accepts)],
        ));

        $this->assertStringContainsString('<td>' . $expected . '</td>', $this->normalise($html));
    }

    /**
     * The accepts list comes back from ACF, which types nothing: a checkbox
     * field edited by hand or migrated badly can hold anything. The cell skips
     * what it cannot read rather than fataling on it.
     *
     * @test
     */
    public function a_non_string_in_the_accepts_list_is_skipped(): void
    {
        $_GET = ['location' => 'BS1'];

        $html = $this->render($this->page(
            members: [$this->twelfthStepper(id: 7)],
            // @phpstan-ignore-next-line — deliberately malformed, as ACF allows.
            views: [new MemberViewStub(id: 7, anonymousName: 'Bob T.', accepts: [123, 'accepts-male'])],
        ));

        $this->assertStringContainsString('<td>Male</td>', $this->normalise($html));
    }

    /** @return array<string, array{0: array<int, string>, 1: string}> */
    public static function acceptsLists(): array
    {
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
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @param array<int, Member>            $members
     * @param array<int, MemberView>        $views
     * @param array<string, Coordinates>|null $places
     */
    private function page(array $members = [], array $views = [], ?array $places = null): MemberSearchPage
    {
        return new MemberSearchPage(
            $this->resolver($members, $places),
            new FakeMemberViewFactory($views),
        );
    }

    /**
     * @param array<int, Member>              $members
     * @param array<string, Coordinates>|null $places
     */
    private function resolver(array $members, ?array $places = null): NearestMembersResolver
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
    private function twelfthStepper(int $id = 7, string $area = 'BS1 1AA', array $accepts = []): Member
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
    private function normalise(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    private function render(MemberSearchPage $page): string
    {
        ob_start();
        try {
            $page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
