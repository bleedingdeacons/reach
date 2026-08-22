<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Unity\Members\Interfaces\MemberRepository;
use WP_List_Table;

/**
 * The enrolled-handsets table on {@see DevicesPage}, as a core list
 * table.
 *
 * Built on WP_List_Table rather than hand-rolled markup so the screen
 * gets what every other WordPress list gets for free and gets right:
 * sortable column headers with the arrow affordance and the keyboard
 * behaviour admins already know, real pagination, the responsive
 * single-column collapse, and row hover. The hand-written table this
 * replaces had none of it — it even computed a page offset it then
 * rendered no links for, so page two was reachable only by editing the
 * URL by hand.
 *
 * <b>Sorting happens over the whole list, not the page in hand.</b>
 * Ordering only the fifty rows fetched would order the page rather than
 * the list, and "oldest handset" would mean "oldest of the fifty this
 * page happened to hold". Seven of the eight sortable columns are
 * columns of the devices table, so they sort in SQL:
 * {@see DeviceRepository::list()} takes the column and direction and
 * whitelists them, and this class maps its own column keys onto the
 * names the repository knows.
 *
 * <b>Responder is the exception, and sorts on the name shown.</b> That
 * name comes from Unity, resolved per row, and is not in the devices
 * table at all — so there is no ORDER BY that can produce it. Sorting
 * on `member_email` instead was the first answer here and was the wrong
 * one: a column header that reorders the list by something other than
 * what the column displays is a column header that lies. So this one
 * reads every row, resolves the names, sorts, and then takes the page.
 *
 * Reading the whole table to sort one column is a real cost and a
 * deliberate one. It is bounded by what this table is — one handset per
 * certified telephone responder in one intergroup — and it is not a new
 * order of magnitude for this plugin:
 * {@see DeviceRepository::findAllLive()} already loads every live row on
 * every broadcast alert. {@see ResponderPresenter} resolves each
 * distinct responder once, so a responder with a phone and a tablet
 * costs one lookup, not two, and the sort comparator costs none at all.
 *
 * The tick boxes belong to the test-alert form by `form` attribute
 * rather than by nesting: each row already carries its own Revoke and
 * Remove forms, and a form inside a form is not something a browser
 * will parse. That is why the form's id is passed in.
 */
final class DevicesListTable extends WP_List_Table
{
    public const PER_PAGE = 50;

    /**
     * How many rows the whole-table read for the Responder sort takes at
     * a time. 500 because that is where
     * {@see \Reach\Devices\WpdbDeviceRepository::list()} clamps a page,
     * so it is the largest single fetch the contract allows; the read
     * loops until it has everything.
     */
    private const FETCH_CHUNK = 500;

    /** Names and linked cells for the Responder column, memoised. */
    private readonly ResponderPresenter $responders;

    /**
     * Column key to the repository column it sorts on.
     *
     * Two vocabularies, deliberately kept apart: the keys are this
     * screen's, the values are the devices table's, and neither has to
     * follow the other when one changes.
     *
     * `responder` is absent on purpose — it is sortable, but not by any
     * column of this table. See {@see pageSortedByResponderName()}.
     */
    private const SORT_FIELDS = [
        'device'    => 'label',
        'platform'  => 'platform',
        'delivery'  => 'push_provider',
        'enrolled'  => 'created_at',
        'last_seen' => 'last_seen_at',
        'status'    => 'revoked_at',
    ];

    public function __construct(
        private readonly DeviceRepository $devices,
        MemberRepository $members,
        private readonly string $testFormId,
        private readonly bool $canSend = true,
        private readonly bool $canManage = true,
    ) {
        $this->responders = new ResponderPresenter($members);

        parent::__construct([
            'singular' => 'handset',
            'plural'   => 'handsets',
            'ajax'     => false,
        ]);
    }

