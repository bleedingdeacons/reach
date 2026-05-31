<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\CallAttemptRepository;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberView;
use Unity\Members\Interfaces\MemberViewFactory;

/**
 * Read-only admin view of wp_reach_call_attempts.
 *
 * Why read-only
 * -------------
 * The audit trail leans on this table being append-only. Letting
 * admins edit or delete rows would:
 *   - make Scrutiny's audit log misleading (it records the attempt
 *     having happened; mutating the underlying row turns that into
 *     a lie),
 *   - and create a tempting "tidy up" path that quietly suppresses
 *     uncomfortable patterns ("this person's number keeps getting
 *     flagged bad").
 * If a correction is genuinely needed, the right answer is for the
 * caller to log a new attempt with the corrected outcome — the
 * scorer's "reached recently wins" rule then takes care of it.
 *
 * Capability
 * ----------
 * Gated behind scrutiny_view_personal_data — the same capability the
 * rest of the Reach REST surface uses. A WP admin without that
 * capability can't see this page. Consistent with the rest of the
 * stack: one cap revocation cuts off every personal-data surface
 * including this one.
 *
 * Menu placement
 * --------------
 * Top-level "Reach" menu, with the call-attempts list as its default
 * page. SettingsPage attaches "Authentication" as a second submenu
 * under the same top-level menu, so all of Reach's admin surfaces
 * — operational data and OAuth configuration — live together rather
 * than splitting configuration off under "Settings".
 */
final class CallAttemptsPage
{
    public const PAGE_SLUG = 'reach-call-attempts';
    public const MENU_SLUG = 'reach';
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;
    private const PER_PAGE = 50;
    private const MAX_PAGES_SHOWN = 1000; // cap pager arithmetic on absurd inputs

    /**
     * SVG menu glyph: a stylised hand reaching upward.
     *
     * Built as a flat, single-fill silhouette so WordPress can recolour
     * it via CSS for inactive / hover / active sidebar states. Designed
     * to read at 20×20 — the size WP renders menu icons — so shapes
     * are chunky (no stroke detail finer than ~1.5px) and the fingers
     * are wide rectangles rather than thin lines.
     *
     * Passed to add_menu_page() as a data: URL; WP recognises
     * "data:image/svg+xml;base64,..." and inlines the icon, applying
     * its own fill colour via the .toplevel_page_* CSS rules.
     */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
        . '<rect x="6" y="15" width="8" height="4" rx="1.2" fill="black"/>'
        . '<rect x="5" y="9" width="10" height="7" rx="2" fill="black"/>'
        . '<rect x="6" y="3" width="1.8" height="7" rx="0.9" fill="black"/>'
        . '<rect x="8.2" y="1" width="1.8" height="9" rx="0.9" fill="black"/>'
        . '<rect x="10.4" y="1.5" width="1.8" height="8.5" rx="0.9" fill="black"/>'
        . '<rect x="12.6" y="3" width="1.8" height="7" rx="0.9" fill="black"/>'
        . '<rect x="3.2" y="10" width="1.8" height="5.5" rx="0.9" fill="black" transform="rotate(-18 4.1 12.75)"/>'
        . '</svg>';

    public function __construct(
        private readonly CallAttemptRepository $repository,
        private readonly MemberViewFactory $memberViews,
        private readonly MemberRepository $members,
    ) {
    }

    /**
     * Per-request memo for responder lookups by email. A paginated
     * list often shows multiple attempts by the same responder, so
     * caching the rendered cell (name + edit link, or fallback email)
     * avoids redundant MemberRepository::findByEmail() calls within
     * a single render. Values are pre-escaped HTML.
     *
     * @var array<string, string>
     */
    private array $responderCellMemo = [];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        // Top-level menu. The list page is also the default landing
        // page for the menu, so menu-slug and submenu-slug match.
        add_menu_page(
            'Reach',
            'Reach',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderList'],
            'data:image/svg+xml;base64,' . base64_encode(self::ICON_SVG),
            58, // between Comments (25) and Tools (75); arbitrary but stable
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Call attempts',
            'Call attempts',
            self::CAPABILITY,
            self::MENU_SLUG, // same slug → this becomes the default page
            [$this, 'renderList'],
        );

