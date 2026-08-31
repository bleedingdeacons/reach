<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\AcknowledgementNotifier;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertContactRepository;
use Reach\Alerts\AlertReplyRepository;
use Reach\Alerts\AlertRepository;
use Reach\Alerts\MessageUuid;
use Reach\Alerts\RecipientResolver;
use Reach\Core\RateLimiter;
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

    /** Longest a reply may be. Matches the alert body it is dispatched as. */
    private const REPLY_MAX = 1000;

    /** How long an alert raised from a handset stays live. */
    private const SEND_TTL = 3600;

    /**
     * How many alerts one handset may raise, and over what window.
     *
     * <b>This is the only thing standing between the rota and a stuck
     * finger.</b> Any member with an address may send — there is no
     * capability to fail — so the protection against a handset raising a
     * hundred alerts is here rather than in an authorisation check that
     * was deliberately not written. Ten in five minutes is far above what
     * anyone types by hand and far below what a retry loop manages.
     */
    private const SEND_MAX = 10;
    private const SEND_WINDOW = 300;

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertContactRepository $contacts,
        private readonly CurrentDevice $currentDevice,
        private readonly AuditLogger $auditLogger,
        private readonly DeviceRepository $devices,
        private readonly AcknowledgementNotifier $acknowledgements,
        private readonly AlertReplyRepository $replies,
        private readonly RecipientResolver $recipients,
        private readonly AlertApi $alertApi,
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

        // Raising an alert from a handset. Registered on the collection
        // rather than a /send path because that is what POSTing to a
        // collection means, and because the GET beside it is the same
        // resource read the other way round.
        register_rest_route(
            self::NAMESPACE,
            '/alerts',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'raise'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/alerts/(?P<id>\d+)/reply',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'reply'],
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
            '/alerts/(?P<id>\d+)/resend',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'resend'],
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
        // An alert deliberately withheld from this handset is not this
        // handset's alert, whatever else it says. Checked first because
        // the exclusion overrides every address below it — a broadcast
        // notice is addressed to everybody, and "everybody" is exactly
        // the shape the one handset it is kept from would otherwise
        // match. See Alert::$excludeDeviceId.
        if ($alert->excludes($device->id)) {
            return false;
        }

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

        $recorded = $this->alerts->acknowledge($alertId, $device->id, $device->memberEmail, $now);

        // Announced only on the acknowledgement that actually landed. A
        // handset retrying after a dropped response, or a second one
        // racing it, must not raise the notice a second time — the rota
        // would be told twice that the same message had been picked up,
        // by the same person, once for every dropped packet.
        if ($recorded) {
            $this->acknowledgements->announce($alert, $device, $this->responderName($device), $now);
        }

        // Always 200, including for a repeat. The acknowledgement is
        // idempotent at the storage layer, and a handset retrying after
        // a dropped response has achieved what it asked for.
        return new WP_REST_Response(['acknowledged' => true, 'alert_id' => $alertId], 200);
    }

    /**
     * What to call the responder in a notice sent to other handsets.
     *
     * Their Unity anonymous name, which is the form this suite shows
     * people, and a generic stand-in where no member record resolves or
     * the record has no name on it. <b>Never the email address.</b> The
     * usual fallback in the admin screens is to show the address, on the
     * grounds that an address is itself the diagnostic; a notice goes to
     * a lock screen instead of to an administrator, so here the fallback
     * has to be the anonymous one. See {@see AcknowledgementNotifier}.
     */
    private function responderName(Device $device): string
    {
        $member = $this->currentDevice->memberFor($device);
        if ($member === null) {
            return AcknowledgementNotifier::UNKNOWN_RESPONDER;
        }

        $name = trim($member->getAnonymousName());

        return $name !== '' ? $name : AcknowledgementNotifier::UNKNOWN_RESPONDER;
    }

    /**
     * Raise an alert from a handset, to one member or to a committee.
     *
     * <b>Any enrolled handset may do this, and that is the design.</b>
     * There is no capability to check: a handset exists only because
     * {@see \Reach\Devices\ResponderGate} passed at enrolment and passes
     * again on this very request, so "an authenticated handset" already
     * means "a certified telephone responder with an address". Reaching
     * for {@see \Reach\Core\Capabilities::SEND_ALERTS} here would not
     * merely be stricter, it would be incoherent — that is a WordPress
     * capability and there is no WordPress user behind a device token.
     *
     * What stands in for it is the throttle. See {@see SEND_MAX}.
     */
    public function raise(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        if ($this->overSendLimit($device)) {
            return new WP_Error(
                'reach_rate_limited',
                'Too many messages from this handset. Wait a few minutes and try again.',
                ['status' => 429],
            );
        }

        $subject = $this->posted($request, 'subject');
        if ($subject === '') {
            return new WP_Error(
                'reach_alert_missing_title',
                'A message needs a subject.',
                ['status' => 400],
            );
        }

        $memberId  = (int) $request->get_param('member_id');
        $committee = $this->posted($request, 'committee');

        // <b>Exactly one, and neither may be assumed.</b> An absent
        // recipient is refused rather than widened to a broadcast —
        // {@see \Reach\Admin\SendMessagePage::SCOPE_ALL} argues that for
        // the admin screen, and it matters more here, where anybody on
        // the rota is sending. A handset raises a broadcast only by
        // resending one that already was one.
        if (($memberId > 0) === ($committee !== '')) {
            return new WP_Error(
                'reach_alert_no_recipient',
                'Choose either a member or a committee to send to.',
                ['status' => 400],
            );
        }

        if ($committee !== '' && !$this->recipients->committeeExists($committee)) {
            return new WP_Error(
                'reach_unknown_committee',
                'That committee no longer exists.',
                ['status' => 404],
            );
        }

        $targets = $memberId > 0
            ? $this->recipients->forMemberId($memberId)
            : $this->recipients->forCommittee($committee);

        // Messaging a committee you sit on should not ring your own
        // pocket. Dropped by address rather than device id so the
        // sender's other handset goes too.
        $targets = $this->recipients->without($targets, $device->memberEmail);

        if ($targets === []) {
            return new WP_Error(
                'reach_no_handsets',
                $committee !== ''
                    ? 'Nobody on that committee has a handset enrolled.'
                    : 'That member has no handset enrolled.',
                ['status' => 404],
            );
        }

        // One uuid across the fan-out: several handsets, one message.
        // See MessageUuid, and AcknowledgementNotifier, which recovers
        // who a message went to from exactly this.
        $messageUuid = MessageUuid::generate();
        $body        = $this->posted($request, 'body');
        $level       = $this->posted($request, 'level');
        $response    = $this->posted($request, 'response');
        $responder   = $this->responderName($device);

        $raised = [];
        foreach ($targets as $target) {
            $id = $this->alertApi->send([
                'kind'             => 'responder_message',
                'source'           => 'hand',
                'title'            => $subject,
                'body'             => $body,
                'level'            => $level,
                'response'         => $response,
                'ttl'              => self::SEND_TTL,
                'target_device_id' => $target->id,
                'message_uuid'     => $messageUuid,
                'sender_email'     => $device->memberEmail,
                'payload'          => ['sent_by' => $responder],
            ]);

            if (!is_wp_error($id)) {
                $raised[] = $id;
            }
        }

        if ($raised === []) {
            return new WP_Error(
                'reach_alert_failed',
                'The message could not be sent.',
                ['status' => 500],
            );
        }

        $this->auditSend($device, $memberId, $committee, count($raised));

        return new WP_REST_Response([
            'sent'         => true,
            'message_uuid' => $messageUuid,
            'handsets'     => count($raised),
        ], 201);
    }

    /**
     * A responder's free-text reply to an alert.
     *
     * <b>Replying works after somebody else has answered, and needs no
     * exception to make it.</b> {@see maySee()} asks whether this handset
     * could have been sent the alert — targeting, and nothing about
     * acknowledgement — so a responder whose copy was cleared when
     * another took the job can still say something about it. The alert
     * row outlives its delivery by the retention window, and Hand offers
     * the button from its history. Neither the poll's suppression nor the
     * notice that clears the card is touched by any of this, which is the
     * point: a reply is not a second person taking the job on.
     */
    public function reply(WP_REST_Request $request): WP_REST_Response|WP_Error
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
            return $this->unknownAlert();
        }

        // A reply to a notice would be a correspondence with nothing at
        // the end of it — the same guard AcknowledgementNotifier opens
        // with, for the same reason.
        if ($alert->isNotice()) {
            return $this->unknownAlert();
        }

        $body = $this->clip($this->posted($request, 'body'), self::REPLY_MAX);
        if ($body === '') {
            return new WP_Error(
                'reach_reply_empty',
                'A reply needs something in it.',
                ['status' => 400],
            );
        }

        $responder = $this->responderName($device);

        $reply = $this->replies->create(
            $alertId,
            $alert->messageUuid,
            $device->id,
            $device->memberEmail,
            $responder,
            $body,
            $now,
        );

        // <b>Dispatched onward only when there is a handset to reach.</b>
        // An alert raised by a plugin has nobody behind it, and one
        // raised from wp-admin has an administrator, who has no handset.
        // Those replies are stored and read on the devices screen; that
        // is not a failure and must not be reported as one.
        if ($alert->senderEmail !== '') {
            $this->alertApi->send([
                'kind'         => Alert::KIND_REPLY,
                'source'       => 'hand',
                'title'        => $responder . ' replied',
                'body'         => $body,
                'level'        => Alert::LEVEL_BLUE,
                'response'     => Alert::RESPONSE_NONE,
                'ttl'          => self::SEND_TTL,
                'target_email' => $alert->senderEmail,
                'payload'      => [
                    'reply_to_alert_id'  => (string) $alertId,
                    'reply_message_uuid' => $alert->messageUuid,
                    'replied_by'         => $responder,
                ],
            ]);
        }

        return new WP_REST_Response([
            'replied'  => true,
            'alert_id' => $alertId,
            'reply_id' => $reply->id,
        ], 201);
    }

    /**
     * Put an acknowledged job back out, as a genuinely new message.
     *
     * <b>The fresh uuid is the whole mechanism.</b>
     * {@see AlertRepository::pendingFor()} suppresses an alert whose
     * message already carries an acknowledgement, so reusing the
     * original's would raise something born suppressed that reached
     * nobody. A new one makes this a new message, which is exactly what
     * it is: the first was answered, and this is the job going back out.
     *
     * Everything else is copied, targeting included — a broadcast goes
     * back to the rota it came from. The original stays acknowledged by
     * the person now handing it on, which is the honest record.
     */
    public function resend(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($insecure = $this->insecureTransport()) !== null) {
            return $insecure;
        }

        $now = time();
        $device = $this->currentDevice->fromRequest($request, $now);
        if ($device === null) {
            return $this->notAuthenticated();
        }

        if ($this->overSendLimit($device)) {
            return new WP_Error(
                'reach_rate_limited',
                'Too many messages from this handset. Wait a few minutes and try again.',
                ['status' => 429],
            );
        }

        $alertId = (int) $request->get_param('id');
        $alert = $this->alerts->findById($alertId);
        if ($alert === null || !$this->maySee($alert, $device)) {
            return $this->unknownAlert();
        }

        // Nothing informational was ever anybody's to take on, so there
        // is no acknowledger and nothing to hand back. A notice is not a
        // job at all.
        if (!$alert->isFirstToRespond() || $alert->isNotice()) {
            return $this->unknownAlert();
        }

        if (!$this->acknowledgedBy($alert, $device->memberEmail)) {
            return $this->unknownAlert();
        }

        $responder = $this->responderName($device);

        $newId = $this->alertApi->send([
            'kind'      => $alert->kind,
            'source'    => $alert->source,
            'title'     => $alert->title,
            'body'      => $alert->body,
            'reference' => $alert->reference,
            'level'     => $alert->level,
            'response'  => $alert->response,
            'ttl'       => self::SEND_TTL,
            // The audience the original had, whatever it was.
            'target_email'     => $alert->targetEmail,
            'target_device_id' => $alert->targetDeviceId,
            // Keeps the phone that just gave the job up from being rung
            // about it. Holds one id, so a responder's other handset will
            // still ring — see Alert::$excludeDeviceId.
            'exclude_device_id' => $device->id,
            'sender_email'      => $device->memberEmail,
            'payload'           => $alert->payload + [
                'resent_from_alert_id' => (string) $alertId,
                'resent_by'            => $responder,
            ],
            // Copied so whoever picks it up can still ring the caller.
            // Re-encrypted into the new alert's own row by the dispatcher;
            // it stays out of the push and the poll exactly as before.
            'contact' => $this->contacts->find($alertId),
        ]);

        if (is_wp_error($newId)) {
            return $newId;
        }

        $this->auditHandover($device, $alert, $newId);

        return new WP_REST_Response([
            'resent'   => true,
            'alert_id' => $alertId,
            'raised'   => $newId,
        ], 201);
    }

    /**
     * Whether this responder is among those who acknowledged a message.
     *
     * <b>Asked of the message, not the alert row.</b> An administrator's
     * message to a responder holding a phone and a tablet is two rows,
     * and acknowledging on one must let them resend from either — which
     * matching on the row alone would refuse. Matched on the address
     * rather than the device id for the same reason.
     *
     * The empty uuid falls back to the row: those are rows written before
     * the column existed and are not one message, so treating them as one
     * would let any of them speak for the others.
     */
    private function acknowledgedBy(Alert $alert, string $memberEmail): bool
    {
        $wanted = strtolower($memberEmail);

        $siblings = $alert->messageUuid === ''
            ? [$alert]
            : $this->alerts->findByMessageUuid($alert->messageUuid);

        foreach ($siblings as $sibling) {
            foreach ($this->alerts->acknowledgementsFor($sibling->id) as $ack) {
                if (strtolower($ack['member_email']) === $wanted) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether this handset has raised too many alerts too quickly. */
    private function overSendLimit(Device $device): bool
    {
        return $this->rateLimiter->overLimit(
            'alert-send:' . $device->id,
            self::SEND_MAX,
            self::SEND_WINDOW,
        );
    }

    /**
     * Record that a responder raised a message, and to whom.
     *
     * The directory listing is not audited — an anonymous name and a
     * home group are the form this suite shows people, and Integrity
     * returns the same without a clear permission. Actually messaging
     * somebody is the fact worth being able to answer for.
     */
    private function auditSend(Device $device, int $memberId, string $committee, int $handsets): void
    {
        $member = $this->currentDevice->memberFor($device);

        $this->auditLogger->log(
            AuditLogger::ACTION_MESSAGE,
            AuditLogger::ENTITY_MEMBER,
            $member !== null ? $member->getId() : 0,
            'hand_message',
            'Message raised from handset'
                . ($committee !== '' ? ';committee:' . $committee : ';member:' . $memberId)
                . ';handsets:' . $handsets,
        );
    }

    /**
     * Record that a responder passed a job — and its contact details —
     * on to a new audience.
     *
     * <b>A second entry, not a replacement for the per-read one.</b>
     * Reading a contact was already answerable; handing it to everybody
     * on the rota is a different disclosure and a wider one, and it
     * happens without anybody reading anything. Auditing only the reads
     * would leave the larger event unrecorded.
     */
    private function auditHandover(Device $device, Alert $alert, int $newAlertId): void
    {
        $member = $this->currentDevice->memberFor($device);

        $this->auditLogger->log(
            AuditLogger::ACTION_MESSAGE,
            AuditLogger::ENTITY_MEMBER,
            $member !== null ? $member->getId() : 0,
            'hand_handover',
            'Alert passed on;from:' . $alert->id
                . ';to:' . $newAlertId
                . ';audience:' . ($alert->isBroadcast() ? 'broadcast' : 'address')
                . ($alert->reference !== '' ? ';ref:' . $alert->reference : '')
                . ($this->contacts->find($alert->id) !== '' ? ';contact:copied' : ''),
        );
    }

    /**
     * The same 404 for "no such alert" and "not yours": which alerts
     * exist is not something one responder should learn about another's.
     */
    private function unknownAlert(): WP_Error
    {
        return new WP_Error('reach_unknown_alert', 'No such alert.', ['status' => 404]);
    }

    /**
     * A trimmed request string, or '' for anything that is not one.
     *
     * Nothing is sanitised here: {@see \Reach\Alerts\AlertRequest} caps
     * the length and strips the markup on the way in, and doing it twice
     * would mean two places to keep in step. The one exception is the
     * reply body, which is stored as well as dispatched — see
     * {@see clip()}.
     */
    private function posted(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);

        return is_string($value) ? trim($value) : '';
    }

    /** Trim, strip markup and cap without splitting a UTF-8 sequence. */
    private function clip(string $value, int $max): string
    {
        $value = trim(wp_strip_all_tags($value));

        return strlen($value) > $max
            ? trim((string) mb_strcut($value, 0, $max, 'UTF-8'))
            : $value;
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
