<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Reach\Admin\CallAttemptsPage;
use Reach\CallAttempts\CallAttempt;
use Reach\Tests\Fixtures\FakeMemberViewFactory;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\Fixtures\MemberViewStub;
use Reach\Tests\Fixtures\RecordingCallAttemptRepository;
use Reach\Tests\ReachTestCase;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Tests for the call-attempts admin screens.
 *
 * src/Admin was excluded from Reach's coverage denominator until now, on the
 * grounds that admin screens are render/menu glue exercised through wp-admin
 * at runtime. Amber covers its whole src/Admin on the same tooling, so the
 * exclusion was habit rather than necessity.
 *
 * Nothing on this page redirects or exits, so every method here runs for real:
 * registration against {@see WpState}, and the two render methods inside an
 * output buffer with the assertions made on the HTML they produced.
 *
 * What the assertions are actually about
 * --------------------------------------
 * Reach is the public 12th-step finder, so this screen is a personal-data
 * surface: it lists who called whom and links through to member records. The
 * capability tests therefore deny {@see PersonalDataPolicy::VIEW_CAPABILITY}
 * specifically, leaving every other capability granted — a page that gated on
 * `manage_options`, or on nothing, would pass a blanket "user can do nothing"
 * check and fail this one. They also assert the repository was never asked for
 * rows, because a guard that renders nothing after reading the data has still
 * read the data.
 *
 * @covers \Reach\Admin\CallAttemptsPage
 */
final class CallAttemptsPageTest extends ReachTestCase
{
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
    public function add_menu_registers_the_top_level_menu_and_both_submenus(): void
    {
        $this->page()->addMenu();

        $slugs = array_column(WpState::$menus, 'slug');

        $this->assertSame(
            [CallAttemptsPage::MENU_SLUG, CallAttemptsPage::MENU_SLUG, CallAttemptsPage::PAGE_SLUG . '-detail'],
            $slugs,
        );
        $this->assertSame('menu', WpState::$menus[0]['type'], 'Reach owns the top-level menu');
        $this->assertSame('submenu', WpState::$menus[1]['type']);
        // The detail view is registered with an empty parent so it has no
        // sidebar link — reachable by URL only.
        $this->assertSame('', WpState::$menus[2]['parent']);
    }

    /**
     * The menu is the first gate on the personal-data surface: registering any
     * of these under a weaker capability would show the screen in the sidebar
     * to someone whose personal-data access has been revoked.
     *
     * @test
     */
    public function every_menu_entry_is_gated_on_the_personal_data_capability(): void
    {
        $this->page()->addMenu();

        foreach (WpState::$menus as $menu) {
            $this->assertSame(
                PersonalDataPolicy::VIEW_CAPABILITY,
                $menu['cap'],
                $menu['slug'] . ' should require the personal-data capability',
            );
        }
    }

    // ── capability guards ─────────────────────────────────────────────

    /**
     * @test
     * @dataProvider guardedScreens
     */
    public function a_screen_renders_nothing_without_the_personal_data_capability(string $method): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $attempts = new RecordingCallAttemptRepository([$this->attempt()]);

