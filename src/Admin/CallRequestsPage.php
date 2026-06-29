<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestRepository;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;

use function wp_get_current_user;

/**
 * Admin view of wp_reach_call_requests — out-of-hours callback requests
 * raised from the Reach find page.
 *
 * The caller's personal data is NOT held here: it is emailed to the
 * configured call-request address when the request is raised (see
 * {@see \Reach\CallRequests\CallRequestMailer}). This table keeps only a
 * non-identifying tracking record — a serial, who raised it, the
 * caller's area, when, and whether it has been actioned — so the list
 * is a durable history rather than short-lived PII.
 *
 * Each pending row carries a "Completed" button instead of a delete:
 * marking a request done records which member actioned it and writes a
 * Scrutiny audit entry. Completed rows are kept (history) and show who
 * closed them.
 *
 * Capability
 * ----------
 * Gated behind scrutiny_view_personal_data — kept the same as the
 * call-attempts page so the menu placement and access are consistent,
 * even though the rows themselves no longer hold caller PII.
 *
 * Menu placement
 * --------------
 * A submenu under the top-level "Reach" menu registered by
 * CallAttemptsPage, sitting alongside "Call attempts".
 */
final class CallRequestsPage
{
    public const PAGE_SLUG = 'reach-call-requests';
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;
    private const COMPLETE_ACTION = 'reach_complete_call_request';
    private const PER_PAGE = 50;
    private const MAX_PAGES_SHOWN = 1000;

    /**
     * Audit action recorded when a request is marked done. Scrutiny's
     * action field is free-form (it already stores values such as
     * 'purge' that aren't interface constants), so a 'complete' action
     * renders as "Complete" in the audit viewer.
     */
    private const AUDIT_ACTION_COMPLETE = 'complete';

