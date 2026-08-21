<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\AlertRepository;
use Unity\Members\Interfaces\MemberRepository;
use WP_List_Table;

/**
 * The recent-alerts table on {@see DevicesPage}, as a core list table.
 *
 * Same reasoning as {@see DevicesListTable} — core's markup brings
 * sortable headers, the responsive collapse and the row styling that
 * hand-rolled table markup only ever approximates.
 *
 * <b>Sorting happens here, not in SQL, and that is the point.</b> This
 * table is the most recent handful of alerts: a window, not a paginated
 * list. Pushing the sort down to the database would apply it *before*
 * the limit, so sorting by title would answer with the ten alerts whose
 * titles start earliest in the alphabet rather than re-ordering the ten
 * on the screen — which is the opposite of what clicking a column
 * header means. Sorting the window in PHP also lets "Acknowledged by"
 * be sortable at all: it is an aggregate over another table, assembled
 * per row, and there is no column to ORDER BY.
 *
 * The window is small by construction. Alerts are operational rather
 * than historical and are purged an hour after they expire, so the
 * whole table is short-lived and this screen only ever wants the top of
 * it.
 *
 * Its column keys are deliberately disjoint from the handsets table's.
 * Both tables live on one screen and core builds every sort link from
 * the same `orderby` query argument, so a shared key would mean sorting
 * one table silently re-sorted the other.
 */
final class AlertsListTable extends WP_List_Table
{
    /**
     * How many alerts the screen shows. Not paginated: `paged` is the
     * handsets table's, and there is only one of it on the URL.
     */
    private const SHOWN = 10;

    /** Names and linked cells for the Acknowledged by column, memoised. */
    private readonly ResponderPresenter $responders;

    public function __construct(
        private readonly AlertRepository $alerts,
        MemberRepository $members,
    ) {
        $this->responders = new ResponderPresenter($members);

        parent::__construct([
            'singular' => 'alert',
            'plural'   => 'alerts',
            'ajax'     => false,
        ]);
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'raised'       => 'When',
            'kind'         => 'Kind',
            'source'       => 'Source',
            'title'        => 'Title',
            'acknowledged' => 'Acknowledged by',
        ];
    }

    /**
     * When sorts newest-first on the first click, matching the order the
     * table arrives in; the rest sort A–Z.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'raised'       => ['raised', true],
            'kind'         => ['kind', false],
            'source'       => ['source', false],
            'title'        => ['title', false],
            'acknowledged' => ['acknowledged', false],
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'title',
        ];

        $rows = [];
        foreach ($this->alerts->list(self::SHOWN, 0) as $alert) {
            $rows[] = $this->row($alert);
        }

        $this->items = $this->sorted($rows);
    }

    public function no_items(): void
    {
        echo 'No alerts have been raised yet.';
    }

    /**
     * No tablenav. There are no bulk actions and no pages, so core's nav
     * bar would render as an empty frame above and below the table.
     *
     * @param string $which
     */
    protected function display_tablenav($which): void
    {
    }

    /** @return array<int, string> */
    protected function get_table_classes(): array
    {
        return ['widefat', 'fixed', 'striped', 'table-view-list', 'reach-alerts'];
    }

    /**
     * @param array<string, mixed> $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        if (!is_array($item)) {
            return '';
        }

        return match ($column_name) {
            'raised' => esc_html((string) ($item['raised'] ?? '')),
            'kind'   => '<code>' . esc_html((string) ($item['kind'] ?? '')) . '</code>',
            'source' => esc_html((string) ($item['source'] ?? '')),
            'title'  => esc_html((string) ($item['title'] ?? ''))
                . (($item['urgent'] ?? false) === true
                    ? ' <strong style="color:#b32d2e;">(urgent)</strong>'
                    : ''),
            'acknowledged' => (string) ($item['acknowledgedHtml'] ?? ''),
            default        => '',
        };
    }

    /**
     * One alert flattened to what the table shows.
     *
     * Every displayed value is resolved once, here, rather than in the
     * column callbacks: the sort runs over these rows, so what it
     * compares has to be what the reader sees. It also means the
     * acknowledgement lookup happens once per alert instead of once per
     * render of the column.
     *
     * `raisedAt` rides along beside the formatted `raised` so When sorts
     * chronologically rather than by the look of its own date string,
     * and `acknowledgedHtml` beside `acknowledged` for the mirror-image
     * reason: the names in that column are links, and sorting the markup
     * would file every linked one under "<".
     *
     * @return array<string, mixed>
     */
    private function row(Alert $alert): array
    {
        [$acknowledged, $acknowledgedHtml] = $this->acknowledgedBy($alert);

        return [
            'id'               => $alert->id,
            'raised'           => $this->when($alert->createdAt),
            'raisedAt'         => $alert->createdAt,
            'kind'             => $alert->kind,
            'source'           => $alert->source,
            'title'            => $alert->title,
            'urgent'           => $alert->isUrgent(),
            'acknowledged'     => $acknowledged,
            'acknowledgedHtml' => $acknowledgedHtml,
        ];
    }

    /**
     * The window in the order the request asked for, or as the
     * repository gave it — newest first — when no sort of this table's
     * was asked for.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        $column = isset($_GET['orderby']) && is_string($_GET['orderby'])
            ? sanitize_key($_GET['orderby'])
            : '';

        if (!array_key_exists($column, $this->get_sortable_columns())) {
            return $rows;
        }

        $descending = !(isset($_GET['order']) && is_string($_GET['order'])
            && strtolower($_GET['order']) === 'asc');

        usort($rows, static function (array $a, array $b) use ($column, $descending): int {
            $compared = $column === 'raised'
                ? ((int) ($a['raisedAt'] ?? 0)) <=> ((int) ($b['raisedAt'] ?? 0))
                : strcasecmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? ''));

            // Alert ids ascend with time, so ties fall back to the same
            // chronological order the table arrives in rather than to
            // whatever usort happened to do with them.
            if ($compared === 0) {
                $compared = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $descending ? -$compared : $compared;
        });

        return $rows;
    }

    /**
     * Who has alarmed for an alert — the answer to "did this reach
     * anybody" — as plain text for the sort and as linked HTML for the
     * cell.
     *
     * <b>Deduplicated by address, not by name.</b> The same person
     * acknowledging from a phone and a tablet is one answer, not two,
     * and the address is what says "same person". Deduplicating on the
     * name instead — which is what this did before the names became
     * links — would also have collapsed two different members who happen
     * to share an anonymous name into one, and they are two answers.
     *
     * @return array{0: string, 1: string}
     */
    private function acknowledgedBy(Alert $alert): array
    {
        $acks = $this->alerts->acknowledgementsFor($alert->id);
        if ($acks === []) {
            return ['Nobody yet', 'Nobody yet'];
        }

        $names = [];
        $cells = [];
        foreach ($acks as $ack) {
            $email = $ack['member_email'];
            if (isset($names[$email])) {
                continue;
            }

            $names[$email] = $this->responders->name($email);
            $cells[$email] = $this->responders->cell($email);
        }

        return [implode(', ', $names), implode(', ', $cells)];
    }

    private function when(int $timestamp): string
    {
        return function_exists('wp_date')
            ? (string) wp_date('Y-m-d H:i', $timestamp)
            : gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }
}