        $this->assertSame('', $this->render($this->page(attempts: $attempts), $method));
        $this->assertSame([], $attempts->listFilters, 'the guard must run before anything is read');
        $this->assertSame([], $attempts->countFilters);
    }

    /** @return array<string, array{0: string}> */
    public static function guardedScreens(): array
    {
        return [
            'list'   => ['renderList'],
            'detail' => ['renderDetail'],
        ];
    }

    /**
     * The guard has to be that capability and not merely "is an admin": a user
     * who can do everything except view personal data is exactly the case
     * Scrutiny's capability exists to describe.
     *
     * @test
     */
    public function the_list_renders_for_a_user_who_lacks_manage_options(): void
    {
        // The mirror of the test above: deny the neighbouring capability and
        // the screen must still render, so the guard is demonstrably reading
        // Scrutiny's capability rather than the generic administrator one.
        WpState::$deniedCaps = ['manage_options'];

        $html = $this->render($this->page(), 'renderList');

        $this->assertStringContainsString('Call attempts', $html);
    }

    // ── list rendering ────────────────────────────────────────────────

    /** @test */
    public function an_empty_list_says_so_rather_than_rendering_an_empty_table(): void
    {
        $html = $this->render($this->page(), 'renderList');

        $this->assertStringContainsString('No call attempts match these filters.', $html);
        $this->assertMatchesRegularExpression('/0\s+attempts match\./', $html);
    }

    /** @test */
    public function a_row_renders_its_time_responder_member_and_outcome(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([
                $this->attempt(id: 5, memberId: 7, email: 'responder@example.test', outcome: CallAttempt::OUTCOME_REACHED),
            ]),
            views: new FakeMemberViewFactory([
                new MemberViewStub(id: 7, anonymousName: 'Bob T.', area: 'Bedminster'),
            ]),
            members: new InMemoryMemberRepository([
                new MemberStub(personalEmail: 'responder@example.test', id: 3, anonymousName: 'Alice K.'),
            ]),
        );

        $html = $this->render($page, 'renderList');

        $this->assertStringContainsString(date('Y-m-d H:i', $this->createdAt()), $html);
        $this->assertStringContainsString('Alice K.', $html, 'the responder resolves to their anonymous name');
        $this->assertStringContainsString('>Bob T.</a> &middot; Bedminster', $html);
        $this->assertStringContainsString('Spoke', $html, 'outcomes render as labels, not stored values');
    }

    /**
     * The detail screen has no sidebar entry — it's registered with an empty
     * parent — so this link is the only way in. Without it, renderDetail() and
     * the note it alone displays are unreachable from the UI.
     *
     * @test
     */
    public function each_rows_time_links_to_that_attempts_detail_screen(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(id: 5)]),
        );

        $this->assertStringContainsString(
            '<a href="https://example.test/wp-admin/admin.php?'
                . 'page=' . CallAttemptsPage::PAGE_SLUG . '-detail&id=5">'
                . date('Y-m-d H:i', $this->createdAt())
                . '</a>',
            $this->render($page, 'renderList'),
        );
    }

    /**
     * @test
     * @dataProvider outcomes
     */
    public function each_stored_outcome_renders_under_its_label(string $outcome, string $label): void
    {
        $page = $this->page(attempts: new RecordingCallAttemptRepository([$this->attempt(outcome: $outcome)]));

        $this->assertStringContainsString($label, $this->render($page, 'renderList'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function outcomes(): array
    {
        return [
            'reached'      => [CallAttempt::OUTCOME_REACHED, 'Spoke'],
            'no answer'    => [CallAttempt::OUTCOME_NO_ANSWER, 'No answer'],
            'bad number'   => [CallAttempt::OUTCOME_WRONG_OR_BAD, 'Wrong / bad number'],
            // Anything the vocabulary has since dropped is shown verbatim
            // rather than blanked, so an orphaned row stays legible.
            'unrecognised' => ['something_else', 'something_else'],
        ];
    }

    /**
     * One factory call for the whole page, not one per row — the comment on
     * loadMemberViews() is the reason the batch entry point is used at all.
     *
     * @test
     */
    public function member_views_are_resolved_in_one_batched_call_with_ids_deduplicated(): void
    {
        $views = new FakeMemberViewFactory([new MemberViewStub(id: 7, anonymousName: 'Bob T.')]);

        $this->render($this->page(
            attempts: new RecordingCallAttemptRepository([
                $this->attempt(id: 1, memberId: 7),
                $this->attempt(id: 2, memberId: 7),
                $this->attempt(id: 3, memberId: 9),
            ]),
            views: $views,
        ), 'renderList');

        $this->assertCount(1, $views->calls);
        $this->assertSame([7, 9], $views->calls[0]);
    }

    /** @test */
    public function a_member_the_factory_could_not_resolve_is_marked_rather_than_left_blank(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(memberId: 404)]),
            views: new FakeMemberViewFactory(),
        );

        $this->assertStringContainsString('(member not found)', $this->render($page, 'renderList'));
    }

    /**
     * @test
     * @dataProvider memberCells
     */
    public function the_member_cell_falls_back_through_name_then_area(
        string $name,
        string $area,
        string $expected
    ): void {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(memberId: 7)]),
            views: new FakeMemberViewFactory([
                new MemberViewStub(id: 7, anonymousName: $name, area: $area),
            ]),
        );

        $this->assertStringContainsString($expected, $this->render($page, 'renderList'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function memberCells(): array
    {
        return [
            // Only the primary label is linked, so the area follows the </a>.
            'name and area'   => ['Bob T.', 'Bedminster', '>Bob T.</a> &middot; Bedminster'],
            'name only'       => ['Bob T.', '', 'Bob T.</a>'],
            'area only'       => ['', 'Bedminster', 'Bedminster</a>'],
            'neither'         => ['', '', '(no name)'],
            'whitespace only' => ['  ', '  ', '(no name)'],
        ];
    }

    /** @test */
    public function the_member_name_links_to_the_member_record(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(memberId: 7)]),
            views: new FakeMemberViewFactory([new MemberViewStub(id: 7, anonymousName: 'Bob T.')]),
        );

        $this->assertStringContainsString(
            '<a href="https://example.test/wp-admin/post.php?post=7&action=edit">Bob T.</a>',
            $this->render($page, 'renderList'),
        );
    }

    // ── the responder cell ────────────────────────────────────────────

    /** @test */
    public function an_unmatched_responder_email_is_shown_rather_than_blanked(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(email: 'stranger@example.test')]),
        );

        $this->assertStringContainsString('stranger@example.test', $this->render($page, 'renderList'));
    }

    /**
     * A member record with no anonymous name set — the cell falls back to the
     * email so the audit trail still names someone.
     *
     * @test
     */
    public function a_matched_responder_without_a_name_falls_back_to_their_email(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(email: 'responder@example.test')]),
            members: new InMemoryMemberRepository([
                new MemberStub(personalEmail: 'responder@example.test', id: 3, anonymousName: '  '),
            ]),
        );

        $html = $this->render($page, 'renderList');

        $this->assertStringContainsString(
            '<a href="https://example.test/wp-admin/post.php?post=3&action=edit">responder@example.test</a>',
            $html,
        );
    }

    /** @test */
    public function an_attempt_with_no_recorded_responder_renders_an_empty_cell(): void
    {
        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(email: '')]),
            members: new InMemoryMemberRepository([
                new MemberStub(personalEmail: 'responder@example.test', id: 3, anonymousName: 'Alice K.'),
            ]),
        );

        $this->assertStringNotContainsString('Alice K.', $this->render($page, 'renderList'));
    }

    /**
     * The memo is the reason the cell is built once and reused: a page of
     * fifty attempts by the same responder would otherwise be fifty
     * findByEmail() calls, each a get_post plus an ACF read.
     *
     * @test
     */
    public function repeat_responders_are_looked_up_once_per_render(): void
    {
        $members = Mockery::mock(MemberRepository::class);
        $members->shouldReceive('findByEmail')
            ->once()
            ->with('responder@example.test')
            ->andReturn(new MemberStub(personalEmail: 'responder@example.test', id: 3, anonymousName: 'Alice K.'));

        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([
                $this->attempt(id: 1, email: 'responder@example.test'),
                $this->attempt(id: 2, email: 'responder@example.test'),
                $this->attempt(id: 3, email: 'responder@example.test'),
            ]),
            members: $members,
        );

        $this->assertStringContainsString('Alice K.', $this->render($page, 'renderList'));
    }

    // ── filters ───────────────────────────────────────────────────────

    /** @test */
    public function no_query_string_sends_no_filters_and_asks_for_the_first_page(): void
    {
        $attempts = new RecordingCallAttemptRepository();

        $this->render($this->page(attempts: $attempts), 'renderList');

        $this->assertSame([[]], $attempts->listFilters);
        $this->assertSame([['limit' => 50, 'offset' => 0]], $attempts->paging);
    }

    /** @test */
    public function member_id_outcome_and_dates_are_converted_for_the_repository(): void
    {
        $_GET = [
            'member_id' => '7',
            'outcome'   => CallAttempt::OUTCOME_NO_ANSWER,
            'since'     => '2026-01-01',
            'until'     => '2026-01-31',
        ];

        $attempts = new RecordingCallAttemptRepository();
        $this->render($this->page(attempts: $attempts), 'renderList');

        $this->assertSame([
            'member_id' => 7,
            'outcome'   => CallAttempt::OUTCOME_NO_ANSWER,
            'since'     => strtotime('2026-01-01 00:00:00'),
            // The whole of the "until" day is included, not up to midnight.
            'until'     => strtotime('2026-01-31 23:59:59'),
        ], $attempts->listFilters[0]);
        $this->assertSame($attempts->listFilters[0], $attempts->countFilters[0], 'count and list must agree');
    }

    /**
     * Both name filters are applied client-side after render — the
     * call_attempts table has no anonymous-name column to filter on — so
     * forwarding either would filter on a column that does not exist.
     *
     * @test
     */
    public function the_two_name_filters_are_never_forwarded_to_the_repository(): void
    {
        $_GET = ['member' => 'Bob', 'responder' => 'Alice'];

        $attempts = new RecordingCallAttemptRepository();
        $html = $this->render($this->page(attempts: $attempts), 'renderList');

        $this->assertSame([], $attempts->listFilters[0]);
        // They still travel through the URL, so the form is repopulated on
        // reload and the inline script can reapply them.
        $this->assertStringContainsString('value="Bob"', $html);
        $this->assertStringContainsString('value="Alice"', $html);
    }

    /**
     * @test
     * @dataProvider droppedFilters
     * @param array<string, string> $query
     */
    public function an_unusable_filter_value_is_dropped_rather_than_queried(array $query): void
    {
        $_GET = $query;

        $attempts = new RecordingCallAttemptRepository();
        $this->render($this->page(attempts: $attempts), 'renderList');

        $this->assertSame([], $attempts->listFilters[0]);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function droppedFilters(): array
    {
        return [
            'an outcome outside the vocabulary' => [['outcome' => 'made_up']],
            'a blank outcome'                   => [['outcome' => '']],
            'a member id of zero'               => [['member_id' => '0']],
            'a negative member id'              => [['member_id' => '-3']],
            'an unparseable since date'         => [['since' => 'not-a-date']],
            'an unparseable until date'         => [['until' => 'not-a-date']],
        ];
    }

    /** @test */
    public function the_selected_outcome_is_marked_in_the_filter_dropdown(): void
    {
        $_GET = ['outcome' => CallAttempt::OUTCOME_WRONG_OR_BAD];

        $html = $this->render($this->page(), 'renderList');

        $this->assertMatchesRegularExpression(
            '/value="' . CallAttempt::OUTCOME_WRONG_OR_BAD . '"\s+selected="selected"/',
            $html,
        );
    }

    // ── the pager ─────────────────────────────────────────────────────

    /** @test */
    public function a_single_page_of_results_has_no_pager(): void
    {
        $html = $this->render($this->page(attempts: new RecordingCallAttemptRepository([], 50)), 'renderList');

        $this->assertStringNotContainsString('tablenav', $html);
    }

    /** @test */
    public function the_first_of_several_pages_offers_next_but_not_prev(): void
    {
        $html = $this->render($this->page(attempts: new RecordingCallAttemptRepository([], 120)), 'renderList');

        $this->assertStringContainsString('Page 1 of 3', $html);
        $this->assertStringContainsString('next-page', $html);
        $this->assertStringNotContainsString('prev-page', $html);
        $this->assertStringContainsString('120 items', $html);
    }

    /** @test */
    public function the_last_page_offers_prev_but_not_next(): void
    {
        $_GET = ['paged' => '3'];

        $html = $this->render($this->page(attempts: new RecordingCallAttemptRepository([], 120)), 'renderList');

        $this->assertStringContainsString('Page 3 of 3', $html);
        $this->assertStringContainsString('prev-page', $html);
        $this->assertStringNotContainsString('next-page', $html);
    }

    /** @test */
    public function pager_links_carry_the_current_filters(): void
    {
        $_GET = ['outcome' => CallAttempt::OUTCOME_REACHED, 'member_id' => '7'];

        $html = $this->render($this->page(attempts: new RecordingCallAttemptRepository([], 120)), 'renderList');

        $this->assertStringContainsString('member_id=7', $html);
        $this->assertStringContainsString('outcome=' . CallAttempt::OUTCOME_REACHED, $html);
        $this->assertStringContainsString('paged=2', $html);
    }

    /**
     * @test
     * @dataProvider pageNumbers
     */
    public function the_requested_page_is_clamped_to_a_real_page(string $paged, int $expectedOffset): void
    {
        $_GET = ['paged' => $paged];

        $attempts = new RecordingCallAttemptRepository([], 120);
        $this->render($this->page(attempts: $attempts), 'renderList');

        $this->assertSame($expectedOffset, $attempts->paging[0]['offset']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function pageNumbers(): array
    {
        return [
            'first page'  => ['1', 0],
            'second page' => ['2', 50],
            'zero'        => ['0', 0],
            'negative'    => ['-4', 0],
            'nonsense'    => ['nonsense', 0],
        ];
    }

    /**
     * A row count large enough to make the pager arithmetic silly is capped:
     * 1000 pages of 50 is as far as the links go.
     *
     * @test
     */
    public function an_absurd_total_is_capped_at_a_thousand_pages(): void
    {
        $html = $this->render(
            $this->page(attempts: new RecordingCallAttemptRepository([], 10_000_000)),
            'renderList',
        );

        $this->assertStringContainsString('Page 1 of 1000', $html);
    }

    // ── the detail screen ─────────────────────────────────────────────

    /** @test */
    public function the_detail_screen_says_so_when_the_attempt_is_gone(): void
    {
        $_GET = ['id' => '404'];

        $html = $this->render($this->page(), 'renderDetail');

        $this->assertStringContainsString('That call attempt could not be found.', $html);
        $this->assertStringContainsString('Back to call attempts', $html);
    }

    /** @test */
    public function the_detail_screen_with_no_id_at_all_looks_up_nothing(): void
    {
        $attempts = new RecordingCallAttemptRepository([$this->attempt(id: 5)]);

        $html = $this->render($this->page(attempts: $attempts), 'renderDetail');

        $this->assertStringContainsString('That call attempt could not be found.', $html);
    }

    /** @test */
    public function the_detail_screen_shows_everything_stored_about_an_attempt(): void
    {
        $_GET = ['id' => '5'];

        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([
                $this->attempt(
                    id: 5,
                    memberId: 7,
                    email: 'responder@example.test',
                    outcome: CallAttempt::OUTCOME_NO_ANSWER,
                    note: 'Rang twice, no reply.',
                ),
            ]),
            views: new FakeMemberViewFactory([new MemberViewStub(id: 7, anonymousName: 'Bob T.')]),
        );

        $html = $this->render($page, 'renderDetail');

        $this->assertStringContainsString('Bob T.', $html);
        $this->assertStringContainsString('responder@example.test', $html);
        $this->assertStringContainsString('No answer', $html);
        $this->assertStringContainsString('Rang twice, no reply.', $html);
        $this->assertStringContainsString(date('Y-m-d H:i', $this->createdAt()), $html);
    }

    /**
     * @test
     * @dataProvider emptyNotes
     */
    public function an_attempt_with_no_note_says_none_rather_than_rendering_an_empty_block(?string $note): void
    {
        $_GET = ['id' => '5'];

        $page = $this->page(
            attempts: new RecordingCallAttemptRepository([$this->attempt(id: 5, note: $note)]),
        );

        $html = $this->render($page, 'renderDetail');

        $this->assertStringContainsString('<em>None</em>', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }

    /** @return array<string, array{0: string|null}> */
    public static function emptyNotes(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function page(
        ?RecordingCallAttemptRepository $attempts = null,
        ?FakeMemberViewFactory $views = null,
        ?MemberRepository $members = null,
    ): CallAttemptsPage {
        return new CallAttemptsPage(
            $attempts ?? new RecordingCallAttemptRepository(),
            $views ?? new FakeMemberViewFactory(),
            $members ?? new InMemoryMemberRepository(),
        );
    }

    /**
     * Fixed so the rendered timestamp is stable. Deliberately not time(): a
     * "when" column that only passes on the day it was written is worse than
     * no assertion.
     */
    private function createdAt(): int
    {
        return (int) strtotime('2026-07-24 09:15:00 UTC');
    }

    private function attempt(
        int $id = 1,
        int $memberId = 7,
        string $email = 'responder@example.test',
        string $outcome = CallAttempt::OUTCOME_REACHED,
        ?string $note = null,
    ): CallAttempt {
        return new CallAttempt(
            id: $id,
            memberId: $memberId,
            viewerEmail: $email,
            viewerProvider: 'google',
            outcome: $outcome,
            note: $note,
            createdAt: $this->createdAt(),
        );
    }

    private function render(CallAttemptsPage $page, string $method): string
    {
        ob_start();
        try {
            $page->{$method}();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
