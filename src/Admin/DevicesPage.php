<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertRepository;
use Reach\Devices\Device;
use Reach\Core\Capabilities;
use Reach\Devices\DeviceRepository;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;

/**
 * Admin view of the Hand handsets enrolled against this site, and the
 * alerts that have been sent to them.
 *
 * Two things an admin needs and cannot get anywhere else:
 *
 * <b>Revoking a handset.</b> A phone is lost, or replaced, or a
 * responder has left. Revoking cuts its token dead immediately. This is
 * a belt-and-braces control rather than the primary one — eligibility is
 * re-checked against Unity on every single request, so a responder who
 * loses their certification stops receiving alerts whether or not
 * anybody remembers to come here — but "that handset, now" is a
 * question the automatic path cannot answer.
 *
 * <b>Removing a handset.</b> The harder-edged sibling of revoking, and
 * two things rather than one: the handset is sent a notice telling it
 * it has been taken off the rota, and then its row is deleted outright.
 * Revoking leaves a record; removing is for a pairing that should not
 * be in the record at all — enrolled by mistake, or a responder asking
 * for it to be erased rather than merely disabled. The notice goes
 * first and by push only: once the row is gone the handset has no token
 * left to poll with, so a notice sent afterwards could never arrive.
 *
 * <b>Sending a test alert.</b> The whole delivery chain — service
 * account, push token, notification channel, the handset's own alarm —
 * has a lot of links, most of them on someone else's infrastructure,
 * and the failure mode is silence. A button that rings handsets on
 * demand is the only practical way to know the rota is actually covered
 * before relying on it at 3am. The test alert is a real alert through
 * the real path; nothing about it is special-cased.
 *
 * The test can go to every live handset at once, or to a ticked
 * selection. The selection is what makes the button diagnostic rather
 * than merely reassuring: a broadcast that six handsets answer and a
 * seventh does not looks much like a broadcast that seven answer, and
 * ringing one phone on its own is how you find out which one is deaf.
 * Selected handsets each get their own alert rather than sharing one,
 * so the Recent alerts table answers per handset instead of hiding a
 * silent phone behind a colleague's acknowledgement.
 *
 * <b>An administrator's own message is not here.</b> It used to be a
 * second form on this screen, sharing the test alert's form element so
 * it could read the same ticked selection — a checkbox may name exactly
 * one form. It now has a screen of its own and picks its recipient by
 * name; see {@see SendMessagePage}. The test alert stayed because it is
 * not a message: it has no text, and its value is being sent to a named
 * handset from the table listing them.
 *
 * Rows are shown revoked as well as live, so the list is a record of
 * what has been enrolled rather than only what is enrolled now.
 *
 * Capability
 * ----------
 * scrutiny_view_personal_data, matching the sibling pages: the list
 * shows which responder each handset belongs to.
 */
final class DevicesPage
{
    public const PAGE_SLUG = 'reach-devices';
    /**
     * Reading the screen, and the refresh that redraws part of it: the
     * list names the responder each handset belongs to, which is a
     * personal-data read.
     *
     * Only reading. Everything that changes something has its own
     * capability below.
     */
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;

    /**
     * Revoking or removing a handset.
     *
     * Neither is a personal-data read, so neither belongs on the
     * capability above. Revoking cuts a handset off the rota, possibly
     * in the middle of a shift; removing erases its record outright and
     * cannot be undone.
     */
    private const MANAGE_CAPABILITY = Capabilities::MANAGE_DEVICES;

    /**
     * Raising the test alert.
     *
     * Its own capability rather than the one above, because pressing
     * those buttons makes every handset on the rota ring wherever it is
     * and whatever time it is, and "may see an unmasked email address"
     * does not imply that. See {@see Capabilities::SEND_ALERTS}, which
     * also explains why it is Reach's rather than one of Scrutiny's.
     *
     * Shared with {@see SendMessagePage}, which gates the same act on
     * the same capability.
     *
     * Administrators hold all three, so on an ordinary site nothing about
     * the screen changes. The split only bites where someone has given
     * the view capability to a role that should not be ringing phones or
     * cutting handsets off — which is the case it exists for.
     */
    private const SEND_CAPABILITY = Capabilities::SEND_ALERTS;
    /**
     * The two row actions are public because the rows are rendered by
     * {@see DevicesListTable}, while the handlers stay here with the
     * rest of the screen's POST plumbing. Same reasoning as
     * {@see PAGE_SLUG}.
     */
    public const REVOKE_ACTION = 'reach_revoke_device';
    public const REMOVE_ACTION = 'reach_remove_device';
    private const TEST_ALERT_ACTION = 'reach_send_test_alert';

