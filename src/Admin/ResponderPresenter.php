<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\Member;
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
 * <b>Why a class.</b> Three screens wanted this and all three needed it
 * memoised: {@see DevicesListTable} (a responder with a phone and a
 * tablet is one lookup), {@see AlertsListTable} (an alert acknowledged
 * twice by the same person is one lookup), and {@see CallAttemptsPage},
 * where this shape started and where a paginated list often shows
 * several attempts by the same responder. A sort comparator that fetched
 * would fetch O(n log n) times.
 *
 * The memo is on the *member*, not on either output, so asking for a
 * name and then a cell for the same address is still one
 * MemberRepository::findByEmail(). That is not a micro-optimisation —
 * {@see \Reach\Tests\Admin\CallAttemptsPageTest} asserts the count, and
 * it caught this class doing it twice.
 *
 * {@see CallAttemptsPage::memberCell()} is deliberately not folded in:
 * it renders "name &middot; area" from a MemberView already in hand,
 * with its own "(member not found)" and "(no name)" markers, and that is
 * a different presenter for a different column rather than another copy
 * of this one.
 *
 * The fallbacks are the ones the whole suite uses. No member record
 * means the address is all there is to show, and an address here is
 * itself the diagnostic — the record was deleted, or no longer matches.
 * No edit link means the current user cannot edit that member, and a
 * link would only lead to a permissions error.
 */
final class ResponderPresenter
{
    /**
     * Members already looked up, keyed by address. A null value is a
     * resolved absence and must not send the lookup round again, which
     * is why every read of this is array_key_exists() and not isset().
     *
     * @var array<string, Member|null>
     */
    private array $resolved = [];

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
        if ($email === '') {
            return '';
        }

        $member = $this->member($email);
        if ($member !== null) {
            $name = trim($member->getAnonymousName());
            if ($name !== '') {
                return $name;
            }
        }

        return $email;
    }

    /**
     * {@see name()} linked to the responder's member record.
     *
     * Returns pre-escaped HTML — a caller must not escape it again.
     */
    public function cell(string $email): string
    {
        if ($email === '') {
            return '';
        }

        if (array_key_exists($email, $this->cells)) {
            return $this->cells[$email];
        }

        $label  = esc_html($this->name($email));
        $member = $this->member($email);

        if ($member !== null) {
            $editUrl = get_edit_post_link($member->getId());
            if (is_string($editUrl) && $editUrl !== '') {
                $label = '<a href="' . esc_url($editUrl) . '">' . $label . '</a>';
            }
        }

        return $this->cells[$email] = $label;
    }

    /**
     * The member behind an address, at most one lookup per address per
     * request.
     *
     * The empty address never reaches here. findByEmail('') is not a
     * harmless miss: a member with no address on file would match it,
     * and the cell would name a stranger.
     */
    private function member(string $email): ?Member
    {
        if (array_key_exists($email, $this->resolved)) {
            return $this->resolved[$email];
        }

        return $this->resolved[$email] = $this->members->findByEmail($email);
    }
}