    public function __construct(
        private readonly CallRequestRepository $repository,
        private readonly AuditLogger $auditLogger,
        private readonly MemberRepository $members,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::COMPLETE_ACTION, [$this, 'handleComplete']);
    }

    public function addMenu(): void
    {
        // Parent menu ("Reach") is registered by CallAttemptsPage; this
        // attaches as a sibling of "Call attempts".
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Call requests',
            'Call requests',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderList'],
        );
    }

    /**
     * List view: a paginated table with a Completed button per pending
     * row. History is kept, so nothing is purged here.
     */
    public function renderList(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $total   = $this->repository->countAll();
        $pending = $this->repository->countPending();
        $rows    = $this->repository->list(self::PER_PAGE, $offset);

        $totalPages = max(1, (int) ceil(min($total, self::PER_PAGE * self::MAX_PAGES_SHOWN) / self::PER_PAGE));

        $notice = '';
        if (isset($_GET['completed']) && $_GET['completed'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Request marked completed.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Call requests</h1>
            <?php echo $notice; ?>
            <p class="description">
                Callback requests raised from the Reach home page. The caller&rsquo;s details are
                emailed to the configured call&#8209;request address &mdash; this list keeps a
                reference and tracks which have been actioned, but holds no caller personal data.
                Mark a request <strong>Completed</strong> once the 12th&#8209;Stepper has called the
                caller back; completed requests are kept here as a history.
            </p>

            <p class="description">
                <?php echo (int) $pending; ?>
                request<?php echo $pending === 1 ? '' : 's'; ?> pending&nbsp;&middot;&nbsp;
                <?php echo (int) $total; ?> in total.
            </p>

            <table class="wp-list-table widefat fixed striped" style="width: auto;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 110px;">Reference</th>
                        <th scope="col" style="width: 130px;">When</th>
                        <th scope="col" style="width: 200px;">Telephone Responder</th>
                        <th scope="col" style="width: 160px;">Area</th>
                        <th scope="col" style="width: 220px;">Status</th>
                        <th scope="col" style="width: 110px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="6">No call requests yet.</td>
                        </tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row->serial()); ?></code></td>
                            <td style="white-space: nowrap;"><?php echo esc_html($this->formatTime($row->createdAt)); ?></td>
                            <td><?php echo $this->responderCell($row->responderName); ?></td>
                            <td><?php echo $this->areaCell($row->area); ?></td>
                            <td><?php echo $this->statusCell($row); ?></td>
                            <td><?php $this->renderAction($row, $page); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php $this->renderPager($page, $totalPages, $total); ?>
        </div>
        <?php
    }

    /**
     * Handle a per-row "Completed" POST from admin-post.php.
     */
    public function handleComplete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions', '', ['response' => 403]);
        }
        check_admin_referer(self::COMPLETE_ACTION);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            [$memberId, $memberName] = $this->actingMember();
            $request = $this->repository->findById($id);
            if (
                $request !== null
                && !$request->isCompleted()
                && $this->repository->markCompleted($id, $memberId, $memberName, time())
            ) {
                $this->auditCompletion($request);
            }
        }

        $page = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;
        wp_safe_redirect($this->listUrl($page, ['completed' => '1']));
        exit;
    }

    /**
     * Resolve the signed-in admin marking the request done to a Unity
     * member, returning [memberId, displayName].
     *
     * The acting WP user's email is matched against Unity members so the
     * stored name is the member's anonymous name when there is one; we
     * fall back to the WP display name (and 0 for the id) otherwise, so
     * the list always shows who actioned a request.
     */
    private function actingMember(): array
    {
        $user = wp_get_current_user();
        $email = is_object($user) && isset($user->user_email) ? (string) $user->user_email : '';

        if ($email !== '') {
            $member = $this->members->findByEmail($email);
            if ($member !== null) {
                $name = trim($member->getAnonymousName());
                return [$member->getId(), $name !== '' ? $name : $this->displayName($user)];
            }
        }

        return [0, $this->displayName($user)];
    }

    private function displayName(object $user): string
    {
        $name = isset($user->display_name) ? trim((string) $user->display_name) : '';
        if ($name !== '') {
            return $name;
        }
        return isset($user->user_login) ? (string) $user->user_login : '(unknown)';
    }

    /**
     * Record a Scrutiny audit entry for a completed call request.
     *
     * Previously a request was *deleted* and logged as a deletion of
     * caller personal data; now it is *completed* and kept. The entry is
     * anchored to the responder's member record (resolved from the
     * stored viewer email) when one exists, and logged under the
     * 'complete' action so the audit viewer reads "Complete" rather than
     * "Delete". No raw PII goes into the detail.
     */
    private function auditCompletion(CallRequest $request): void
    {
        $memberId = 0;
        if ($request->viewerEmail !== '') {
            $member = $this->members->findByEmail($request->viewerEmail);
            if ($member !== null) {
                $memberId = $member->getId();
            }
        }

        $this->auditLogger->logBatch(
            self::AUDIT_ACTION_COMPLETE,
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            [PersonalDataFields::MOBILE_NUMBER],
            'Reach call request ' . $request->serial() . ' completed',
        );
    }

    /**
     * Render the action cell: a "Completed" button for a pending request,
     * or a muted dash once it is done.
     */
    private function renderAction(CallRequest $row, int $page): void
    {
        if ($row->isCompleted()) {
            echo '<span aria-hidden="true">&mdash;</span>';
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0;"
              onsubmit="return confirm('Mark this call request as completed? This records that you actioned it.');">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::COMPLETE_ACTION); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
            <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">
            <?php wp_nonce_field(self::COMPLETE_ACTION); ?>
            <button type="submit" class="button button-small button-primary">Completed</button>
        </form>
        <?php
    }

    /**
     * Render the "Status" cell — "Pending" while open, or "Completed by
     * <member> on <date>" once actioned.
     */
    private function statusCell(CallRequest $row): string
    {
        if (!$row->isCompleted()) {
            return '<span style="color:#996800;">Pending</span>';
        }

        $by = trim($row->completedByName);
        $by = $by !== '' ? esc_html($by) : '<em>(unknown)</em>';
        $when = esc_html($this->formatTime((int) $row->completedAt));

        return '<span style="color:#00713c;">Completed</span> by ' . $by . '<br><small>' . $when . '</small>';
    }

    private function renderPager(int $page, int $totalPages, int $total): void
    {
        if ($totalPages <= 1) {
            return;
        }
        $link = fn(int $n): string => esc_url($this->listUrl($n));
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
     * @param array<string, string> $extra
     */
    private function listUrl(int $page = 1, array $extra = []): string
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($page > 1) {
            $args['paged'] = $page;
        }
        foreach ($extra as $k => $v) {
            $args[$k] = $v;
        }
        return admin_url('admin.php?' . http_build_query($args));
    }

    /**
     * Render the "Telephone Responder" cell — the plain name stored with
     * the request (a Unity anonymous name, or the sign-in email fallback).
     */
    private function responderCell(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '<em>(unknown)</em>';
        }
        return esc_html($name);
    }

    /**
     * Render the "Area" cell — the free-text area/postcode the caller is
     * in, or a muted dash when somehow empty.
     */
    private function areaCell(string $area): string
    {
        $area = trim($area);
        if ($area === '') {
            return '<span aria-hidden="true">&mdash;</span>';
        }
        return esc_html($area);
    }

    /**
     * Format a stored epoch as the site's local-time display string,
     * respecting the configured timezone (same as CallAttemptsPage).
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
