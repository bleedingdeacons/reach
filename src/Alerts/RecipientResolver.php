<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Devices\Device;
use Unity\Committees\Interfaces\Committee;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Members\Interfaces\MemberRepository;
use Reach\Devices\DeviceRepository;

/**
 * Who a message actually reaches: the handsets behind a responder, a
 * committee, or a member id.
 *
 * <b>Why this is not still inside the admin screen.</b> It was, and it
 * had to come out the moment a second caller wanted it. Hand's compose
 * screen addresses exactly the same two things wp-admin does — one
 * person, or one committee and the committees under it — and a second
 * copy of that resolution would have been two answers to "who is on
 * Public Information" that could drift apart without either side
 * noticing. {@see \Reach\Admin\SendMessagePage} and
 * {@see \Reach\Rest\AlertController} now ask the same object.
 *
 * <b>Everything here returns handsets, not people.</b> An alert is
 * delivered to a device; a responder with a phone and a tablet is two
 * deliveries. The results are keyed by device id on the way out so a
 * handset reached by two paths — somebody sitting on a committee and its
 * parent, or two member records carrying one address — is still sent one
 * copy.
 *
 * <b>Live handsets only.</b> A revoked device is not a recipient, and
 * {@see DeviceRepository::findAllLive()} is the definition. Nothing here
 * re-checks the responder gate: {@see AlertDispatcher} does that at
 * dispatch, on every send, which is the place it has to happen anyway
 * because a certification can lapse between choosing a recipient and the
 * alert going out.
 */
final class RecipientResolver
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly MemberRepository $members,
        private readonly CommitteeRepository $committees,
    ) {
    }

    /**
     * One responder's live handsets, matched on address without regard
     * to case — an admin who types an address by hand should not have to
     * match the capitalisation Unity happens to hold.
     *
     * @return array<int, Device>
     */
    public function forResponder(string $responder): array
    {
        $wanted = strtolower(trim($responder));
        if ($wanted === '') {
            return [];
        }

        $devices = [];
        foreach ($this->devices->findAllLive() as $device) {
            if (strtolower($device->memberEmail) === $wanted) {
                $devices[] = $device;
            }
        }

        return $devices;
    }

    /**
     * One member's live handsets, by Unity member id.
     *
     * <b>The id is the address Hand sends, and this is where it becomes
     * an email.</b> A handset picks a recipient out of a directory that
     * carries anonymous names and home groups and no addresses at all —
     * see {@see \Reach\Rest\DirectoryController} — so the resolution has
     * to happen server-side. That is the point: one responder never
     * learns another's address in order to message them.
     *
     * @return array<int, Device>
     */
    public function forMemberId(int $memberId): array
    {
        if ($memberId <= 0) {
            return [];
        }

        $member = $this->members->findById($memberId);
        if ($member === null) {
            return [];
        }

        $email = trim($member->getPersonalEmail());

        return $email === '' ? [] : $this->forResponder($email);
    }

    /**
     * Every live handset belonging to a committee's members, and to the
     * members of the committees under it.
     *
     * <b>Descendants are included.</b> Messaging Public Information and
     * not reaching Health or Employment would be a trap: the tree says
     * they are part of it, and choosing the parent is choosing the
     * branch. It is also what {@see CommitteeRepository::memberIdsIn()}
     * does by default, so the screen and the data agree.
     *
     * Keyed by device id so a handset is only ever sent one copy. Two
     * paths lead to the same phone: a member can hold more than one
     * committee in the branch being addressed, and two member records
     * can carry the same address. Neither is a reason to ring a phone
     * twice.
     *
     * @return array<int, Device>
     */
    public function forCommittee(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [];
        }

        $memberIds = $this->committees->memberIdsIn($slug);
        if ($memberIds === []) {
            return [];
        }

        $devices = [];

        foreach ($this->members->findAll(['post__in' => $memberIds]) as $member) {
            $email = $member->getPersonalEmail();

            if ($email === '') {
                continue;
            }

            foreach ($this->forResponder($email) as $device) {
                $devices[$device->id] = $device;
            }
        }

        return array_values($devices);
    }

    /** Whether a committee exists to be addressed at all. */
    public function committeeExists(string $slug): bool
    {
        return trim($slug) !== '' && $this->committees->findBySlug(trim($slug)) !== null;
    }

    /**
     * The committee tree flattened depth-first, as slug => label.
     *
     * Every committee is listed, including those nobody on them has a
     * handset for, with the reachable count in the label. Hiding them
     * would leave a sender wondering where a committee went; saying
     * "0 handsets" answers it on the spot, and the send is refused
     * plainly if they pick one anyway.
     *
     * The count is the branch, not the node, because that is what
     * sending to it would reach.
     *
     * @return array<string, string>
     */
    public function committeeLabels(): array
    {
        $labels = [];

        foreach ($this->committees->roots() as $root) {
            $this->collectLabel($root, 0, $labels);
        }

        return $labels;
    }

    /**
     * The same tree as structured rows, for a client that draws its own
     * indentation rather than reading dashes out of a label.
     *
     * @return array<int, array{slug: string, name: string, depth: int, handsets: int}>
     */
    public function committeeTree(): array
    {
        $rows = [];

        foreach ($this->committees->roots() as $root) {
            $this->collectRow($root, 0, $rows);
        }

        return $rows;
    }

    /**
     * Drop a sender's own handsets from a resolved set.
     *
     * Messaging a committee you sit on should not ring your own pocket,
     * and putting a job back to the rota should not hand it straight
     * back to the person who just gave it up. Matched on the address
     * rather than the device id so a responder's *other* handset is
     * dropped too — which {@see Alert::$excludeDeviceId} cannot express,
     * holding one id.
     *
     * @param array<int, Device> $devices
     * @return array<int, Device>
     */
    public function without(array $devices, string $responder): array
    {
        $wanted = strtolower(trim($responder));
        if ($wanted === '') {
            return $devices;
        }

        $kept = [];
        foreach ($devices as $device) {
            if (strtolower($device->memberEmail) !== $wanted) {
                $kept[] = $device;
            }
        }

        return $kept;
    }

    /**
     * Whether an address has any live handset behind it.
     *
     * What the directory's "reachable" flag is. Deliberately the same
     * question {@see forResponder()} answers, so a member listed as
     * reachable is one a send will actually reach.
     */
    public function isReachable(string $responder): bool
    {
        return $this->forResponder($responder) !== [];
    }

    /**
     * @param array<string, string> $into
     */
    private function collectLabel(Committee $committee, int $depth, array &$into): void
    {
        $slug  = $committee->getSlug();
        $count = count($this->forCommittee($slug));

        $into[$slug] = str_repeat('— ', $depth)
            . $committee->getName()
            . ' (' . ($count === 1 ? '1 handset' : $count . ' handsets') . ')';

        foreach ($this->committees->childrenOf($slug) as $child) {
            $this->collectLabel($child, $depth + 1, $into);
        }
    }

    /**
     * @param array<int, array{slug: string, name: string, depth: int, handsets: int}> $into
     */
    private function collectRow(Committee $committee, int $depth, array &$into): void
    {
        $slug = $committee->getSlug();

        $into[] = [
            'slug'     => $slug,
            'name'     => $committee->getName(),
            'depth'    => $depth,
            'handsets' => count($this->forCommittee($slug)),
        ];

        foreach ($this->committees->childrenOf($slug) as $child) {
            $this->collectRow($child, $depth + 1, $into);
        }
    }
}
