<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\RecipientResolver;
use Reach\Devices\CurrentDevice;
use Reach\Logger\HasLogger;
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
 *   GET /reach/v1/members     → the member directory, as a picker sees it
 *   GET /reach/v1/committees  → the committee tree, likewise
 *
 * <b>Names and home groups. No addresses, ever.</b> A member is chosen by
 * id and resolved to an address server-side by
 * {@see RecipientResolver::forMemberId()}, so one responder never learns
 * another's email in order to message them. That is not incidental
 * tidiness — it is what makes a directory on every handset acceptable at
 * all. The anonymous name and the home group are the form this suite
 * shows people; Integrity returns the same anonymous name without any
 * clear permission, and neither field is audited for the same reason.
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

    public function __construct(
        private readonly CurrentDevice $currentDevice,
        private readonly MemberRepository $members,
        private readonly GroupRepository $groups,
        private readonly RecipientResolver $recipients,
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
