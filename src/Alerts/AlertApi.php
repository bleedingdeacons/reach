<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

use function add_action;

/**
 * Reach's public alerting API — the way another plugin makes a duty
 * handset ring.
 *
 * Most callers will want the global wrapper rather than this class:
 *
 * ```php
 * $alertId = reach_send_alert([
 *     'kind'      => 'shift_uncovered',
 *     'source'    => 'trusted',
 *     'title'     => 'Helpline shift uncovered',
 *     'body'      => 'Tonight 22:00–08:00 has nobody signed up.',
 *     'reference' => 'SHIFT-2026-08-15-N',
 *     'level'     => 'red',
 * ]);
 *
 * if (is_wp_error($alertId)) {
 *     // Reach refused it — see the error for why.
 * }
 * ```
 *
 * `kind` and `title` are the only required fields. `target_email`
 * addresses one responder; omit it and the alert goes to the whole
 * certified rota, which is usually what a helpline wants. `ttl`
 * (seconds) controls how long it stays live before it stops being worth
 * ringing anybody about; it defaults to an hour.
 *
 * <b>`level` is how loud it is, and defaults to `yellow`.</b>
 *
 *   `red`    — takes the handset's screen over and rings like an
 *              incoming call until somebody answers. For what has to be
 *              dealt with now.
 *   `yellow` — a heads-up notification with a sound. Audible, missable,
 *              and right for most things.
 *   `blue`   — the tray, at ordinary importance. Reminders and
 *              information; never wakes anybody.
 *
 * <b>`response` is whether somebody has to take it on, and defaults to
 * `first`.</b>
 *
 *   `first`  — the first responder to acknowledge deals with it. The
 *              rest are told who answered and it clears off their
 *              handsets. Their button says Acknowledge.
 *   `none`   — everybody reads it and closes their own copy. Nobody is
 *              told anything, and one responder closing it leaves it on
 *              every other screen. Their button says Close.
 *
 * The two are independent: a red alert can be informational (a fire
 * drill everybody must see) and a blue one can be first-to-respond (a
 * low-priority job somebody still has to pick up).
 *
 * `priority` (`normal` / `urgent`) is the older spelling of `level` and
 * is still accepted — `urgent` means `red`, `normal` means `yellow`. An
 * explicit `level` wins where both are sent. New callers should use
 * `level`: `priority` cannot ask for `blue` at all.
 *
 * Plugins that would rather not depend on a function existing can fire
 * the action instead, which is inert when Reach is not active:
 *
 * ```php
 * do_action('reach/send_alert', ['kind' => 'shift_uncovered', ...]);
 * ```
 *
 * <b>Do not put personal data in an alert.</b> The text travels through
 * Google's push infrastructure and onto a lock screen anyone nearby can
 * read. Send a reference and let the responder look the details up
 * through a channel that is actually private — which is the same rule
 * Reach already applies to its own call requests, whose caller details
 * are emailed and never stored. {@see Alert} has the longer version.
 */
final class AlertApi
{
    /** Action a plugin can fire instead of calling the function. */
    public const SEND_ACTION = 'reach/send_alert';

    public function __construct(private readonly AlertDispatcher $dispatcher)
    {
    }

    /**
     * Hook the action form of this API up.
     *
     * The function form needs no registration — it is declared in the
     * plugin bootstrap and resolves through the container on call.
     */
    public function register(): void
    {
        add_action(self::SEND_ACTION, [$this, 'handleAction'], 10, 1);
    }

    /**
     * Raise an alert.
     *
     * @param array<string, mixed> $args See the class docblock.
     * @return int|WP_Error The stored alert's id, or why it was refused.
     */
    public function send(array $args): int|WP_Error
    {
        $request = AlertRequest::fromArray($args);
        if ($request instanceof WP_Error) {
            return $request;
        }

        return $this->dispatcher->dispatch($request)->id;
    }

    /**
     * Action-hook adapter.
     *
     * Swallows the refusal deliberately: `do_action()` has no return
     * value, so a caller using this form has already chosen not to find
     * out. The refusal is still logged by the dispatcher, and a plugin
     * that wants to know uses {@see send()}.
     *
     * @param mixed $args
     */
    public function handleAction($args): void
    {
        if (is_array($args)) {
            $this->send($args);
        }
    }
}
