<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reach\Admin\CallRequestsPage;
use Reach\CallRequests\CallRequest;
use Reach\Tests\Fixtures\InMemoryCallRequestRepository;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\ReachTestCase;
use ReflectionMethod;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_User;

/**
 * Tests for the call-requests admin screen.
 *
 * Two of the three techniques the Integrity port documents are in play here:
 * the list view runs for real inside an output buffer, and the capability
 * guard on the Completed POST is a plain expectException because wp_die()
 * throws.
 *
 * The third is the exit wall. handleComplete() ends `wp_safe_redirect(); exit;`
 * and the stubs *record* redirects rather than throwing on them, so the exit
 * runs and takes the test runner with it. Everything branchy that used to sit
 * in front of that — parse the id, complete the row if it is still pending,
 * audit it, work out which page to go back to — now lives in the private
 * completeFromRequest(), which returns the redirect target instead of issuing
 * it. That is the only production change here and it is behaviour-identical;
 * the precedent is Integrity's parsePermissions()/parseIpWhitelist().
 *
 * The rows on this screen deliberately hold no caller personal data (the
 * caller's details are emailed and never stored), but the screen is still
 * gated on {@see PersonalDataPolicy::VIEW_CAPABILITY} to match the rest of
 * Reach's admin — so that is the capability the guards are tested against.
 *
 * @covers \Reach\Admin\CallRequestsPage
 */