    /**
     * The admin-ajax action behind the Recent alerts refresh.
     *
     * admin-ajax rather than a REST route: this is a chunk of admin
     * markup for one screen, gated on the same capability and nonce as
     * the screen itself, and it has no business on the plugin's public
     * REST surface where the Hand app's own routes live.
     */
    private const ALERTS_REFRESH_ACTION = 'reach_recent_alerts';

    /**
     * The admin-ajax action behind the handsets table.
     *
     * Sorting, paging and searching all come back through here rather
     * than reloading the screen, for the reason the Recent alerts
     * refresh already gives: a reload throws away the handset selection
     * an admin has just ticked, and wherever they had scrolled to.
     */
    private const HANDSETS_REFRESH_ACTION = 'reach_handsets';

    /**
     * The nonce for the test-alert form.
     *
     * Named for the screen's actions rather than for the test alert
     * because it once covered two of them. Left alone: the name is
     * verified on both sides of a live form, and renaming it would
     * invalidate any page an admin already had open.
     */
    private const ACTIONS_NONCE = 'reach_handset_actions';

    /**
     * Id of the test-alert form.
     *
     * The row checkboxes live inside the handsets table and are bound to
     * this form by their `form` attribute rather than by being nested in
     * it: the rows already carry their own Revoke and Remove forms, and
     * a form inside a form is not something a browser will parse.
     */
    private const ACTIONS_FORM_ID = 'reach-handset-actions';

    /**
     * The value the bulk-actions dropdown posts for a test alert. Must
     * match the key {@see DevicesListTable::get_bulk_actions()} offers.
     */
    private const BULK_TEST_ACTION = 'reach_test';

