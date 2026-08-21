<?php

/**
 * Stand-in for wp-admin's WP_List_Table.
 *
 * The one WordPress class the suite's shared stubs cannot supply. It
 * lives in wp-admin/includes rather than in the loaded core, so it is
 * outside what bleedingdeacons/wp-mocks covers — the same reason
 * dbDelta() is defined in this plugin's bootstrap and nowhere else.
 *
 * <b>What this is for, and what it is not.</b> The two list tables on
 * the Hand devices screen are tested through the page, which renders
 * them for real into an output buffer, so something has to be there to
 * extend. This reproduces the parts of the contract those subclasses
 * touch — the column plumbing, the row loop, the placeholder row, the
 * sortable header links and the pagination arguments — closely enough
 * that assertions about the rendered rows mean what they say. It does
 * not reproduce core's markup, and nothing should assert on the classes
 * or the wrappers it emits: those belong to WordPress and change with
 * it. What the subclasses put *inside* a cell is theirs, and that is
 * what the tests are about.
 *
 * Deliberately not in wp-mocks. One consumer, one screen; it earns a
 * place in the shared package when a second plugin needs it, not
 * before.
 */

declare(strict_types=1);

if (class_exists('WP_List_Table')) {
    return;
}

class WP_List_Table
{
    /** @var array<int, mixed> */
    public $items = [];

    /** @var array<string, mixed> */
    protected $_args = [];

    /** @var array<string, mixed> */
    protected $_pagination_args = [];

    /** @var array<int, mixed>|null */
    protected $_column_headers = null;

    /** @param array<string, mixed> $args */
    public function __construct($args = [])
    {
        $this->_args = is_array($args) ? $args : [];
    }

    public function prepare_items()
    {
    }

    /** @param array<string, mixed> $args */
    protected function set_pagination_args($args)
    {
        $this->_pagination_args = $args;
    }

    /** @param string $key */
    public function get_pagination_arg($key)
    {
        return $this->_pagination_args[$key] ?? 0;
    }

    public function has_items()
    {
        return $this->items !== [];
    }

    public function no_items()
    {
        echo 'No items found.';
    }

