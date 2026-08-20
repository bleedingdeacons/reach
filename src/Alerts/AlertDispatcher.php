<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Transport\AlertTransport;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Logger\HasLogger;

use function do_action;

/**
 * Stores an alert and gets it onto the right handsets.
 *
 * <b>Store first, deliver second, and never the other way round.</b>
 * Everything about this feature's reliability follows from that order.
 * The alert is durable the moment it is raised, so a handset that is
 * out of signal, asleep, or being replaced mid-shift collects it on its
 * next poll regardless of what the push transports did. Delivery is
 * therefore an optimisation — a fast path that makes a phone ring in
 * two seconds instead of at the next poll — and is allowed to fail
 * quietly. A dispatcher that failed the caller because Google was
 * having a bad afternoon would be worse than useless.
 *
 * <b>Who receives an alert.</b> A broadcast alert (the normal case for
 * a helpline) goes to every enrolled handset whose responder still
 * passes {@see ResponderGate} — certified telephone responders only.
 * An alert may instead name one responder, or — for the two cases that
 * are about a handset rather than a person, the admin test alert and a
 * removal notice — one device.
 * The gate is re-run here rather than trusted from the device row for
 * the same reason it is re-run on every authenticated request: roles
 * change, and an alert containing a live callback is precisely the
 * thing that must not reach someone who has stepped down.
 *
 * The gate result is memoised per dispatch. A broadcast to a rota of
 * thirty responders would otherwise mean thirty member lookups, and
 * several of them are the same person's phone and desktop.
 */
final class AlertDispatcher
{
    use HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    /** @param array<int, AlertTransport> $transports */
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertContactRepository $contacts,
        private readonly DeviceRepository $devices,
        private readonly ResponderGate $gate,
        private readonly array $transports,
    ) {
    }

    /**
     * Raise an alert: store it, then try to push it.
     *
     * Returns the stored alert. It is stored whatever happens to the
     * push attempts, so a caller can rely on having a durable record
     * and a reference to quote.
     */
    public function dispatch(AlertRequest $request, ?int $now = null): Alert
    {
        $now = $now ?? time();
        $alert = $this->alerts->create($request, $now);

        // Contact details, when there are any, go to their own encrypted
        // table and nowhere near the push below. Stored before delivery so
        // a responder who opens the alert the instant it lands finds them
        // already there.
        if ($request->contact !== '') {
            $this->contacts->save($alert->id, $request->contact, $now);
        }

        $devices = $this->resolveTargets($alert);
        $pushed = 0;

        foreach ($devices as $device) {
            foreach ($this->transports as $transport) {
                if (!$transport->supports($device)) {
                    continue;
                }

                if ($transport->deliver($alert, $device)) {
                    $pushed++;
                }

                // One transport per device. Having been accepted by one
                // is enough, and having been refused by one is not a
                // reason to try another that declined to support it.
                break;
            }
        }

        $context = [
            'alert_id'  => $alert->id,
            'kind'      => $alert->kind,
            'source'    => $alert->source,
            'reference' => $alert->reference,
            'devices'   => count($devices),
            'pushed'    => $pushed,
        ];

        // Every handset refused is not the same event as a quiet rota, and
        // logging both at info is how a broken push stays invisible. A
        // service account missing cloudmessaging.messages.create returned
        // 403 on every send for weeks here: each alert was stored, each
        // dispatch logged 'pushed' => 0 at info, and the only reason
        // anybody was alerted at all was the handsets polling. Nothing
        // said so. An alert that reached no handset it had one for is a
        // delivery failure and is recorded as one.
        //
        // Deliberately not conditioned on the transport's reason. From
        // here the causes are indistinguishable and the consequence is
        // not: a responder was not told.
        if ($pushed === 0 && $devices !== []) {
            self::logError(
                'Alert reached no handset — every push was refused. Check the FCM service '
                . 'account and its permissions on the Firebase project.',
                $context,
            );
        } else {
            self::logInfo('Alert dispatched', $context);
        }

        /**
         * Fires after an alert has been stored and delivery attempted.
         *
         * The alert carries no personal data (see {@see Alert}), so this
         * is safe to hang further notifiers on — an SMS fallback for a
         * responder with no smartphone, say.
         */
        do_action('reach/alert_dispatched', $alert, count($devices), $pushed);

        return $alert;
    }

    /**
     * The live handsets an alert should reach.
     *
     * Three addresses, narrowest first: one device, one responder, or
     * everybody.
     *
     * @return array<int, Device>
     */
    private function resolveTargets(Alert $alert): array
    {
        if ($alert->isDeviceTargeted()) {
            // One named handset. The gate still applies: an admin
            // testing a handset whose owner has stepped down should
            // find it silent, which is the answer they needed.
            $device = $this->devices->findById($alert->targetDeviceId);
            if (
                $device === null
                || $device->isRevoked()
                || $this->gate->authorisedMember($device->memberEmail) === null
            ) {
                self::logNotice('Alert targeted a handset that cannot receive it', [
                    'alert_id'  => $alert->id,
                    'device_id' => $alert->targetDeviceId,
                ]);
                return [];
            }

            return [$device];
        }

        if (!$alert->isBroadcast()) {
            // A named target that is no longer eligible gets nothing,
            // and that is not an error worth failing the dispatch over —
            // the alert is stored, and the admin list will show it went
            // to nobody.
            if ($this->gate->authorisedMember($alert->targetEmail) === null) {
                self::logNotice('Alert targeted a responder who is not eligible', [
                    'alert_id' => $alert->id,
                ]);
                return [];
            }

            return $this->devices->findByMemberEmail($alert->targetEmail);
        }

        $eligible = [];
        /** @var array<string, bool> $decided Memoised gate results, keyed by email. */
        $decided = [];

        foreach ($this->devices->findAllLive() as $device) {
            $email = $device->memberEmail;

            if (!array_key_exists($email, $decided)) {
                $decided[$email] = $this->gate->authorisedMember($email) !== null;
            }

            if ($decided[$email]) {
                $eligible[] = $device;
            }
        }

        return $eligible;
    }
}