        // Detail view: same capability, hidden from the menu (passing
        // null as the parent slug registers it without a sidebar link).
        add_submenu_page(
            '',
            'Call attempt',
            'Call attempt',
            self::CAPABILITY,
            self::PAGE_SLUG . '-detail',
            [$this, 'renderDetail'],
        );
    }

    /**
     * List view: filter form, paginated table.
     *
     * All filters live in the query string so admins can bookmark
     * or share a specific view. No POSTs anywhere on this page —
     * read-only is enforced by the absence of mutation handlers,
     * not by client-side button hiding.
     */
    public function renderList(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $filters = $this->readFilters();
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $offset  = ($page - 1) * self::PER_PAGE;

        $total = $this->repository->countWhere($this->toRepoFilters($filters));
        $rows  = $this->repository->list($this->toRepoFilters($filters), self::PER_PAGE, $offset);

        // Resolve every member referenced on this page in a single
        // batched factory call. The list table would otherwise issue
        // N findById() calls, each doing a get_post + ACF read.
        $memberIds = [];
        foreach ($rows as $row) {
            $memberIds[$row->memberId] = true;
        }
        $resolved = $this->loadMemberViews(array_keys($memberIds));

        $totalPages = max(1, (int) ceil(min($total, self::PER_PAGE * self::MAX_PAGES_SHOWN) / self::PER_PAGE));
        ?>
        <div class="wrap">
            <h1>Call attempts</h1>
            <p class="description">
                Read-only log of every recorded call attempt from the Reach find page.
                Outcomes feed the responsiveness badges shown to other Reach users.
            </p>

            <?php $this->renderFilters($filters, $total); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 160px;">When</th>
                        <th scope="col">12th Stepper</th>
                        <th scope="col">Responder</th>
                        <th scope="col" style="width: 160px;">Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="4">No call attempts match these filters.</td>
                        </tr>
                    <?php else: foreach ($rows as $row): ?>
                        <?php $memberView = $resolved[$row->memberId] ?? null; ?>
                        <tr>
                            <td><?php echo esc_html($this->formatTime($row->createdAt)); ?></td>
                            <td><?php echo $this->memberCell($memberView); ?></td>
                            <td><?php echo $this->responderCell($row->viewerEmail); ?></td>
                            <td><?php echo esc_html($this->outcomeLabel($row->outcome)); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php $this->renderPager($page, $totalPages, $total, $filters); ?>
        </div>
        <script>
            // In-page filter for the 12th-Stepper column. Lives client-
            // side because the call_attempts table has no name to filter
            // on — only member_id — and the existing pattern of pulling
            // every member through the repository to resolve a name is
            // too heavy for an interactive filter. Filtering the already-
            // rendered page is good enough: admins scanning recent
            // activity already see the page they care about, and the
            // other filters (outcome / date / responder) still narrow
            // the server-side set first.
            (function () {
                var input = document.getElementById('reach-member-filter');
                if (!input) {
                    return;
                }
                // Pre-read the 12th-Stepper cell text for each row so
                // we don't touch the DOM on every keystroke. The
                // "no call attempts" placeholder row has a single
                // colspan cell and is skipped — leaving it visible is
                // correct: when nothing matched server-side, that
                // message should keep showing regardless of what's
                // typed locally.
                var entries = [];
                var rows = document.querySelectorAll('.wp-list-table tbody tr');
                rows.forEach(function (row) {
                    var cell = row.children[1];
                    if (cell) {
                        entries.push({ row: row, text: (cell.textContent || '').toLowerCase() });
                    }
                });
                input.addEventListener('input', function () {
                    var q = input.value.trim().toLowerCase();
                    entries.forEach(function (entry) {
                        var match = q === '' || entry.text.indexOf(q) !== -1;
                        entry.row.style.display = match ? '' : 'none';
                    });
                });
            })();
        </script>
        <?php
    }

    /**
     * Detail view for one attempt. Shows everything stored, including
     * the free-text note (which never leaves this surface — it isn't
     * sent to other Reach users and isn't included in audit-log detail).
     */
    public function renderDetail(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $attempt = $id > 0 ? $this->repository->findById($id) : null;
        // Resolve through the factory so the detail and list views
        // agree on what a "member" looks like (anonymous projection
        // only). createFromSource accepts a single-element array and
        // silently drops unresolved ids, so missing members come back
        // as null below.
        $member = null;
        if ($attempt !== null) {
            $views  = $this->loadMemberViews([$attempt->memberId]);
            $member = $views[$attempt->memberId] ?? null;
        }

        ?>
        <div class="wrap">
            <h1>Call attempt</h1>

            <?php if ($attempt === null): ?>
                <p>That call attempt could not be found.</p>
                <p><a href="<?php echo esc_url($this->listUrl()); ?>">&larr; Back to call attempts</a></p>
            <?php else: ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">ID</th>
                            <td><?php echo (int) $attempt->id; ?></td>
                        </tr>
                        <tr>
                            <th scope="row">When</th>
                            <td><?php echo esc_html($this->formatTime($attempt->createdAt)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">12th Stepper</th>
                            <td><?php echo $this->memberCell($member); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Responder</th>
                            <td><?php echo $this->responderCell($attempt->viewerEmail); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Outcome</th>
                            <td><?php echo esc_html($this->outcomeLabel($attempt->outcome)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Note</th>
                            <td>
                                <?php if ($attempt->note === null || $attempt->note === ''): ?>
                                    <em>None</em>
                                <?php else: ?>
                                    <pre style="white-space: pre-wrap; margin: 0;"><?php
                                        echo esc_html($attempt->note);
                                    ?></pre>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p><a href="<?php echo esc_url($this->listUrl()); ?>">&larr; Back to call attempts</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Read filters off the query string and sanitise them. Everything
     * is optional; whatever's missing or invalid is silently dropped
     * rather than erroring, because filter forms on admin lists are
     * conventionally forgiving.
     *
     * @return array{member_id: int, viewer_email: string, outcome: string, since: string, until: string}
     */
    private function readFilters(): array
    {
        $memberId    = isset($_GET['member_id']) ? max(0, (int) $_GET['member_id']) : 0;
        $viewerEmail = isset($_GET['viewer_email']) ? sanitize_text_field((string) $_GET['viewer_email']) : '';
        $outcome     = isset($_GET['outcome']) ? sanitize_text_field((string) $_GET['outcome']) : '';
        if ($outcome !== '' && !CallAttempt::isValidOutcome($outcome)) {
            $outcome = '';
        }
        // Dates kept as 'YYYY-MM-DD' strings on the form side; the
        // repository wants epoch seconds.
        $since = isset($_GET['since']) ? sanitize_text_field((string) $_GET['since']) : '';
        $until = isset($_GET['until']) ? sanitize_text_field((string) $_GET['until']) : '';

        return [
            'member_id'    => $memberId,
            'viewer_email' => $viewerEmail,
            'outcome'      => $outcome,
            'since'        => $since,
            'until'        => $until,
        ];
    }

    /**
     * Convert the form-level filters into the repository's filter
     * shape — repository wants epoch ints for dates, the form wants
     * 'YYYY-MM-DD' strings.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function toRepoFilters(array $filters): array
    {
        $out = [];
        if ((int) ($filters['member_id'] ?? 0) > 0) {
            $out['member_id'] = (int) $filters['member_id'];
        }
        if (!empty($filters['viewer_email'])) {
            $out['viewer_email'] = (string) $filters['viewer_email'];
        }
        if (!empty($filters['outcome'])) {
            $out['outcome'] = (string) $filters['outcome'];
        }
        if (!empty($filters['since'])) {
            $ts = strtotime((string) $filters['since'] . ' 00:00:00');
            if ($ts !== false) {
                $out['since'] = $ts;
            }
        }
        if (!empty($filters['until'])) {
            // Include the whole 'until' day.
            $ts = strtotime((string) $filters['until'] . ' 23:59:59');
            if ($ts !== false) {
                $out['until'] = $ts;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function renderFilters(array $filters, int $total): void
    {
        ?>
        <form method="get" action="" style="margin: 16px 0;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">

            <p style="display: flex; flex-wrap: wrap; gap: 8px; align-items: end;">
                <label>
                    Member<br>
                    <input type="search" id="reach-member-filter" size="24"
                           placeholder="filter by name…">
                </label>

                <label>
                    Responder<br>
                    <input type="search" name="viewer_email" size="24"
                           value="<?php echo esc_attr((string) $filters['viewer_email']); ?>">
                </label>

                <label>
                    Outcome<br>
                    <select name="outcome">
                        <option value="">Any</option>
                        <?php foreach (CallAttempt::OUTCOMES as $opt): ?>
                            <option value="<?php echo esc_attr($opt); ?>"
                                <?php selected($filters['outcome'], $opt); ?>>
                                <?php echo esc_html($this->outcomeLabel($opt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Since<br>
                    <input type="date" name="since"
                           value="<?php echo esc_attr((string) $filters['since']); ?>">
                </label>

                <label>
                    Until<br>
                    <input type="date" name="until"
                           value="<?php echo esc_attr((string) $filters['until']); ?>">
                </label>

                <span>
                    <button type="submit" class="button button-primary">Filter</button>
                    <a href="<?php echo esc_url($this->listUrl()); ?>" class="button">Reset</a>
                </span>
            </p>

            <p class="description">
                <?php echo (int) $total; ?>
                attempt<?php echo $total === 1 ? '' : 's'; ?> match<?php echo $total === 1 ? 'es' : ''; ?>.
            </p>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function renderPager(int $page, int $totalPages, int $total, array $filters): void
    {
        if ($totalPages <= 1) {
            return;
        }
        $base = $this->listUrl($filters);
        $link = static function (int $n) use ($base) {
            return esc_url(add_query_arg('paged', $n, $base));
        };
        ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php echo (int) $total; ?> item<?php echo $total === 1 ? '' : 's'; ?>
                </span>
                <span class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a class="prev-page button" href="<?php echo $link($page - 1); ?>">&lsaquo; Prev</a>
                    <?php endif; ?>
                    <span class="paging-input">
                        Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
                    </span>
                    <?php if ($page < $totalPages): ?>
                        <a class="next-page button" href="<?php echo $link($page + 1); ?>">Next &rsaquo;</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function listUrl(array $filters = []): string
    {
        $args = ['page' => self::MENU_SLUG];
        foreach (['member_id', 'viewer_email', 'outcome', 'since', 'until'] as $k) {
            if (!empty($filters[$k])) {
                $args[$k] = $filters[$k];
            }
        }
        return admin_url('admin.php?' . http_build_query($args));
    }

    private function detailUrl(int $id): string
    {
        return admin_url('admin.php?' . http_build_query([
            'page' => self::PAGE_SLUG . '-detail',
            'id'   => $id,
        ]));
    }

    /**
     * Resolve member ids to {@see MemberView}s in one batch.
     *
     * MemberViewFactory::createFromSource() is the batch-friendly entry
     * point — concrete implementations hydrate the whole list in a
     * single round-trip and warm WP's object cache as a side effect.
     * Unresolved ids (deleted members) are silently dropped; callers
     * handle null via {@see memberCell()}.
     *
     * Returned views are keyed by member id rather than left in the
     * factory's positional order, so the list-table loop can look up
     * each row's member with a simple array access.
     *
     * @param array<int, int> $ids
     * @return array<int, MemberView>
     */
    private function loadMemberViews(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $views = $this->memberViews->createFromSource($ids);

        $out = [];
        foreach ($views as $view) {
            $out[$view->getId()] = $view;
        }
        return $out;
    }

    /**
     * Render the "Responder" cell as pre-escaped HTML.
     *
     * Responders are themselves members (they're the ones flagged as
     * telephone responders / 12th-steppers in Unity), so the same
     * MemberRepository that powers the rest of Reach is the right
     * lookup. When the lookup resolves, we render the member's
     * anonymous name as a link to their edit screen — consistent with
     * how the 12th-stepper cell is rendered, and so admins can jump
     * straight from the audit trail to the responder's record.
     *
     * Falls back to the raw (escaped) email when no matching member
     * exists — a responder whose record has since been removed, or an
     * attempt pre-dating their joining the responder roster. This
     * keeps the audit trail readable rather than silently blanking
     * the column.
     *
     * Memoised per-request to avoid repeated lookups on paginated
     * lists where the same responder appears on multiple rows.
     */
    private function responderCell(string $email): string
    {
        if ($email === '') {
            return '';
        }
        if (isset($this->responderCellMemo[$email])) {
            return $this->responderCellMemo[$email];
        }

        $member = $this->members->findByEmail($email);
        if ($member === null) {
            return $this->responderCellMemo[$email] = esc_html($email);
        }

        $name  = trim($member->getAnonymousName());
        $label = esc_html($name !== '' ? $name : $email);

        $editUrl = get_edit_post_link($member->getId());
        if (is_string($editUrl) && $editUrl !== '') {
            $label = '<a href="' . esc_url($editUrl) . '">' . $label . '</a>';
        }

        return $this->responderCellMemo[$email] = $label;
    }

    /**
     * Render the "12th Stepper" cell. Returns pre-escaped HTML so it
     * can be echoed without further wrapping.
     *
     * A resolved member shows "Anonymous Name · Area", with the name
     * linked to the member's edit screen so admins can jump straight
     * from the audit trail to the record. When the member is missing
     * (deleted or otherwise unreadable), we mark the cell explicitly
     * rather than rendering an empty one — admins still need to spot
     * orphaned attempts even though the id no longer appears in the
     * UI. If the current user can't edit the member (capability
     * mismatch), we render the name as plain text rather than a
     * broken link.
     */
    private function memberCell(?MemberView $member): string
    {
        if ($member === null) {
            return '<em>(member not found)</em>';
        }
        $name = trim($member->getAnonymousName());
        $area = trim($member->getArea());

        if ($name === '' && $area === '') {
            return '<em>(no name)</em>';
        }

        // Link the primary label (name when present, otherwise area)
        // to the member's edit screen. Only the primary label is
        // linked — area-as-secondary stays plain so the cell reads
        // as "name · area" rather than two adjacent links.
        $primary     = $name !== '' ? $name : $area;
        $primaryHtml = esc_html($primary);
        $editUrl     = get_edit_post_link($member->getId());
        if (is_string($editUrl) && $editUrl !== '') {
            $primaryHtml = '<a href="' . esc_url($editUrl) . '">' . $primaryHtml . '</a>';
        }

        $parts = [$primaryHtml];
        if ($name !== '' && $area !== '') {
            $parts[] = esc_html($area);
        }
        return implode(' &middot; ', $parts);
    }

    private function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            CallAttempt::OUTCOME_REACHED       => 'Spoke',
            CallAttempt::OUTCOME_NO_ANSWER     => 'No answer',
            CallAttempt::OUTCOME_WRONG_OR_BAD  => 'Wrong / bad number',
            default                            => $outcome,
        };
    }

    /**
     * Format a stored epoch as the site's local-time display string.
     * Using wp_date() rather than date() so the site's configured
     * timezone is respected — admins reading "14:32" assume their
     * own clock, not UTC.
     */
    private function formatTime(int $epoch): string
    {
        if (function_exists('wp_date')) {
            $formatted = wp_date('Y-m-d H:i', $epoch);
            if (is_string($formatted)) {
                return $formatted;
            }
        }
        return gmdate('Y-m-d H:i', $epoch) . ' UTC';
    }
}