    /**
     * Core clamps this to total_pages once pagination args are known.
     * Subclasses read it before setting them — they need the page number
     * to fetch the page — so the clamp never applies and is left out.
     */
    public function get_pagenum()
    {
        return max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 0);
    }

    /** @return array<string, string> */
    public function get_columns()
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function get_sortable_columns()
    {
        return [];
    }

    /** @return array{0: array<string, string>, 1: array<int, string>, 2: array<string, mixed>, 3: string} */
    protected function get_column_info()
    {
        $headers = is_array($this->_column_headers)
            ? $this->_column_headers + [[], [], [], '']
            : [$this->get_columns(), [], $this->get_sortable_columns(), ''];

        /** @var array{0: array<string, string>, 1: array<int, string>, 2: array<string, mixed>, 3: string} $headers */
        return $headers;
    }

    public function get_column_count()
    {
        [$columns, $hidden] = $this->get_column_info();

        return count($columns) - count($hidden);
    }

    /** @return array<int, string> */
    protected function get_table_classes()
    {
        return ['widefat', 'fixed', 'striped'];
    }

    /** @param string $which */
    protected function display_tablenav($which)
    {
        if ($this->_pagination_args === []) {
            return;
        }

        echo '<div class="tablenav ' . $which . '">';
        $this->pagination($which);
        echo '</div>';
    }

    /**
     * Core's is a page-number box, four arrows and a count. Only the
     * count is reproduced: the arrows are URLs built from
     * $_SERVER['REQUEST_URI'], which is core's business and not
     * something a test should be pinned to.
     *
     * @param string $which
     */
    protected function pagination($which)
    {
        echo '<div class="tablenav-pages"><span class="displaying-num">'
            . (int) ($this->_pagination_args['total_items'] ?? 0) . ' items</span></div>';
    }

    /** @param bool $with_id */
    public function print_column_headers($with_id = true)
    {
        [$columns, $hidden, $sortable] = $this->get_column_info();

        $currentColumn = isset($_GET['orderby']) && is_string($_GET['orderby']) ? $_GET['orderby'] : '';
        $currentOrder = isset($_GET['order']) && is_string($_GET['order'])
            && strtolower($_GET['order']) === 'asc' ? 'asc' : 'desc';

        echo '<tr>';

        foreach ($columns as $key => $label) {
            $classes = ['manage-column', 'column-' . $key];

            if ($key === 'cb') {
                $classes[] = 'check-column';
                $label = '<input id="cb-select-all-1" type="checkbox" />'
                    . '<label for="cb-select-all-1"><span class="screen-reader-text">Select All</span></label>';
            }

            if (isset($sortable[$key])) {
                $spec = $sortable[$key];
                $orderby = is_array($spec) ? (string) ($spec[0] ?? $key) : (string) $spec;
                $descFirst = is_array($spec) && ($spec[1] ?? false) === true;

                if ($currentColumn === $orderby) {
                    $classes[] = 'sorted';
                    $classes[] = $currentOrder;
                    $next = $currentOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    $classes[] = 'sortable';
                    $classes[] = $descFirst ? 'desc' : 'asc';
                    $next = $descFirst ? 'desc' : 'asc';
                }

                $label = '<a href="?orderby=' . $orderby . '&#038;order=' . $next . '">'
                    . '<span>' . $label . '</span><span class="sorting-indicator"></span></a>';
            }

            if (in_array((string) $key, $hidden, true)) {
                $classes[] = 'hidden';
            }

            $tag = $key === 'cb' ? 'td' : 'th';
            $id = $with_id ? ' id="' . $key . '"' : '';

            echo '<' . $tag . $id . ' scope="col" class="' . implode(' ', $classes) . '">'
                . $label . '</' . $tag . '>';
        }

        echo '</tr>';
    }

    public function display()
    {
        $this->display_tablenav('top');

        echo '<table class="wp-list-table ' . implode(' ', $this->get_table_classes()) . '">';
        echo '<thead>';
        $this->print_column_headers();
        echo '</thead><tbody>';
        $this->display_rows_or_placeholder();
        echo '</tbody><tfoot>';
        $this->print_column_headers(false);
        echo '</tfoot></table>';

        $this->display_tablenav('bottom');
    }

    public function display_rows_or_placeholder()
    {
        if ($this->has_items()) {
            $this->display_rows();

            return;
        }

        echo '<tr class="no-items"><td class="colspanchange" colspan="' . $this->get_column_count() . '">';
        $this->no_items();
        echo '</td></tr>';
    }

    public function display_rows()
    {
        foreach ($this->items as $item) {
            $this->single_row($item);
        }
    }

    /** @param mixed $item */
    public function single_row($item)
    {
        echo '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    /** @param mixed $item */
    protected function single_row_columns($item)
    {
        [$columns, $hidden, , $primary] = $this->get_column_info();

        foreach ($columns as $key => $label) {
            if ($key === 'cb') {
                echo '<th scope="row" class="check-column">' . $this->column_cb($item) . '</th>';
                continue;
            }

            $classes = $key . ' column-' . $key;
            if ($key === $primary) {
                $classes .= ' has-row-actions column-primary';
            }
            if (in_array((string) $key, $hidden, true)) {
                $classes .= ' hidden';
            }

            echo '<td class="' . $classes . '" data-colname="' . strip_tags((string) $label) . '">';
            echo method_exists($this, 'column_' . $key)
                ? (string) call_user_func([$this, 'column_' . $key], $item)
                : (string) $this->column_default($item, (string) $key);
            echo $this->handle_row_actions($item, (string) $key, (string) $primary);
            echo '</td>';
        }
    }

    /**
     * @param mixed $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name)
    {
        return '';
    }

    /** @param mixed $item */
    protected function column_cb($item)
    {
        return '';
    }

    /**
     * @param mixed $item
     * @param string $column_name
     * @param string $primary
     */
    protected function handle_row_actions($item, $column_name, $primary)
    {
        return '';
    }

    /**
     * @param array<string, string> $actions
     * @param bool $always_visible
     */
    protected function row_actions($actions, $always_visible = false)
    {
        return '';
    }

    protected function get_default_primary_column_name()
    {
        return '';
    }

    public function get_primary_column()
    {
        return $this->get_column_info()[3];
    }

    protected function get_primary_column_name()
    {
        return $this->get_default_primary_column_name();
    }
}
