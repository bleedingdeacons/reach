<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\MemberRepository;

/**
 * Turns a responder's email address into the two things an admin table
 * needs from it: the name to show, and the cell to render.
 *
 * <b>Why two.</b> Both list tables on the Hand devices screen sort on
 * columns that name responders, and both render those names as links.
 * Sorting the rendered cell would sort on its markup — every linked name
 * filed under "<", every unlinked one after it, which is to say every
 * handset whose member record has gone missing gathered into a block of
 * its own instead of sitting under the address it displays. So the sort
 * takes {@see name()} and the column takes {@see cell()}, and the two
 * always agree because the second is built from the first.
 *
 * <b>Why a class.</b> {@see DevicesListTable} and {@see AlertsListTable}
 * both needed this, and both needed it memoised: a responder with a
 * phone and a tablet is one lookup, an alert acknowledged twice by the
 * same person is one lookup, and a sort comparator that fetched would
 * fetch O(n log n) times. One presenter per render holds that cache for
 * both.
 *
 * ({@see CallAttemptsPage::responderCell()} is a third copy of the same
 * idea, predating this and left alone: it is a different screen with its
 * own tests, and folding it in is a tidy-up rather than part of this.)
 *
 * The fallbacks are the ones the whole suite uses. No member record
 * means the address is all there is to show, and an address here is
 * itself the diagnostic — the record was deleted, or no longer matches.
 * No edit link means the current user cannot edit that member, and a
 * link would only lead to a permissions error.
 */
final class ResponderPresenter
{
    /** @var array<string, string> */
    private array $names = [];

    /** @var array<string, string> */
    private array $cells = [];

    public function __construct(private readonly MemberRepository $members)
    {
    }

    /**
     * The responder's anonymous name, or their address where Unity knows
     * no member or the member has no name. Plain text: this is what a
     * sort compares.
     */
    public function name(string $email): string
    {
        if (array_key_exists($email, $this->names)) {
            return $this->names[$email];
        }

        $member = $this->members->findByEmail($email);
        $name   = $member !== null ? trim($member->getAnonymousName()) : '';

        return $this->names[$email] = $name !== '' ? $name : $email;
    }

    /**
     * {@see name()} linked to the responder's member record.
     *
     * Returns pre-escaped HTML — a caller must not escape it again.
     */
    public function cell(string $email): string
    {
        if (array_key_exists($email, $this->cells)) {
            return $this->cells[$email];
        }

        $label  = esc_html($this->name($email));
        $member = $this->members->findByEmail($email);

        if ($member !== null) {
            $editUrl = get_edit_post_link($member->getId());
            if (is_string($editUrl) && $editUrl !== '') {
                $label = '<a href="' . esc_url($editUrl) . '">' . $label . '</a>';
            }
        }

        return $this->cells[$email] = $label;
    }
}
