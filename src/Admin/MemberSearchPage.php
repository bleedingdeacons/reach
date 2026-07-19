<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Resolution\NearestMembersResolver;
use Reach\Resolution\ScoredMember;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberView;
use Unity\Members\Interfaces\MemberViewFactory;

/**
 * Admin search for the nearest 12th-steppers to an area.
 *
 * The same tool the public find page offers Reach visitors, exposed to
 * intergroup admins: type an area (postcode or place name), optionally
 * narrow by the genders the responder accepts 12th-step calls from, and
 * get back the nearest matching 12th-steppers ordered by distance.
 *
 * Shared filter, no REST round-trip
 * --------------------------------
 * The gender/area matching is not reimplemented here — this page calls
 * {@see NearestMembersResolver::resolve()} directly, the very same
 * filter that backs the reach/v1/nearest-members REST endpoint. Going
 * through the container-bound resolver rather than the HTTP controller
 * keeps this an ordinary admin screen (no session cookie, no audit of a
 * "visitor" who is really a logged-in WP admin) while guaranteeing the
 * results match what a member would see on the find page.
 *
 * Member data via the view factory
 * --------------------------------
 * The resolver hands back {@see ScoredMember}s carrying full Member
 * objects; we take only their ids and distances and re-hydrate through
 * {@see MemberViewFactory} — the same anonymous, read-only projection
 * CallAttemptsPage renders. Admins see the anonymous name, covered
 * area and the genders accepted, never the raw personal record.
 *
 * Capability & placement
 * ----------------------
 * Gated behind scrutiny_view_personal_data (a search surfaces mobile
 * numbers), matching the rest of Reach's admin. Attaches as a submenu
 * under the top-level "Reach" menu registered by CallAttemptsPage.
 */
final class MemberSearchPage
{
    public const PAGE_SLUG = 'reach-find-member';
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;

    /** Upper bound on results returned for one search. */
    private const LIMIT = 50;

    /**
     * The genders a member may accept 12th-step calls from, offered as
     * search filters.
     *
     * Keyed by the stored ACF option value (the `member-accepts` checkbox
     * choices: `accepts-male`, `accepts-female`, `accepts-non-binary`),
     * with the display label as the value. The stored option string is
     * what the resolver matches against each member's accepts list — the
     * same values the public find page submits — so the filter checkbox
     * must carry `accepts-male`, not the label "Male". Sending the label
     * matches nothing (the resolver lower-cases but does not strip the
     * `accepts-` prefix), which silently returned zero results.
     *
     * @var array<string, string>
     */
    private const GENDERS = [
        'accepts-male'       => 'Male',
        'accepts-female'     => 'Female',
        'accepts-non-binary' => 'Non-Binary',
    ];

