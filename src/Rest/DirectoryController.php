<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\RecipientResolver;
use Reach\Core\RateLimiter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Logger\HasLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * REST controller: who a handset may address a message to.
 *
 *   GET /reach/v1/members              → the member directory, as a picker sees it
 *   GET /reach/v1/committees           → the committee tree, likewise
 *   GET /reach/v1/members/<id>/contact → one member's phone numbers
 *
 * <b>Names and home groups. No email addresses, ever.</b> A member is chosen by
 * id and resolved to an address server-side by
 * {@see RecipientResolver::forMemberId()}, so one responder never learns
 * another's email in order to message them. That is not incidental
 * tidiness — it is what makes a directory on every handset acceptable at
 * all. The anonymous name and the home group are the form this suite
 * shows people; Integrity returns the same anonymous name without any
 * clear permission, and neither field is audited for the same reason.
 *
 * <b>Phone numbers are the exception, and only one member at a time.</b>
 * {@see contact()} hands a handset the numbers for a single member it
 * names, so a responder can ring the person they have just picked. They
 * are deliberately not part of the list payload: that route returns the
 * whole directory two hundred rows at a time, and numbers in it would
 * let one handset copy every number an intergroup holds in a couple of
 * requests. Per member the same sweep costs a request each, against a
 * throttle ({@see CONTACT_MAX}) and leaving an audit row per name taken
 * ({@see auditExposure()}). The email address stays server-side either
 * way — the guarantee above is about addresses and is untouched.
 *
 * <b>Everybody is listed, and the unreachable are labelled.</b> Hiding
 * members with no handset would leave a sender wondering where somebody
 * went, and the labelled version answers it on the spot — the same
 * reasoning {@see RecipientResolver::committeeLabels()} applies to
 * committees with nobody on them. The send is refused plainly if an
 * unreachable member is picked anyway.
 *
 * Authenticated exactly as the alert routes are: TLS, then the device
 * token, then {@see \Reach\Devices\ResponderGate} inside
 * {@see CurrentDevice::fromRequest()}. There is no capability check
 * because there is no WordPress user behind a handset — see
 * {@see AlertController::raise()}.
 */
final class DirectoryController
{
    use HasLogger;
    use RequiresSecureTransport;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    public const NAMESPACE = 'reach/v1';

    /**
     * Page size, and its ceiling.
     *
     * An intergroup is hundreds of members, not tens of thousands, so
     * the cap is about one badly-formed request rather than about
     * scale — and a handset scrolling a picker wants a page it can
     * render, not the lot.
     */
    private const PER_PAGE = 50;
    private const PER_PAGE_MAX = 200;

    /**
     * Contact lookups one responder may make per window, and the window.
     *
     * The list route hands out names; this one hands out numbers, and
     * that difference is what the cap answers for. A responder working
     * a message or returning a call looks somebody up once, sometimes a
     * handful of times. Sixty an hour leaves that untouched while
     * turning a directory sweep into something that takes most of a day
     * and leaves an audit row behind for every name taken.
     *
     * The same ceiling the find page's search carries, deliberately:
     * that endpoint governs this very exposure from the browser side,
     * and a number is no less personal for having been fetched by a
     * handset. In data terms this is the stricter of the two — a search
     * returns up to fifty members for one hit of its counter, this
     * returns one.
     *
     * Keyed on the responder, not the handset. The alert throttle keys
     * on the device because what it guards against is one handset stuck
     * in a retry loop; what this guards against is a person copying a
     * directory, and a person with a phone and a tablet should not get
     * twice the allowance for it.
     */
    private const CONTACT_MAX = 60;
    private const CONTACT_WINDOW = 60 * 60;

    /**
     * Fields counted as a personal-data view when a member's numbers are
     * handed to a handset, and the one that is only counted for the
     * members who have it.
     *
     * Same names and the same split as
     * {@see NearestMembersController::AUDITED_FIELDS} — this is the same
     * data, reached by a different door, so a member's exposure history
     * should read the same either way. The reasoning for logging the
     * landline only when there is one to log lives there.
     */
    private const AUDITED_FIELDS = ['mobile_number'];
    private const AUDITED_LANDLINE_FIELD = 'landline_number';