    /**
     * The field the bulk-actions dropdown posts under.
     *
     * <b>Deliberately not `action`.</b> admin-post.php routes on
     * $_REQUEST['action'] and POST beats the query string, so a control
     * of that name would hijack the routing of the very form it sits in.
     * Must match {@see DevicesListTable::bulk_actions()}.
     */
    private const BULK_FIELD = 'reach_bulk';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AlertRepository $alerts,
        private readonly AlertApi $alertApi,
        private readonly MemberRepository $members,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::REVOKE_ACTION, [$this, 'handleRevoke']);
        add_action('admin_post_' . self::REMOVE_ACTION, [$this, 'handleRemove']);
        add_action('admin_post_' . self::TEST_ALERT_ACTION, [$this, 'handleTestAlert']);
        add_action('wp_ajax_' . self::ALERTS_REFRESH_ACTION, [$this, 'handleRecentAlerts']);
        add_action('wp_ajax_' . self::HANDSETS_REFRESH_ACTION, [$this, 'handleHandsets']);
    }

    public function addMenu(): void
    {
        // Parent menu ("Reach") is registered by CallAttemptsPage.
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Hand devices',
            'Hand devices',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderList'],
        );
    }

    public function renderList(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // WP_List_Table lives in wp-admin/includes and is not loaded on
        // every admin request — only on the core screens that use it. A
        // plugin screen has to ask for it, and has to ask before the two
        // subclasses are autoloaded, or they extend a class that is not
        // there yet.
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // A reader who cannot send is shown no send form and no tick
        // boxes, rather than buttons that answer 403. The handler checks
        // again regardless: what the page chose to render is not a
        // permission check.
        $canSend = current_user_can(self::SEND_CAPABILITY);
        $canManage = current_user_can(self::MANAGE_CAPABILITY);

        $handsets = new DevicesListTable(
            $this->devices,
            $this->members,
            self::ACTIONS_FORM_ID,
            $canSend,
            $canManage,
        );
        $handsets->prepare_items();

        $alerts = new AlertsListTable($this->alerts, $this->members);
        $alerts->prepare_items();

        $notice = $this->notice();
        ?>
        <div class="wrap">
            <h1>Hand devices</h1>
            <?php echo $notice; ?>

            <p class="description">
                Handsets running the Hand app, each paired to one certified telephone responder.
                Alerts go to every live handset here. A responder whose certification lapses stops
                receiving them automatically &mdash; revoke a handset that is lost or replaced,
                and remove one whose record should not be kept at all.
            </p>

            <?php if ($canSend) : ?>
            <p class="description">
                <strong>Send test alert</strong> rings the ticked handsets now, through the real
                delivery path &mdash; ringing one phone on its own is how you find out
                <em>which</em> handset is deaf. To send your own wording instead, use
                <a href="<?php echo esc_url($this->sendMessageUrl()); ?>">Send Message</a>.
            </p>

            <!--
                The form the table's controls belong to.

                Empty but for its nonce, and that is the point: the tick
                boxes, the bulk-actions dropdown, its Apply button and the
                broadcast button all sit inside the table markup and reach
                this form by their `form` attribute. They have to, because
                the rows carry POST forms of their own for Revoke and
                Remove, and a form inside a form is not something a browser
                will parse.
            -->
            <form id="<?php echo esc_attr(self::ACTIONS_FORM_ID); ?>"
                  method="post"
                  action="<?php echo esc_url($this->postUrl(self::TEST_ALERT_ACTION)); ?>">
                <?php wp_nonce_field(self::ACTIONS_NONCE); ?>
            </form>
            <?php endif; ?>

            <h2 class="title">Enrolled handsets</h2>

            <div id="reach-handsets"
                 data-action="<?php echo esc_attr(self::HANDSETS_REFRESH_ACTION); ?>"
                 data-nonce="<?php echo esc_attr(wp_create_nonce(self::HANDSETS_REFRESH_ACTION)); ?>">
                <form class="search-form" method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <?php echo $handsets->searchBox(); ?>
                </form>

                <!--
                    Only this inner element is replaced on a swap.

                    The search form is deliberately outside it: rebuilding
                    the form from its own outerHTML would serialise the
                    `value` *attribute* rather than the live property, so
                    the term somebody had just typed would vanish from the
                    box while the table stayed filtered by it.
                -->
                <div class="reach-handsets-table">
                    <?php $handsets->display(); ?>
                </div>
            </div>

            <script>
                // Sorting, paging and searching without reloading the
                // screen.
                //
                // A reload would throw away the handsets an admin has just
                // ticked — which is the whole point of the selection — and
                // wherever they had scrolled to. So the table is fetched
                // as a fragment and swapped in, exactly as Recent alerts
                // below already does, but driven by clicks rather than a
                // timer.
                //
                // Everything is delegated from the container rather than
                // bound to the links and boxes themselves: those are
                // replaced wholesale on every swap, and a listener
                // attached to one of them would go with it.
                (function () {
                    var box = document.getElementById('reach-handsets');
                    if (!box || typeof ajaxurl === 'undefined' || typeof window.fetch !== 'function') {
                        return;
                    }

                    var busy = false;

                    box.addEventListener('click', function (event) {
                        // Sort headers and pager arrows are both links
                        // carrying the state we want in their query.
                        var link = event.target.closest('a');
                        if (!link || !box.contains(link) || !link.href) {
                            return;
                        }

                        var query = new URL(link.href, window.location.origin).searchParams;
                        if (!query.get('orderby') && !query.get('paged')) {
                            return;
                        }

                        event.preventDefault();
                        load(query);
                    });

                    box.addEventListener('submit', function (event) {
                        var form = event.target.closest('form.search-form');
                        if (!form) {
                            return;
                        }

                        event.preventDefault();
                        load(new URLSearchParams(new FormData(form)));
                    });

                    // Back and forward should move through the sorts and
                    // searches, not out of the screen entirely.
                    window.addEventListener('popstate', function () {
                        load(new URLSearchParams(window.location.search), false);
                    });

                    function load(query, push) {
                        if (busy) {
                            return;
                        }
                        busy = true;

                        var wanted = new URLSearchParams();
                        ['orderby', 'order', 'paged', 's'].forEach(function (key) {
                            if (query.get(key)) {
                                wanted.set(key, query.get(key));
                            }
                        });

                        var asked = new URLSearchParams(wanted.toString());
                        asked.set('action', box.dataset.action);
                        asked.set('_ajax_nonce', box.dataset.nonce);

                        box.style.opacity = '0.5';

                        window.fetch(ajaxurl + '?' + asked.toString(), { credentials: 'same-origin' })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error(String(response.status));
                                }
                                return response.text();
                            })
                            .then(function (html) {
                                // Only the table's own element, so the
                                // search form above it is left standing
                                // with whatever is typed in it.
                                var target = box.querySelector('.reach-handsets-table');
                                if (target) {
                                    target.innerHTML = html;
                                }

                                if (push !== false) {
                                    wanted.set('page', '<?php echo esc_js(self::PAGE_SLUG); ?>');
                                    window.history.pushState({}, '', '?' + wanted.toString());
                                }
                            })
                            .catch(function () {
                                // Fall back to the ordinary page load. A
                                // sort that silently does nothing is worse
                                // than one that costs a reload.
                                wanted.set('page', '<?php echo esc_js(self::PAGE_SLUG); ?>');
                                window.location.search = wanted.toString();
                            })
                            .finally(function () {
                                box.style.opacity = '';
                                busy = false;
                            });
                    }
                })();
            </script>

            <?php if ($canSend) : ?>
            <script>
                // Tick-all for the handset selection. Written here rather
                // than left to core's list-table JS, which this screen
                // cannot rely on: the boxes belong to the test-alert form
                // by their `form` attribute, and the table they sit in is
                // replaced on every sort — so this delegates from the
                // container rather than binding to boxes that will not
                // survive the next swap.
                (function () {
                    var box = document.getElementById('reach-handsets');
                    if (!box) {
                        return;
                    }

                    box.addEventListener('change', function (event) {
                        var table = box.querySelector('table.reach-handsets');
                        if (!table || !table.contains(event.target)) {
                            return;
                        }

                        var boxes = table.querySelectorAll('.reach-device-select');
                        // Core renders one tick-all in the head and another
                        // in the foot, and they have to agree with each other.
                        var alls = table.querySelectorAll(
                            '.check-column input[type="checkbox"]:not(.reach-device-select)');

                        if (event.target.classList.contains('reach-device-select')) {
                            var every = Array.prototype.every.call(boxes, function (b) {
                                return b.checked;
                            });
                            alls.forEach(function (all) {
                                all.checked = every;
                            });
                            return;
                        }

                        if (event.target.type === 'checkbox' && alls.length > 0) {
                            var checked = event.target.checked;
                            boxes.forEach(function (b) {
                                b.checked = checked;
                            });
                            alls.forEach(function (all) {
                                all.checked = checked;
                            });
                        }
                    });
                })();
            </script>
            <?php endif; ?>

            <h2 class="title">Recent alerts</h2>
            <p class="description">
                Refreshes itself every five seconds, so a test or a message you have just sent
                appears here &mdash; and its acknowledgements fill in &mdash; without touching the
                page. That is your proof of delivery, for messages sent from
                <a href="<?php echo esc_url($this->sendMessageUrl()); ?>">Send Message</a> as well
                as for tests; a &ldquo;sent&rdquo; notice only means Reach accepted it.
            </p>

            <div id="reach-recent-alerts"
                 data-action="<?php echo esc_attr(self::ALERTS_REFRESH_ACTION); ?>"
                 data-nonce="<?php echo esc_attr(wp_create_nonce(self::ALERTS_REFRESH_ACTION)); ?>">
                <?php $alerts->display(); ?>
            </div>

            <script>
                // Refresh the Recent alerts table on its own, rather than
                // reloading the screen. A page reload would throw away the
                // handset selection and wherever the admin had scrolled
                // to — every five seconds.
                (function () {
                    var box = document.getElementById('reach-recent-alerts');
                    if (!box || typeof ajaxurl === 'undefined' || typeof window.fetch !== 'function') {
                        return;
                    }

                    var failures = 0;
                    var timer = window.setInterval(refresh, 5000);

                    function query() {
                        var here = new URLSearchParams(window.location.search);
                        var out = new URLSearchParams();
                        out.set('action', box.dataset.action);
                        out.set('_ajax_nonce', box.dataset.nonce);
                        // Carry the sort through, or every refresh would
                        // undo the column the admin just clicked.
                        ['orderby', 'order'].forEach(function (key) {
                            if (here.get(key)) {
                                out.set(key, here.get(key));
                            }
                        });
                        return out.toString();
                    }

                    function refresh() {
                        // A backgrounded tab has nobody watching it and
                        // should not poll admin-ajax for hours.
                        if (document.hidden) {
                            return;
                        }

                        window.fetch(ajaxurl + '?' + query(), { credentials: 'same-origin' })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error(String(response.status));
                                }
                                return response.text();
                            })
                            .then(function (html) {
                                failures = 0;
                                box.innerHTML = html;
                            })
                            .catch(function () {
                                failures += 1;
                                // Stop rather than retry forever. A nonce
                                // expires after a day and a session can be
                                // signed out under the tab; either would
                                // otherwise mean a failing request every
                                // five seconds for as long as it stays open.
                                if (failures >= 3) {
                                    window.clearInterval(timer);
                                }
                            });
                    }
                })();
            </script>
        </div>
        <?php
    }

    /**
     * Serve the handsets table on its own, for a sort, a page or a
     * search.
     */
    public function handleHandsets(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        echo $this->handsetsFragment();
        wp_die();
    }

    /**
     * The handsets table as HTML.
     *
     * Split from {@see handleHandsets()} for the reason
     * {@see revokeFromRequest()} gives: everything above is a guard, and
     * the wp_die() below takes the test runner with it.
     */
    private function handsetsFragment(): string
    {
        check_ajax_referer(self::HANDSETS_REFRESH_ACTION);

        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // Core builds every sort and page link out of REQUEST_URI, which
        // during an admin-ajax request is admin-ajax.php. Left alone, the
        // first sort would replace the table with one whose links answer
        // with a bare fragment instead of the screen.
        $_SERVER['REQUEST_URI'] = $this->handsetsUri();

        $handsets = new DevicesListTable(
            $this->devices,
            $this->members,
            self::ACTIONS_FORM_ID,
            current_user_can(self::SEND_CAPABILITY),
            current_user_can(self::MANAGE_CAPABILITY),
        );
        $handsets->prepare_items();

        ob_start();
        $handsets->display();

        return (string) ob_get_clean();
    }

    /**
     * The screen's own path and query, carrying whatever sort, page and
     * search are in play — so the links inside the fragment point back
     * at the screen rather than at admin-ajax.
     */
    private function handsetsUri(): string
    {
        $args = ['page' => self::PAGE_SLUG];

        foreach (['orderby', 'order'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $args[$key] = sanitize_key($_GET[$key]);
            }
        }

        if (isset($_GET['paged']) && is_numeric($_GET['paged'])) {
            $args['paged'] = (int) $_GET['paged'];
        }

        if (isset($_GET['s']) && is_string($_GET['s'])) {
            $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
        }

        $url   = (string) add_query_arg($args, admin_url('admin.php'));
        $path  = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) && $path !== '' ? $path : '/wp-admin/admin.php')
            . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /**
     * Serve the Recent alerts table on its own, for the five-second
     * refresh.
     *
     * A fragment rather than a JSON payload the page reassembles: the
     * table is already rendered by {@see AlertsListTable}, and having the
     * browser rebuild core's list-table markup from data would be a
     * second implementation of it to keep in step with the first.
     */
    public function handleRecentAlerts(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        echo $this->recentAlertsFragment();
        wp_die();
    }

    /**
     * The Recent alerts table as HTML. Split out of
     * {@see handleRecentAlerts()} for the same reason as
     * {@see revokeFromRequest()} — everything above is a guard and the
     * `wp_die()` below takes the test runner with it.
     */
    private function recentAlertsFragment(): string
    {
        check_ajax_referer(self::ALERTS_REFRESH_ACTION);

        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // Core builds every sort link in the fragment out of REQUEST_URI,
        // which during an admin-ajax request is admin-ajax.php. Left
        // alone, the first refresh would replace the table with one whose
        // column headers link to a URL that answers with a bare fragment
        // instead of the screen. Point it back at the screen the fragment
        // is going to live on.
        $_SERVER['REQUEST_URI'] = $this->screenUri();

        $alerts = new AlertsListTable($this->alerts, $this->members);
        $alerts->prepare_items();

        ob_start();
        $alerts->display();

        return (string) ob_get_clean();
    }

    /**
     * The devices screen's own path and query, carrying whatever sort is
     * in play. Path-and-query rather than a full URL because that is what
     * `$_SERVER['REQUEST_URI']` holds and what core prefixes with the
     * host when it rebuilds the current URL.
     */
    private function screenUri(): string
    {
        $args = ['page' => self::PAGE_SLUG];
        foreach (['orderby', 'order'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $args[$key] = sanitize_key($_GET[$key]);
            }
        }

        // parse_url() rather than wp_parse_url(): the wrapper exists for
        // a PHP 5.4 bug with protocol-relative URLs, and admin_url()
        // never returns one.
        $url   = (string) add_query_arg($args, admin_url('admin.php'));
        $path  = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) && $path !== '' ? $path : '/wp-admin/admin.php')
            . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /** admin-post.php with the handler named in the query string. */
    private function postUrl(string $action): string
    {
        return (string) add_query_arg('action', $action, admin_url('admin-post.php'));
    }

    /** The screen an admin's own message is sent from. */
    private function sendMessageUrl(): string
    {
        return (string) add_query_arg(
            ['page' => SendMessagePage::PAGE_SLUG],
            admin_url('admin.php'),
        );
    }

    public function handleRevoke(): void
    {
        if (!current_user_can(self::MANAGE_CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->revokeFromRequest());
        exit;
    }

    /**
     * Apply the revoke POST and return where the browser goes next.
     *
     * Split out of {@see handleRevoke()} for the reason
     * {@see CallRequestsPage::completeFromRequest()} documents: everything
     * above is a guard, everything below is `wp_safe_redirect(); exit;`,
     * and the `exit` takes the test runner with it. Same body, same order,
     * with the target returned rather than issued.
     */
    private function revokeFromRequest(): string
    {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        check_admin_referer(self::REVOKE_ACTION . '_' . $deviceId);

        $revoked = $deviceId > 0 && $this->devices->revoke($deviceId, time());

        return $this->resultUrl($revoked ? 'revoked' : 'revoke_failed');
    }

    public function handleRemove(): void
    {
        if (!current_user_can(self::MANAGE_CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->removeFromRequest());
        exit;
    }

    /**
     * Notify the handset, delete its row, and return where the browser
     * goes next. Split out of {@see handleRemove()} for the same reason
     * as {@see revokeFromRequest()}.
     *
     * <b>The order is the whole design.</b> The notice can only reach a
     * removed handset by push: deleting the row destroys the token it
     * would poll with, so anything sent afterwards has nowhere to go and
     * nothing to authenticate. Sending first also means the dispatcher
     * can still resolve the device it is addressed to.
     *
     * The delete happens whatever the notice did. A handset that is out
     * of signal, or whose owner has already lost their certification,
     * will not hear about it — but an admin who asked for a pairing to
     * be erased asked for that, not for it to be erased only if Google
     * was reachable.
     */
    private function removeFromRequest(): string
    {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        check_admin_referer(self::REMOVE_ACTION . '_' . $deviceId);

        $device = $deviceId > 0 ? $this->devices->findById($deviceId) : null;
        if ($device === null) {
            return $this->resultUrl('remove_failed');
        }

        $this->alertApi->send([
            'kind'     => 'device_removed',
            'source'   => 'reach',
            'title'    => 'This handset has been removed',
            'body'     => 'An administrator has taken this handset off the alert rota. '
                . 'It will stop receiving alerts. Sign in again to enrol it afresh.',
            // Blue and informational: this is the app being told about its
            // own enrolment, not a responder being asked to do anything.
            // Hand intercepts it before admission and signs out, so the
            // level only governs what a handset that somehow shows it does
            // — and a removal is not worth a siren at any hour.
            'level'    => Alert::LEVEL_BLUE,
            'response' => Alert::RESPONSE_NONE,
            // Long enough to survive a phone that is briefly asleep,
            // short enough that a handset switched on next week is not
            // told about a removal it has long since noticed.
            'ttl'      => 3600,
            'target_device_id' => $device->id,
        ]);

        return $this->resultUrl($this->devices->delete($device->id) ? 'removed' : 'remove_failed');
    }

    public function handleTestAlert(): void
    {
        if (!current_user_can(self::SEND_CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->testAlertFromRequest());
        exit;
    }

    /**
     * Raise the test alert(s) and return where the browser goes next.
     * Split out of {@see handleTestAlert()} for the same reason as
     * {@see revokeFromRequest()}.
     *
     * Two scopes behind one nonce, chosen by which button was pressed
     * rather than inferred from whether anything happens to be ticked:
     * an admin who ticks three handsets and then presses "every live
     * handset" gets what the button says, not what the checkboxes hint.
     */
    private function testAlertFromRequest(): string
    {
        // ACTIONS_NONCE, not TEST_ALERT_ACTION: the form's controls live
        // inside the table and reach it by their `form` attribute, so
        // there is one nonce field for all of them. Verifying the
        // handler's own action name here instead sent every test alert to
        // "Are you sure you want to do this?".
        check_admin_referer(self::ACTIONS_NONCE);

        $who = $this->adminName();

        // The broadcast button, named rather than valued: a submit button
        // appears in the POST only when it is the one that was pressed.
        if (isset($_POST['reach_scope_all'])) {
            return $this->resultUrl(
                is_wp_error($this->sendTestAlert($who)) ? 'test_failed' : 'test_sent',
            );
        }

        // Otherwise it came from the bulk-actions dropdown, which is
        // rendered twice — top and bottom — with "-1" meaning nothing
        // was chosen. The fields are `reach_bulk` and `reach_bulk2`
        // rather than core's `action`/`action2`; see
        // {@see DevicesListTable::bulk_actions()} for why that name is
        // not available to a form posting to admin-post.php.
        //
        // <b>Nothing chosen is a refusal, not a broadcast.</b> This used
        // to read "anything but selected means all", which was safe while
        // the only ways in were two labelled buttons. A dropdown left at
        // "Bulk actions" and applied would now ring every handset on the
        // rota, which is not what pressing Apply on an unchosen action
        // asks for.
        if (!$this->bulkTestRequested()) {
            return $this->resultUrl('test_no_scope');
        }

        $devices = $this->selectedLiveDevices();
        if ($devices === []) {
            return $this->resultUrl('test_none_selected');
        }

        // One alert per handset rather than one shared between them. Each
        // then carries its own acknowledgement, so the Recent alerts
        // table answers "did this handset ring" for every phone in the
        // selection instead of letting a silent one hide behind a
        // colleague's answer.
        $failed = false;
        foreach ($devices as $device) {
            if (is_wp_error($this->sendTestAlert($who, $device->id))) {
                $failed = true;
            }
        }

        return $this->resultUrl($failed ? 'test_failed' : 'test_sent_selected');
    }

    /**
     * Whether the bulk-actions dropdown asked for a test alert.
     *
     * Both copies are read because core renders the control twice and
     * submits both; it reads the top one first and falls through to the
     * bottom when the top is unset or "-1".
     */
    private function bulkTestRequested(): bool
    {
        foreach ([self::BULK_FIELD, self::BULK_FIELD . '2'] as $key) {
            $value = isset($_POST[$key]) && is_string($_POST[$key])
                ? sanitize_key($_POST[$key])
                : '';

            if ($value === self::BULK_TEST_ACTION) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raise one test alert, for a named handset or for the whole rota.
     *
     * @return int|WP_Error The alert's id, or why it was refused.
     */
    private function sendTestAlert(string $who, int $deviceId = 0): int|WP_Error
    {
        return $this->alertApi->send([
            'kind'     => 'test',
            'source'   => 'reach',
            'title'    => 'Hand test alert',
            'body'     => 'This is a test sent from the Reach admin by ' . $who . '. No action is needed.',
            // <b>Red on purpose, and informational on purpose.</b> Red
            // because the whole value of a test is exercising the loudest
            // path there is — a test that arrived quietly would prove only
            // that quiet alerts work, which is not the question anybody
            // sends one to answer. Informational because its own body says
            // no action is needed: there is nothing here to take on, so the
            // handset offers Close.
            'level'    => Alert::LEVEL_RED,
            'response' => Alert::RESPONSE_NONE,
            // Short: a test that is still ringing handsets ten minutes
            // later is a nuisance, and its only job is to arrive now.
            'ttl'      => 300,
            'target_device_id' => $deviceId,
        ]);
    }

    /**
     * The live handsets ticked in the list, deduplicated and in the
     * order they were posted.
     *
     * Every id is resolved against the repository rather than trusted
     * from the form. A posted id is only ever a row number an admin's
     * browser sent back, and a revoked handset — whose row has no
     * checkbox to tick — must not become testable by editing one in.
     *
     * @return array<int, Device>
     */
    private function selectedLiveDevices(): array
    {
        $posted = $_POST['device_ids'] ?? [];
        if (!is_array($posted)) {
            return [];
        }

        $devices = [];
        foreach ($posted as $value) {
            $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
            if ($id <= 0 || isset($devices[$id])) {
                continue;
            }

            $device = $this->devices->findById($id);
            if ($device !== null && !$device->isRevoked()) {
                $devices[$id] = $device;
            }
        }

        return array_values($devices);
    }

    /** Who to say sent a test alert, without naming an account nobody set up. */
    private function adminName(): string
    {
        $user = wp_get_current_user();

        return $user instanceof \WP_User && $user->display_name !== ''
            ? $user->display_name
            : 'an administrator';
    }





    /**
     * The admin notice for whatever the last action did, if anything.
     *
     * These are plain text, not markup: the whole string goes through
     * esc_html() below. An HTML entity written here reaches the admin as
     * the literal characters &mdash; so punctuation is the character
     * itself.
     */
    private function notice(): string
    {
        $messages = [
            'revoked'       => ['success', 'Handset revoked. It will stop receiving alerts immediately.'],
            'revoke_failed' => ['error', 'That handset could not be revoked — it may already have been.'],
            'removed'       => ['success', 'Handset removed. Its record here is gone, and a notice has been sent telling it so — a handset that is out of signal will simply stop authenticating instead.'],
            'remove_failed' => ['error', 'That handset could not be removed — it may already have been.'],
            'test_sent'     => ['success', 'Test alert sent. Every live handset should be ringing.'],
            'test_sent_selected' => ['success', 'Test alert sent. The selected handsets should be ringing.'],
            'test_none_selected' => ['warning', 'Tick at least one live handset before sending to a selection.'],
            'test_no_scope' => ['warning', 'Choose "Send test alert" from the bulk actions, or use the button to send to every live handset.'],
            'test_failed'   => ['error', 'The test alert could not be sent. Check the Reach log for the reason.'],
        ];

        $key = isset($_GET['reach_result']) && is_string($_GET['reach_result'])
            ? sanitize_key($_GET['reach_result'])
            : '';

        if (!isset($messages[$key])) {
            return '';
        }

        [$type, $text] = $messages[$key];

        return '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>'
            . esc_html($text) . '</p></div>';
    }

    /** The list URL, flagged with what the last action did. */
    private function resultUrl(string $result): string
    {
        return (string) add_query_arg(
            ['page' => self::PAGE_SLUG, 'reach_result' => $result],
            admin_url('admin.php'),
        );
    }
}
