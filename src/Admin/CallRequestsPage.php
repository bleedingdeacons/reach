<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestRepository;
use Reach\CallRequests\WpdbCallRequestRepository;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Admin view of wp_reach_call_requests — out-of-hours callback requests
 * raised from the Reach find page.
 *
 * Unlike {@see CallAttemptsPage} (a read-only audit surface) this list
 * is deletable: requests are short-lived operational data. Each row
 * carries a Delete button, and the whole table is purged of anything
 * older than {@see WpdbCallRequestRepository::RETENTION_DAYS} days both
 * by a daily cron and as a backstop every time this page loads (so it
 * still works on installs where WP-Cron is disabled).
 *
 * Capability
 * ----------
 * Gated behind scrutiny_view_personal_data — same as the call-attempts
 * page. These rows contain the *caller's* name and phone, so the same
 * personal-data capability is the right gate.
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
    private const DELETE_ACTION = 'reach_delete_call_request';
    private const PER_PAGE = 50;
    private const MAX_PAGES_SHOWN = 1000;

    /** Human labels for the stored single-choice gender values. */
    private const GENDER_LABELS = [
        'male'       => 'Male',
        'female'     => 'Female',
        'non-binary' => 'Non-Binary',
    ];

    public function __construct(
        private readonly CallRequestRepository $repository,
        private readonly AuditLogger $auditLogger,
        private readonly MemberRepository $members,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::DELETE_ACTION, [$this, 'handleDelete']);
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
     * List view: purge expired rows, then show a paginated table with a
     * Delete button per row.
     */
    public function renderList(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // Backstop purge — keeps the page honest even where WP-Cron is
        // disabled. Cheap: one indexed DELETE.
        $this->repository->purgeOlderThan(
            WpdbCallRequestRepository::RETENTION_DAYS * DAY_IN_SECONDS,
            time(),
        );

        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $total = $this->repository->countAll();
        $rows  = $this->repository->list(self::PER_PAGE, $offset);

        $totalPages = max(1, (int) ceil(min($total, self::PER_PAGE * self::MAX_PAGES_SHOWN) / self::PER_PAGE));

        $notice = '';
        if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Request deleted.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Call requests</h1>
            <?php echo $notice; ?>
            <p class="description">
                Callback requests raised from the Reach home page. Each is logged by the listed
                telephone responder for a 12th&#8209;Stepper to call the caller back. Requests are
                kept for
                <?php echo (int) WpdbCallRequestRepository::RETENTION_DAYS; ?> days, then removed
                automatically &mdash; delete one sooner once it has been actioned.
            </p>

            <p class="description">
                <?php echo (int) $total; ?>
                request<?php echo $total === 1 ? '' : 's'; ?> pending.
            </p>

            <table class="wp-list-table widefat fixed striped" style="width: auto;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 130px;">When</th>
                        <th scope="col" style="width: 200px;">Telephone Responder</th>
                        <th scope="col" style="width: 160px;">Caller</th>
                        <th scope="col" style="width: 150px;">Phone</th>
                        <th scope="col" style="width: 160px;">Area</th>
                        <th scope="col" style="width: 110px;">Preferred</th>
                        <th scope="col">Notes</th>
                        <th scope="col" style="width: 90px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="8">No call requests pending.</td>
                        </tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo esc_html($this->formatTime($row->createdAt)); ?></td>
                            <td><?php echo $this->responderCell($row->responderName); ?></td>
                            <td><?php echo esc_html($row->callerName); ?></td>
                            <td><a href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $row->callerPhone) ?? ''); ?>"><?php echo esc_html($row->callerPhone); ?></a></td>
                            <td><?php echo $this->areaCell($row->area); ?></td>
                            <td><?php echo esc_html($this->genderLabel($row->gender)); ?></td>
                            <td><?php echo $this->noteCell($row->note); ?></td>
                            <td><?php $this->renderDeleteButton($row->id, $page); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php $this->renderPager($page, $totalPages, $total); ?>
        </div>
        <?php
    }

    /**
     * Handle a per-row delete POST from admin-post.php.
     */
    public function handleDelete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions', '', ['response' => 403]);
        }
        check_admin_referer(self::DELETE_ACTION);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            // Load the row before deleting so the audit entry can name the
            // responder who raised it; only audit once a row is actually gone.
            $request = $this->repository->findById($id);
            if ($this->repository->delete($id) && $request !== null) {
                $this->auditDeletion($request);
            }
        }

        $page = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;
        wp_safe_redirect($this->listUrl($page, ['deleted' => '1']));
        exit;
    }

    /**
     * Record a Scrutiny audit entry for a deleted call request.
     *
     * A request holds the *caller's* name and phone, so removing it is a
     * deletion of personal data and is logged as such. The entry is anchored
     * to the responder's member record (resolved from the stored viewer
     * email) when one exists, against the mobile-number field — the kind of
     * datum the request carried. The acting admin, time and anonymised IP are
     * recorded by the logger; no raw caller PII goes into the detail.
     */
    private function auditDeletion(CallRequest $request): void
    {
        $memberId = 0;
        if ($request->viewerEmail !== '') {
            $member = $this->members->findByEmail($request->viewerEmail);
            if ($member !== null) {
                $memberId = $member->getId();
            }
        }

        $this->auditLogger->logBatch(
            AuditLogger::ACTION_DELETE,
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            [PersonalDataFields::MOBILE_NUMBER],
            'Reach call request deleted',
        );
    }

    private function renderDeleteButton(int $id, int $page): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0;"
              onsubmit="return confirm('Delete this call request? This permanently removes the caller details and cannot be undone.');">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::DELETE_ACTION); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">
            <?php wp_nonce_field(self::DELETE_ACTION); ?>
            <button type="submit" class="button button-small button-link-delete">Delete</button>
        </form>
        <?php
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
     * Human label for a stored single-choice gender value, falling back
     * to the raw value for anything unexpected.
     */
    private function genderLabel(string $gender): string
    {
        return self::GENDER_LABELS[$gender] ?? ($gender !== '' ? $gender : '—');
    }

    /**
     * Render the free-text note cell, preserving the caller's line
     * breaks while escaping HTML. Empty notes show a muted dash.
     */
    private function noteCell(?string $note): string
    {
        $note = $note !== null ? trim($note) : '';
        if ($note === '') {
            return '<span aria-hidden="true">&mdash;</span>';
        }
        return '<span style="white-space: pre-wrap;">' . esc_html($note) . '</span>';
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