    public function __construct(
        private readonly CurrentDevice $currentDevice,
        private readonly MemberRepository $members,
        private readonly GroupRepository $groups,
        private readonly RecipientResolver $recipients,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/members',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'members'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'search' => [
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/members/(?P<id>\d+)/contact',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'contact'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/committees',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'committees'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * A page of the member directory.
     */
    public function members(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $device = $this->currentDevice->fromRequest($request, time());
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $page     = max(1, (int) $request->get_param('page'));
        $perPage  = (int) $request->get_param('per_page');
        $perPage  = $perPage > 0 ? min($perPage, self::PER_PAGE_MAX) : self::PER_PAGE;
        $search   = (string) ($request->get_param('search') ?? '');

        $args = [
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        $members = $this->members->findAll($args);

        return new WP_REST_Response([
            'members' => $this->present($members),
            'page'    => $page,
            'total'   => $this->members->count($search !== '' ? ['s' => $search] : []),
        ], 200);
    }

    /**
     * One member's phone numbers, so the handset can ring them.
     *
     * The picker names people; this answers "what do I dial for that
     * one". It takes a member id and returns that member's numbers
     * alone — there is no bulk form of this route, and the reasoning is
     * in the class docblock.
     *
     * <b>Everyone in the picker can be revealed.</b> No further gate is
     * applied because there is no coherent one to apply: the list shows
     * the whole directory, this route follows the list, and the
     * 12th-stepper flag that bounds the find page would be the wrong
     * test here — an intergroup officer a responder needs to ring is
     * often not a 12th-stepper at all. What bounds this route is the
     * throttle and the audit row, not a narrower population.
     *
     * A member with no numbers on file is a 200 with two empty strings,
     * not a 404: they exist, the handset asked a fair question, and the
     * answer is that there is nothing to dial. The lookup is still
     * audited — an exposure of nothing is the honest record of what
     * happened, and it is the sweeping that these rows exist to make
     * visible, not the yield.
     */
    public function contact(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $device = $this->currentDevice->fromRequest($request, time());
        if ($device === null) {
            return $this->notAuthenticated();
        }

        if ($this->overContactLimit($device)) {
            return new WP_Error(
                'reach_rate_limited',
                'Too many contact lookups in a short time. Please wait a little while and try again.',
                ['status' => 429],
            );
        }

        $memberId = (int) $request->get_param('id');
        $member = $memberId > 0 ? $this->members->findById($memberId) : null;
        if ($member === null) {
            return new WP_Error('reach_unknown_member', 'No such member.', ['status' => 404]);
        }

        $this->auditExposure($member, $device);

        return new WP_REST_Response([
            'id'              => $member->getId(),
            'mobile_number'   => trim($member->getMobileNumber()),
            'landline_number' => trim($member->getLandlineNumber()),
            // Which number to ring, as the enum's own value, exactly as
            // /nearest-members reports it — one contract for this field
            // across the API, so a handset needs one model for both.
            // It is only meaningful when both numbers are present: ACF
            // keeps the last saved choice for a field its conditional
            // logic has hidden, so a member whose landline was deleted
            // can still carry a stored preference for it. The caller
            // checks the number is there before honouring the tag, as
            // find.js does.
            'preferred_contact' => $member->getPreferredContact()->value,
        ], 200);
    }

    /** Whether this responder has looked up too many numbers too quickly. */
    private function overContactLimit(Device $device): bool
    {
        return $this->rateLimiter->overLimit(
            'directory-contact:' . $device->memberEmail,
            self::CONTACT_MAX,
            self::CONTACT_WINDOW,
        );
    }

    /**
     * Record that this handset was shown this member's numbers.
     *
     * Subject is the member whose numbers were handed over, not the
     * responder who asked — "who saw this number" is the question these
     * rows answer, so the row has to hang off the person the number
     * belongs to. The asker is named in the detail instead, in the
     * `caller:<name>#<id>` form
     * {@see NearestMembersController::callerDetail()} writes and
     * Scrutiny's admin renders as a linked "Caller: <name>".
     */
    private function auditExposure(Member $member, Device $device): void
    {
        $fields = self::AUDITED_FIELDS;
        if (trim($member->getLandlineNumber()) !== '') {
            $fields[] = self::AUDITED_LANDLINE_FIELD;
        }

        $this->auditLogger->logBatch(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $member->getId(),
            $fields,
            $this->callerDetail($device),
        );
    }

    /**
     * The audit-detail string naming the responder behind this handset.
     *
     * Never the email address, and never the device label: the
     * anonymous name is the form this suite writes people down in, and
     * Scrutiny's contract forbids raw PII in `detail`. A handset whose
     * member no longer resolves — revoked between the gate check and
     * here — is logged as unknown rather than not logged, because the
     * lookup happened either way.
     */
    private function callerDetail(Device $device): string
    {
        $caller = 'unknown';

        $member = $this->currentDevice->memberFor($device);
        if ($member !== null) {
            $name = trim($member->getAnonymousName());
            if ($name !== '') {
                $caller = sprintf('%s#%d', $name, $member->getId());
            }
        }

        return sprintf('caller:%s', $caller);
    }

    /**
     * The committee tree, flattened depth-first with a depth on each row.
     *
     * <b>Keyed by slug, never by term id.</b> The tree is built by hand
     * in wp-admin on each site, so the same committee has different term
     * ids on dev, test and production — an id a handset cached would be
     * right on one machine and point at something else on the next. See
     * {@see \Unity\Committees\Interfaces\CommitteeRepository}.
     */
    public function committees(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $device = $this->currentDevice->fromRequest($request, time());
        if ($device === null) {
            return $this->notAuthenticated();
        }

        return new WP_REST_Response([
            'committees' => $this->recipients->committeeTree(),
        ], 200);
    }

    /**
     * Turn a page of members into what the picker shows.
     *
     * <b>Home groups are resolved in one pass, not one per row.</b> The
     * ids are gathered first and fetched with a single
     * `findAll(['post__in' => …])`, so the per-row lookups that follow
     * land on WordPress's object cache — the same batching
     * {@see \Reach\Admin\CallAttemptsPage} does, and for the same reason:
     * a page of fifty members is otherwise fifty queries.
     *
     * @param array<int, Member> $members
     * @return array<int, array{id: int, anonymous_name: string, home_group: string, reachable: bool}>
     */
    private function present(array $members): array
    {
        $groupIds = [];
        foreach ($members as $member) {
            $groupId = $member->getHomeGroup();
            if ($groupId > 0) {
                $groupIds[$groupId] = $groupId;
            }
        }

        $groupNames = $this->groupNames($groupIds);

        $out = [];
        foreach ($members as $member) {
            $email = trim($member->getPersonalEmail());

            $out[] = [
                'id' => $member->getId(),
                // The name, never an address. A member with no anonymous
                // name shows as unnamed rather than falling back to their
                // email the way the admin screens do — this list is read
                // on a handset, not by an administrator diagnosing a
                // missing record. Same reasoning as
                // AlertController::responderName().
                'anonymous_name' => $this->name($member),
                'home_group'     => $groupNames[$member->getHomeGroup()] ?? '',
                // Whether a message would actually arrive. An address
                // with no live handset behind it is listed and labelled,
                // not hidden.
                'reachable' => $email !== '' && $this->recipients->isReachable($email),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, int> $groupIds
     * @return array<int, string>
     */
    private function groupNames(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $names = [];
        foreach ($this->groups->findAll(['post__in' => array_values($groupIds)]) as $group) {
            $names[$group->getId()] = $group->getTitle();
        }

        return $names;
    }

    private function name(Member $member): string
    {
        $name = trim($member->getAnonymousName());

        return $name !== '' ? $name : '(no name)';
    }

    private function notAuthenticated(): WP_Error
    {
        return new WP_Error(
            'reach_device_not_authenticated',
            'This device is not signed in.',
            ['status' => 401],
        );
    }
}
