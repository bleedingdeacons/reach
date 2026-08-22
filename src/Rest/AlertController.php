<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\AlertContactRepository;
use Reach\Alerts\AlertRepository;
use Reach\Devices\CurrentDevice;
use Reach\Devices\DeviceRepository;
use Reach\Logger\HasLogger;
use Reach\Devices\Device;
use Scrutiny\Audit\Interfaces\AuditLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * REST controller: the Hand app collecting and acknowledging alerts.
 *
 * Two routes, both authenticated by the device's bearer token:
 *
 *   GET  /reach/v1/alerts              → alerts this handset should ring about
 *   POST /reach/v1/alerts/{id}/ack     → this handset has alarmed for one
 *
 * <b>Why a poll exists at all when there is push.</b> Push is the fast
 * path, not the reliable one. FCM does not cover the Windows and macOS
 * heads at all; on mobile it can be delayed by battery management,
 * dropped while the handset is out of signal, or silently broken by a
 * rotated registration token. The poll is what makes the feature
 * dependable: every alert is stored before any push is attempted, and a
 * handset that asks will always be told. A responder's phone that has
 * been in a tunnel for ten minutes catches up the moment it surfaces.
 *
 * The poll deliberately has no client-side cursor. What a handset has
 * already dealt with is recorded server-side as an acknowledgement —
 * see {@see AlertRepository::pendingFor()} for why a cursor held on the
 * handset is the wrong place for it.
 *
 * Acknowledging means "this handset has raised the alarm for this
 * alert", not "a responder has dealt with the call". It is what stops
 * the same alert ringing twice, and what lets an admin see whether an
 * alert reached anybody at all.
 */
final class AlertController
{
    use HasLogger;
    use RequiresSecureTransport;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    public const NAMESPACE = 'reach/v1';

    /**
     * Most alerts a single poll returns.
     *
     * A handset that has been off for a long time will have more than
     * this waiting; it gets them in batches, oldest first, and the
     * unacknowledged remainder is still there on the next poll. The cap
     * exists so one very stale handset cannot ask for a response
     * containing every alert of the week.
     */
    private const MAX_PER_POLL = 20;

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertContactRepository $contacts,
        private readonly CurrentDevice $currentDevice,
        private readonly AuditLogger $auditLogger,
        private readonly DeviceRepository $devices,
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
            '/alerts',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'pending'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/alerts/(?P<id>\d+)/contact',
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
            '/alerts/unreadable',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'unreadable'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/alerts/(?P<id>\d+)/ack',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'acknowledge'],
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
    }

    /**
     * Everything this handset should currently be ringing about.
     */
    public function pending(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $alerts = $this->alerts->pendingFor(
            $device->memberEmail,
            $device->id,
            $now,
            self::MAX_PER_POLL,
        );

        return new WP_REST_Response([
            'alerts' => array_map(static fn($alert) => $alert->toArray(), $alerts),
            // Echoed so a handset can detect a clock that has drifted far
            // enough to make its own expiry arithmetic wrong.
            'now' => $now,
        ], 200);
    }

    /**
     * The contact details attached to an alert.
     *
     * <b>The one endpoint in this controller that returns personal
     * data</b>, and shaped accordingly. The details are never in the
     * poll response, never in the push payload, and therefore never on a
     * lock screen; a responder asks for them explicitly, over TLS,
     * having opened the alert — and every such read is written to
     * Scrutiny's audit trail.
     *
     * That audit entry is the point. Reach's existing promise is that a
     * regulator can answer "which user saw this personal data, and
     * when"; an alert contact is personal data reaching a responder, so
     * it is answerable the same way.
     */
    public function contact(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $alertId = (int) $request->get_param('id');
        $alert = $this->alerts->findById($alertId);
        if ($alert === null || !$this->maySee($alert, $device)) {
            // Same 404 for "no such alert" and "not yours": which alerts
            // exist is not something one responder should learn about
            // another's.
            return new WP_Error('reach_unknown_alert', 'No such alert.', ['status' => 404]);
        }

        $contact = $this->contacts->find($alertId);
        if ($contact === '') {
            return new WP_REST_Response(['alert_id' => $alertId, 'contact' => ''], 200);
        }

        $member = $this->currentDevice->memberFor($device);
        $this->auditLogger->log(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $member !== null ? $member->getId() : 0,
            'alert_contact',
            'Alert contact viewed'
                . ($alert->reference !== '' ? ';ref:' . $alert->reference : '')
                . ';alert:' . $alertId,
        );

        return new WP_REST_Response([
            'alert_id' => $alertId,
            'contact'  => $contact,
        ], 200);
    }

    /**
     * Whether a handset could legitimately have been sent this alert.
     * Shared by the acknowledge and contact paths so the two cannot
     * drift — one deciding a responder may read a contact while the
     * other says the alert is not theirs.
     */
    private function maySee(Alert $alert, Device $device): bool
    {
        // A device-targeted alert carries no address, so its device id
        // has to be checked before the email — otherwise the empty
        // address reads as a broadcast and every handset may see it.
        if ($alert->isDeviceTargeted()) {
            return $alert->targetDeviceId === $device->id;
        }

        return $alert->isBroadcast() || $alert->targetEmail === $device->memberEmail;
    }

    /**
     * Record that this handset has alarmed for an alert.
     */
    public function acknowledge(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $alertId = (int) $request->get_param('id');
        $alert = $this->alerts->findById($alertId);
        if ($alert === null) {
            return new WP_Error('reach_unknown_alert', 'No such alert.', ['status' => 404]);
        }

        // A handset may only acknowledge an alert it could have been
        // sent. Without this check any device could mark another
        // responder's targeted alert as handled, and the admin view
        // would show it answered by someone who never saw it.
        if (!$this->maySee($alert, $device)) {
            return new WP_Error('reach_unknown_alert', 'No such alert.', ['status' => 404]);
        }

        $this->alerts->acknowledge($alertId, $device->id, $device->memberEmail, $now);

        // Always 200, including for a repeat. The acknowledgement is
        // idempotent at the storage layer, and a handset retrying after
        // a dropped response has achieved what it asked for.
        return new WP_REST_Response(['acknowledged' => true, 'alert_id' => $alertId], 200);
    }

    /**
     * A handset reporting that it could not read an alert.
     *
     * <b>Why the handset has to be the one to say.</b> Reach can already
     * see that a device row has no key — it refuses to send to those, and
     * says so on the dashboard. What it cannot see is a handset whose own
     * copy has gone: a reinstall, a restore from a backup that skipped the
     * keystore, a lock-screen change that invalidated it. From here that
     * handset looks perfectly healthy, right up until an alert it cannot
     * open, and the only symptom would be a responder who does not answer.
     *
     * <b>Deliberately carries nothing but the fact.</b> No alert id, no
     * reason, no diagnostic. The remedy is the same whatever the cause —
     * sign in again — so a taxonomy of failures would be detail nobody
     * acts on, and every field is one more thing a handset in a bad state
     * can get wrong.
     *
     * Answers 204 whether or not the row moved. A handset reporting the
     * same fault on three alerts in a row is not an error, and there is
     * nothing it could usefully do with a different answer.
     */
    public function unreadable(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        $this->devices->markKeyFault($device->id, $now);

        self::logWarning('Handset reported it could not read an alert', [
            'device'    => $device->id,
            'responder' => $device->memberEmail,
            'remedy'    => 'The responder should sign in again to be issued a new key.',
        ]);

        return new WP_REST_Response(null, 204);
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