    public function __construct(
        private readonly NearestMembersResolver $resolver,
        private readonly MemberViewFactory $memberViews,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        // Attaches under the top-level "Reach" menu registered by
        // CallAttemptsPage. Registration order (see Plugin::init) puts
        // CallAttemptsPage first so the parent slug exists by the time
        // this submenu is added.
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Find a 12th Stepper',
            'Find a 12th Stepper',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    /**
     * Search form + results. All inputs live in the query string so a
     * given search is bookmarkable and shareable; a GET with no
     * `location` just renders the empty form.
     */
    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $location = isset($_GET['location']) ? sanitize_text_field(wp_unslash((string) $_GET['location'])) : '';
        $accepts  = $this->readAccepts();
        $searched = $location !== '';

        ?>
        <div class="wrap">
            <h1>Find a 12th Stepper</h1>
            <p class="description">
                Search for the nearest available 12th-steppers to an area.
                Enter a postcode or place name, and optionally narrow to the
                genders a responder accepts 12th-step calls from.
            </p>

            <?php $this->renderForm($location, $accepts); ?>
            <?php if ($searched): ?>
                <?php $this->renderResults($location, $accepts); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<int, string> $accepts
     */
    private function renderForm(string $location, array $accepts): void
    {
        ?>
        <form method="get" action="" style="margin: 16px 0;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

            <p style="display: flex; flex-wrap: wrap; gap: 16px; align-items: end;">
                <label>
                    Area<br>
                    <input type="search" name="location" size="28"
                           value="<?php echo esc_attr($location); ?>"
                           placeholder="postcode or place name…">
                </label>

                <span>
                    Accepts calls from<br>
                    <?php foreach (self::GENDERS as $value => $label): ?>
                        <label style="margin-right: 12px; white-space: nowrap;">
                            <input type="checkbox" name="accepts[]"
                                   value="<?php echo esc_attr($value); ?>"
                                   <?php checked(in_array($value, $accepts, true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </span>

                <span>
                    <button type="submit" class="button button-primary">Search</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="button">Reset</a>
                </span>
            </p>
            <p class="description">
                Leave the gender boxes unticked to include every 12th-stepper regardless of who they accept.
            </p>
        </form>
        <?php
    }

    /**
     * @param array<int, string> $accepts
     */
    private function renderResults(string $location, array $accepts): void
    {
        $result = $this->resolver->resolve($location, $accepts, self::LIMIT);

        if (!$result->resolved) {
            ?>
            <div class="notice notice-warning">
                <p>Could not find the area &ldquo;<?php echo esc_html($location); ?>&rdquo;. Try a postcode or a different place name.</p>
            </div>
            <?php
            return;
        }

        // The resolver returns full Member objects on each ScoredMember;
        // we keep only the id → distance/area mapping and re-hydrate the
        // display fields through the view factory (anonymous projection),
        // matching how the rest of Reach's admin renders members.
        $scoredById = [];
        $ids = [];
        foreach ($result->members as $scored) {
            $id = $scored->member->getId();
            $scoredById[$id] = $scored;
            $ids[] = $id;
        }

        $views = $this->memberViews->createFromSource($ids);

        ?>
        <p class="description">
            <?php echo count($views); ?>
            12th-stepper<?php echo count($views) === 1 ? '' : 's'; ?> found.
        </p>

        <table class="wp-list-table widefat fixed striped" style="width: auto;">
            <thead>
                <tr>
                    <th scope="col" style="width: 220px;">12th Stepper</th>
                    <th scope="col" style="width: 180px;">Area</th>
                    <th scope="col" style="width: 100px;">Distance</th>
                    <th scope="col" style="width: 180px;">Accepts</th>
                    <th scope="col" style="width: 160px;">Mobile</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($views === []): ?>
                    <tr>
                        <td colspan="5">No 12th-steppers match this search.</td>
                    </tr>
                <?php else: foreach ($views as $view): ?>
                    <?php
                    $scored = $scoredById[$view->getId()] ?? null;
                    // Prefer the pipe entry the resolver actually matched
                    // (e.g. "Kingswood" out of "Kingswood|Hanham") so the
                    // area shown is the one the distance refers to.
                    $area = $scored->matchedArea ?? $view->getArea();
                    ?>
                    <tr>
                        <td><?php echo $this->nameCell($view); ?></td>
                        <td><?php echo esc_html($area); ?></td>
                        <td style="white-space: nowrap;">
                            <?php echo $scored !== null ? esc_html($this->formatDistance($scored)) : '&mdash;'; ?>
                        </td>
                        <td><?php echo esc_html($this->acceptsLabel($view->getAccepts())); ?></td>
                        <td><?php echo $this->mobileCell($view->getMobileNumber()); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Gender checkboxes off the query string, kept only if they are
     * values we actually offer — an unknown `accepts[]` entry is dropped
     * rather than passed to the resolver.
     *
     * @return array<int, string>
     */
    private function readAccepts(): array
    {
        $raw = $_GET['accepts'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($value));
            // Keep only known option values (the ACF `member-accepts`
            // choices), i.e. keys of the GENDERS map — not their labels.
            if (isset(self::GENDERS[$value])) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Anonymous name linked to the member's edit screen. Falls back to
     * the area, then to a placeholder, mirroring CallAttemptsPage so the
     * two admin surfaces read the same way.
     */
    private function nameCell(MemberView $view): string
    {
        $name = trim($view->getAnonymousName());
        $label = esc_html($name !== '' ? $name : '(no name)');

        $editUrl = get_edit_post_link($view->getId());
        if (is_string($editUrl) && $editUrl !== '') {
            return '<a href="' . esc_url($editUrl) . '">' . $label . '</a>';
        }
        return $label;
    }

    /**
     * Render the mobile number as a tel: link so admins can dial from
     * the search results. Blank when the member has no number on file.
     */
    private function mobileCell(string $mobile): string
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return '<em>&mdash;</em>';
        }
        return '<a href="' . esc_url('tel:' . $mobile) . '">' . esc_html($mobile) . '</a>';
    }

    /**
     * Turn a member's stored accepts values (`accepts-male`, …) into a
     * human-readable list ("Male, Female"). Unrecognised values are
     * shown verbatim rather than dropped, so unexpected data is still
     * visible to an admin.
     *
     * @param array<int, string> $accepts
     */
    private function acceptsLabel(array $accepts): string
    {
        $labels = [];
        foreach ($accepts as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $labels[] = self::GENDERS[$value] ?? $value;
        }

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    private function formatDistance(ScoredMember $scored): string
    {
        return number_format($scored->distanceKm, 1) . ' km';
    }
}