final class CallRequestsPageTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function register_hooks_the_menu_and_the_completed_post_handler(): void
    {
        $this->page()->register();

        $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
        $this->assertActionAdded(
            'admin_post_reach_complete_call_request',
            false,
            'the Completed button posts to admin-post.php and needs its handler hooked',
        );
    }

    /** @test */
    public function add_menu_attaches_under_the_reach_menu_behind_the_personal_data_capability(): void
    {
        $this->page()->addMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('submenu', WpState::$menus[0]['type']);
        $this->assertSame('reach', WpState::$menus[0]['parent']);
        $this->assertSame(CallRequestsPage::PAGE_SLUG, WpState::$menus[0]['slug']);
        $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
    }

    // ── capability guards ─────────────────────────────────────────────

    /** @test */
    public function the_list_renders_nothing_without_the_personal_data_capability(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $repository = new InMemoryCallRequestRepository([$this->request()]);

        $this->assertSame('', $this->renderList($this->page(repository: $repository)));
        $this->assertSame([], $repository->paging, 'the guard must run before anything is read');
    }

    /** @test */
    public function completing_a_request_without_the_personal_data_capability_dies(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $_POST = ['id' => '5'];
        $repository = new InMemoryCallRequestRepository([$this->request(id: 5)]);

        try {
            $this->page(repository: $repository)->handleComplete();
            $this->fail('expected wp_die() for a user without the capability');
        } catch (WpDieException) {
            $this->assertSame([], $repository->completions, 'nothing may be completed behind the guard');
        }
    }

    // ── list rendering ────────────────────────────────────────────────

    /** @test */
    public function an_empty_list_says_so(): void
    {
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('No call requests yet.', $html);
        $this->assertMatchesRegularExpression('/0\s+requests pending/', $html);
    }

    /** @test */
    public function the_counts_separate_pending_from_the_total(): void
    {
        $page = $this->page(repository: new InMemoryCallRequestRepository([
            $this->request(id: 1),
            $this->request(id: 2),
            $this->request(id: 3, completedAt: $this->completedAt()),
        ]));

        $html = $this->renderList($page);

        $this->assertMatchesRegularExpression('/2\s+requests pending/', $html);
        $this->assertMatchesRegularExpression('/3 in total/', $html);
    }

    /** @test */
    public function a_single_pending_request_is_counted_in_the_singular(): void
    {
        $page = $this->page(repository: new InMemoryCallRequestRepository([$this->request()]));

        $this->assertMatchesRegularExpression('/1\s+request pending/', $this->renderList($page));
    }

    /** @test */
    public function a_pending_row_shows_its_reference_area_responder_and_a_completed_button(): void
    {
        $page = $this->page(repository: new InMemoryCallRequestRepository([
            $this->request(id: 5, responderName: 'Alice K.', area: 'Bedminster'),
        ]));

        $html = $this->renderList($page);

        $this->assertStringContainsString('CR-000005', $html);
        $this->assertStringContainsString('Alice K.', $html);
        $this->assertStringContainsString('Bedminster', $html);
        $this->assertStringContainsString(date('Y-m-d H:i', $this->createdAt()), $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('<button type="submit" class="button button-small button-primary">Completed</button>', $html);
        $this->assertStringContainsString('name="id" value="5"', $html);
    }

    /** @test */
    public function a_completed_row_names_who_closed_it_and_offers_no_button(): void
    {
        $page = $this->page(repository: new InMemoryCallRequestRepository([
            $this->request(id: 5, completedAt: $this->completedAt(), completedByName: 'Alice K.'),
        ]));

        $html = $this->renderList($page);

        $this->assertStringContainsString('Completed</span> by Alice K.', $html);
        $this->assertStringContainsString(date('Y-m-d H:i', $this->completedAt()), $html);
        $this->assertStringNotContainsString('<button', $html, 'history is not re-completable');
        $this->assertStringNotContainsString('Pending', $html);
    }

    /** @test */
    public function a_completed_row_with_no_recorded_name_says_unknown(): void
    {
        $page = $this->page(repository: new InMemoryCallRequestRepository([
            $this->request(id: 5, completedAt: $this->completedAt(), completedByName: '  '),
        ]));

        $this->assertStringContainsString('Completed</span> by <em>(unknown)</em>', $this->renderList($page));
    }

    /**
     * @test
     * @dataProvider blankCells
     */
    public function a_blank_responder_or_area_renders_a_placeholder(
        string $responderName,
        string $area,
        string $expected
    ): void {
        $page = $this->page(repository: new InMemoryCallRequestRepository([
            $this->request(responderName: $responderName, area: $area),
        ]));

        $this->assertStringContainsString($expected, $this->renderList($page));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function blankCells(): array
    {
        return [
            'no responder name' => ['', 'Bedminster', '<em>(unknown)</em>'],
            'whitespace name'   => ['   ', 'Bedminster', '<em>(unknown)</em>'],
            'no area'           => ['Alice K.', '', '<span aria-hidden="true">&mdash;</span>'],
            'whitespace area'   => ['Alice K.', '   ', '<span aria-hidden="true">&mdash;</span>'],
        ];
    }

    /** @test */
    public function the_completed_notice_shows_only_after_a_completion(): void
    {
        $this->assertStringNotContainsString('Request marked completed.', $this->renderList($this->page()));

        $_GET = ['completed' => '1'];

        $this->assertStringContainsString('Request marked completed.', $this->renderList($this->page()));
    }

    // ── the pager ─────────────────────────────────────────────────────

    /** @test */
    public function a_single_page_of_requests_has_no_pager(): void
    {
        $html = $this->renderList($this->page(repository: new InMemoryCallRequestRepository([], 50)));

        $this->assertStringNotContainsString('tablenav', $html);
    }

    /** @test */
    public function the_middle_of_several_pages_offers_both_directions(): void
    {
        $_GET = ['paged' => '2'];

        $html = $this->renderList($this->page(repository: new InMemoryCallRequestRepository([], 120)));

        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringContainsString('prev-page', $html);
        $this->assertStringContainsString('next-page', $html);
        $this->assertStringContainsString('120 items', $html);
    }

    /**
     * @test
     * @dataProvider pageNumbers
     */
    public function the_requested_page_is_clamped_to_a_real_page(string $paged, int $expectedOffset): void
    {
        $_GET = ['paged' => $paged];

        $repository = new InMemoryCallRequestRepository([], 120);
        $this->renderList($this->page(repository: $repository));

        $this->assertSame(
            [['limit' => 50, 'offset' => $expectedOffset]],
            $repository->paging,
        );
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function pageNumbers(): array
    {
        return [
            'first page'  => ['1', 0],
            'third page'  => ['3', 100],
            'zero'        => ['0', 0],
            'negative'    => ['-4', 0],
            'nonsense'    => ['nonsense', 0],
        ];
    }

    /** @test */
    public function an_absurd_total_is_capped_at_a_thousand_pages(): void
    {
        $html = $this->renderList($this->page(repository: new InMemoryCallRequestRepository([], 10_000_000)));

        $this->assertStringContainsString('Page 1 of 1000', $html);
    }

    // ── completing a request (reflection: the live caller exits) ───────

    /** @test */
    public function a_pending_request_is_completed_and_audited(): void
    {
        $_POST = ['id' => '5'];

        $repository = new InMemoryCallRequestRepository([
            $this->request(id: 5, viewerEmail: 'responder@example.test'),
        ]);
        $audit = new SpyAuditLogger();

        $this->completeFromRequest($this->page(
            repository: $repository,
            audit: $audit,
            members: [new MemberStub(personalEmail: 'responder@example.test', id: 42, anonymousName: 'Alice K.')],
        ));

        $this->assertCount(1, $repository->completions);
        $this->assertSame(5, $repository->completions[0]['id']);

        $this->assertCount(1, $audit->batches);
        $this->assertSame('complete', $audit->batches[0]['action']);
        $this->assertSame(AuditLogger::ENTITY_MEMBER, $audit->batches[0]['entityType']);
        $this->assertSame(42, $audit->batches[0]['entityId'], 'the entry anchors to the responder on the request');
        $this->assertSame([PersonalDataFields::MOBILE_NUMBER], $audit->batches[0]['fieldNames']);
        $this->assertSame('Reach call request CR-000005 completed', $audit->batches[0]['detail']);
    }

    /**
     * The detail carries a reference, never the caller — the whole point of
     * this table is that caller data lives in an inbox, not the database.
     *
     * @test
     */
    public function the_audit_detail_carries_no_email_address(): void
    {
        $_POST = ['id' => '5'];

        $audit = new SpyAuditLogger();
        $this->completeFromRequest($this->page(
            repository: new InMemoryCallRequestRepository([
                $this->request(id: 5, viewerEmail: 'responder@example.test'),
            ]),
            audit: $audit,
        ));

        $this->assertStringNotContainsString('@', $audit->batches[0]['detail']);
    }

    /** @test */
    public function a_request_whose_responder_is_unknown_is_audited_against_no_member(): void
    {
        $_POST = ['id' => '5'];

        $audit = new SpyAuditLogger();
        $this->completeFromRequest($this->page(
            repository: new InMemoryCallRequestRepository([$this->request(id: 5, viewerEmail: '')]),
            audit: $audit,
        ));

        $this->assertSame(0, $audit->batches[0]['entityId']);
    }

    /**
     * @test
     * @dataProvider nonCompletions
     * @param array<string, string> $post
     * @param array<int, CallRequest> $rows
     */
    public function nothing_is_completed_or_audited_when_there_is_no_pending_row(array $post, array $rows): void
    {
        $_POST = $post;

        $repository = new InMemoryCallRequestRepository($rows);
        $audit = new SpyAuditLogger();

        $this->completeFromRequest($this->page(repository: $repository, audit: $audit));

        $this->assertSame([], $repository->completions);
        $this->assertSame([], $audit->batches);
    }

    /** @return array<string, array{0: array<string, string>, 1: array<int, CallRequest>}> */
    public static function nonCompletions(): array
    {
        $pending = new CallRequest(
            id: 5,
            responderName: 'Alice K.',
            area: 'Bedminster',
            viewerEmail: 'responder@example.test',
            viewerProvider: 'google',
            createdAt: 1_753_348_500,
        );
        $completed = new CallRequest(
            id: 5,
            responderName: 'Alice K.',
            area: 'Bedminster',
            viewerEmail: 'responder@example.test',
            viewerProvider: 'google',
            createdAt: 1_753_348_500,
            completedAt: 1_753_352_100,
            completedByMemberId: 42,
            completedByName: 'Alice K.',
        );

        return [
            'no id posted'         => [[], [$pending]],
            'a zero id'            => [['id' => '0'], [$pending]],
            'a non-numeric id'     => [['id' => 'nonsense'], [$pending]],
            'an id nobody has'     => [['id' => '404'], [$pending]],
            'an already-done row'  => [['id' => '5'], [$completed]],
        ];
    }

    // ── who actioned it ───────────────────────────────────────────────

    /** @test */
    public function the_acting_admin_is_recorded_as_their_unity_member(): void
    {
        $_POST = ['id' => '5'];
        $this->signedInAs(email: 'admin@example.test', displayName: 'Site Admin');

        $repository = new InMemoryCallRequestRepository([$this->request(id: 5)]);
        $this->completeFromRequest($this->page(
            repository: $repository,
            members: [new MemberStub(personalEmail: 'admin@example.test', id: 42, anonymousName: 'Alice K.')],
        ));

        $this->assertSame(42, $repository->completions[0]['memberId']);
        $this->assertSame('Alice K.', $repository->completions[0]['memberName']);
    }

    /**
     * @test
     * @dataProvider fallbackIdentities
     * @param array<int, Member> $members
     */
    public function an_admin_without_a_usable_member_name_falls_back_to_wordpress(
        array $members,
        int $expectedId,
        string $expectedName
    ): void {
        $_POST = ['id' => '5'];
        $this->signedInAs(email: 'admin@example.test', displayName: 'Site Admin');

        $repository = new InMemoryCallRequestRepository([$this->request(id: 5)]);
        $this->completeFromRequest($this->page(repository: $repository, members: $members));

        $this->assertSame($expectedId, $repository->completions[0]['memberId']);
        $this->assertSame($expectedName, $repository->completions[0]['memberName']);
    }

    /** @return array<string, array{0: array<int, Member>, 1: int, 2: string}> */
    public static function fallbackIdentities(): array
    {
        return [
            'no member matches the signed-in email' => [
                [],
                0,
                'Site Admin',
            ],
            'the matched member has no anonymous name' => [
                [new MemberStub(personalEmail: 'admin@example.test', id: 42, anonymousName: '  ')],
                42,
                'Site Admin',
            ],
        ];
    }

    /** @test */
    public function an_admin_with_no_display_name_is_recorded_under_their_login(): void
    {
        $_POST = ['id' => '5'];
        $this->signedInAs(email: 'admin@example.test', displayName: '', login: 'siteadmin');

        $repository = new InMemoryCallRequestRepository([$this->request(id: 5)]);
        $this->completeFromRequest($this->page(repository: $repository));

        $this->assertSame('siteadmin', $repository->completions[0]['memberName']);
    }

    /** @test */
    public function an_admin_with_no_email_is_not_looked_up_at_all(): void
    {
        $_POST = ['id' => '5'];
        $this->signedInAs(email: '', displayName: 'Site Admin');

        $repository = new InMemoryCallRequestRepository([$this->request(id: 5)]);
        // A member whose email is also blank must not be matched by accident.
        $this->completeFromRequest($this->page(
            repository: $repository,
            members: [new MemberStub(personalEmail: '', id: 42, anonymousName: 'Alice K.')],
        ));

        $this->assertSame(0, $repository->completions[0]['memberId']);
        $this->assertSame('Site Admin', $repository->completions[0]['memberName']);
    }

    // ── where the browser goes next ───────────────────────────────────

    /**
     * @test
     * @dataProvider redirectTargets
     * @param array<string, string> $post
     */
    public function the_redirect_returns_to_the_list_flagged_as_completed(array $post, string $expected): void
    {
        $_POST = $post;

        $target = $this->completeFromRequest($this->page());

        $this->assertSame($expected, $target);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function redirectTargets(): array
    {
        $base = 'https://example.test/wp-admin/admin.php?';

        return [
            'no page posted' => [
                [],
                $base . 'page=reach-call-requests&completed=1',
            ],
            'back to the page the button was on' => [
                ['paged' => '3'],
                $base . 'page=reach-call-requests&paged=3&completed=1',
            ],
            'page one carries no paged argument' => [
                ['paged' => '1'],
                $base . 'page=reach-call-requests&completed=1',
            ],
            'a nonsense page falls back to the first' => [
                ['paged' => '-2'],
                $base . 'page=reach-call-requests&completed=1',
            ],
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int, Member> $members */
    private function page(
        ?InMemoryCallRequestRepository $repository = null,
        ?AuditLogger $audit = null,
        array $members = [],
    ): CallRequestsPage {
        return new CallRequestsPage(
            $repository ?? new InMemoryCallRequestRepository(),
            $audit ?? new SpyAuditLogger(),
            new InMemoryMemberRepository($members),
        );
    }

    /**
     * Fix who wp_get_current_user() reports.
     *
     * Patchwork keeps the redefined function's signature, and the stub's is
     * `: \WP_User`, so this has to hand back a real WP_User rather than a
     * convenient stdClass.
     */
    private function signedInAs(string $email, string $displayName, string $login = 'tester'): void
    {
        $user = new WP_User(['administrator'], 1);
        $user->user_email = $email;
        $user->display_name = $displayName;
        $user->user_login = $login;

        Functions\when('wp_get_current_user')->justReturn($user);
    }

    private function createdAt(): int
    {
        return (int) strtotime('2026-07-24 09:15:00 UTC');
    }

    private function completedAt(): int
    {
        return (int) strtotime('2026-07-24 10:15:00 UTC');
    }

    private function request(
        int $id = 1,
        string $responderName = 'Alice K.',
        string $area = 'Bedminster',
        string $viewerEmail = 'responder@example.test',
        ?int $completedAt = null,
        string $completedByName = '',
    ): CallRequest {
        return new CallRequest(
            id: $id,
            responderName: $responderName,
            area: $area,
            viewerEmail: $viewerEmail,
            viewerProvider: 'google',
            createdAt: $this->createdAt(),
            completedAt: $completedAt,
            completedByMemberId: $completedAt === null ? 0 : 42,
            completedByName: $completedByName,
        );
    }

    private function completeFromRequest(CallRequestsPage $page): string
    {
        return (string) (new ReflectionMethod(CallRequestsPage::class, 'completeFromRequest'))->invoke($page);
    }

    private function renderList(CallRequestsPage $page): string
    {
        ob_start();
        try {
            $page->renderList();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