    /**
     * Two columns exist only for readers who can act on a row.
     *
     * The tick column's whole purpose is choosing who a test or a
     * message goes to, so without the send capability it is a column of
     * controls wired to a form that is not on the page. The actions
     * column holds nothing but the Revoke and Remove buttons, so without
     * the manage capability it is an empty column with a blank heading.
     *
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return array_filter([
            'cb'        => $this->canSend ? '<input type="checkbox" />' : '',
            'responder' => 'Responder',
            'device'    => 'Device',
            'platform'  => 'Platform',
            'delivery'  => 'Delivery',
            'enrolled'  => 'Enrolled',
            'last_seen' => 'Last seen',
            'status'    => 'Status',
            'actions'   => $this->canManage ? '&nbsp;' : '',
        ], static fn(string $label): bool => $label !== '');
    }

    /**
     * The sortable columns, and which way each sorts on first click.
     *
     * The two timestamps sort newest-first to begin with, because "when
     * did this last check in" is nearly always asked about the recent
     * end. The text columns sort A–Z. Status sorts live handsets first:
     * a live row has no revocation timestamp, so it sorts as NULL, which
     * MySQL puts at the ascending end.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'responder' => ['responder', false],
            'device'    => ['device', false],
            'platform'  => ['platform', false],
            'delivery'  => ['delivery', false],
            'enrolled'  => ['enrolled', true],
            'last_seen' => ['last_seen', true],
            'status'    => ['status', false],
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'responder',
        ];

        $total  = $this->devices->countAll();
        $page   = $this->get_pagenum();
        $column = $this->requestedColumn();
        $order  = $this->requestedOrder();

        $this->items = $column === 'responder'
            ? $this->pageSortedByResponderName($page, $order, $total)
            : $this->devices->list(
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
                self::SORT_FIELDS[$column] ?? '',
                $order,
            );

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    public function no_items(): void
    {
        echo 'No handsets have been enrolled yet.';
    }

    /**
     * One page of handsets ordered by the responder name the Responder
     * column displays.
     *
     * The whole table is read because the name is not in it — see the
     * class docblock on why that is affordable here and why sorting by
     * email instead was rejected. The read goes in the repository's
     * default order, which is fully determined (it tails with id DESC),
     * so the chunks join up without a row appearing twice or not at all.
     *
     * Names are resolved into an array before the sort rather than inside
     * the comparator: a comparator that fetches is a comparator that
     * fetches O(n log n) times.
     *
     * Ties fall back to id, so two handsets belonging to the same
     * responder keep a stable order between pages instead of swapping
     * places on a reload.
     *
     * @return array<int, Device>
     */
    private function pageSortedByResponderName(int $page, string $order, int $total): array
    {
        $devices = [];
        for ($offset = 0; $offset < $total; $offset += self::FETCH_CHUNK) {
            $chunk = $this->devices->list(self::FETCH_CHUNK, $offset);
            if ($chunk === []) {
                break;
            }

            $devices = array_merge($devices, $chunk);
        }

        $names = [];
        foreach ($devices as $device) {
            $names[$device->id] = $this->responders->name($device->memberEmail);
        }

        $descending = $order !== 'asc';

        usort($devices, static function (Device $a, Device $b) use ($names, $descending): int {
            $compared = strcasecmp($names[$a->id] ?? '', $names[$b->id] ?? '');
            if ($compared === 0) {
                $compared = $a->id <=> $b->id;
            }

            return $descending ? -$compared : $compared;
        });

        return array_slice($devices, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
    }

    /**
     * Table classes. `reach-handsets` is what the tick-all script on the
     * page hangs off, so it finds this table rather than the alerts one
     * sitting beside it.
     *
     * @return array<int, string>
     */
    protected function get_table_classes(): array
    {
        return ['widefat', 'fixed', 'striped', 'table-view-list', 'reach-handsets'];
    }

    /**
     * The bottom nav bar, pagination only, and nothing at the top.
     *
     * Core's top nav would bring two things this screen cannot use. One
     * is the bulk-actions machinery — a nonce and an empty
     * <div class="bulkactions"> — and this table has no bulk actions:
     * the test-alert buttons are their own form above it, because the
     * tick boxes have to reach them from outside the table.
     *
     * The other is the "current page" text box, which only works inside
     * a <form> wrapped round the table. The rows carry POST forms of
     * their own, and a form inside a form is not something a browser
     * will parse — the same constraint that puts the tick boxes on a
     * `form` attribute. Core renders that box in the top nav only, so
     * keeping the bottom one alone loses a control that could not have
     * worked and keeps the arrows, which are links and need no form at
     * all.
     *
     * @param string $which
     */
    protected function display_tablenav($which): void
    {
        if ($which !== 'bottom') {
            return;
        }

        echo '<div class="tablenav bottom">';
        $this->pagination('bottom');
        echo '<br class="clear" /></div>';
    }

    /**
     * A revoked handset gets no tick box: it cannot receive a test
     * alert, and offering one would be a button that quietly does
     * nothing. {@see DevicesPage} re-checks the same thing on the way
     * back in, because a posted id is only a row number a browser sent.
     *
     * @param Device $item
     */
    protected function column_cb($item): string
    {
        if (!$item instanceof Device || $item->isRevoked()) {
            return '';
        }

        return sprintf(
            '<input type="checkbox" form="%s" name="device_ids[]" class="reach-device-select" value="%d" aria-label="%s">',
            esc_attr($this->testFormId),
            $item->id,
            esc_attr($this->selectLabel($item)),
        );
    }

    /**
     * @param Device $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        if (!$item instanceof Device) {
            return '';
        }

        return match ($column_name) {
            'responder' => $this->responders->cell($item->memberEmail),
            'device'    => esc_html($item->label !== '' ? $item->label : '—'),
            'platform'  => esc_html($item->platform),
            'delivery'  => $item->wantsPush()
                ? 'Push'
                : '<span title="This handset collects alerts by polling.">Poll</span>',
            'enrolled'  => esc_html($this->when($item->createdAt)),
            'last_seen' => esc_html($item->lastSeenAt > 0 ? $this->when($item->lastSeenAt) : '—'),
            'status'    => $item->isRevoked()
                ? '<span style="color:#b32d2e;">Revoked</span>'
                : '<span style="color:#008a20;">Live</span>',
            'actions'   => $this->rowForms($item),
            default     => '',
        };
    }

    /**
     * The per-row Revoke and Remove buttons.
     *
     * Deliberately forms rather than core's row-action links: both
     * change state, so both post with a nonce of their own. Revoke is
     * absent from a row that is already revoked — re-revoking would only
     * move a timestamp this list exists to preserve.
     *
     * Unreachable without the manage capability, because the column is
     * dropped entirely; the guard here is belt and braces, and the one
     * that counts is in {@see DevicesPage::handleRevoke()}.
     */
    private function rowForms(Device $device): string
    {
        if (!$this->canManage) {
            return '';
        }

        $post = esc_url(admin_url('admin-post.php'));
        $html = '';

        if (!$device->isRevoked()) {
            $html .= '<form method="post" style="display:inline-block;" action="' . $post . '"'
                . ' onsubmit="return confirm(&#039;Revoke this handset? It will stop receiving alerts'
                . ' immediately and the responder will need to sign in again.&#039;);">'
                . '<input type="hidden" name="action" value="' . esc_attr(DevicesPage::REVOKE_ACTION) . '">'
                . '<input type="hidden" name="device_id" value="' . $device->id . '">'
                . wp_nonce_field(DevicesPage::REVOKE_ACTION . '_' . $device->id, '_wpnonce', true, false)
                . '<button type="submit" class="button button-small">Revoke</button>'
                . '</form> ';
        }

        return $html
            . '<form method="post" style="display:inline-block;" action="' . $post . '"'
            . ' onsubmit="return confirm(&#039;Remove this handset? It will be told to sign out, and its'
            . ' record here is deleted rather than kept as history. This cannot be undone.&#039;);">'
            . '<input type="hidden" name="action" value="' . esc_attr(DevicesPage::REMOVE_ACTION) . '">'
            . '<input type="hidden" name="device_id" value="' . $device->id . '">'
            . wp_nonce_field(DevicesPage::REMOVE_ACTION . '_' . $device->id, '_wpnonce', true, false)
            . '<button type="submit" class="button button-small button-link-delete">Remove</button>'
            . '</form>';
    }

    /**
     * Which of this table's columns the request asked to sort by, or ''.
     *
     * The alerts table shares this screen and therefore shares
     * `orderby`, so a value this table does not recognise is not a
     * malformed request — it is the other table's sort, and means
     * "leave mine alone". The two sets of column keys are disjoint for
     * exactly that reason.
     */
    private function requestedColumn(): string
    {
        return isset($_GET['orderby']) && is_string($_GET['orderby'])
            ? sanitize_key($_GET['orderby'])
            : '';
    }

    private function requestedOrder(): string
    {
        return isset($_GET['order']) && is_string($_GET['order']) && strtolower($_GET['order']) === 'asc'
            ? 'asc'
            : 'desc';
    }

    /**
     * The accessible label for a row's tick box.
     *
     * Every row carrying the same label leaves a screen-reader user hearing
     * "select this handset" eight times with nothing to tell the rows apart.
     * The id is always included because it is the only thing guaranteed
     * unique — two handsets may share a label, or have none.
     */
    private function selectLabel(Device $device): string
    {
        return $device->label !== ''
            ? sprintf('Select handset %d, %s, for a test alert', $device->id, $device->label)
            : sprintf('Select handset %d for a test alert', $device->id);
    }

    private function when(int $timestamp): string
    {
        return function_exists('wp_date')
            ? (string) wp_date('Y-m-d H:i', $timestamp)
            : gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }
}
