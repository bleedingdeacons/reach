# Reach

[![CI](https://github.com/bleedingdeacons/reach/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/reach/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/reach/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/reach/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/reach/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/reach?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Freach%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Freach%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-2.7.0-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, Apple, or Facebook, and a mobile-first finder UI.

## Requirements

- WordPress 6.1+
- PHP 8.1+ with `openssl` and `json`
- [Unity](https://github.com/bleedingdeacons/unity) and [Scrutiny](https://github.com/bleedingdeacons/scrutiny) must be active

Reach hooks into Unity on `unity/loaded` and uses Unity's `MemberRepository` to source members. Every member surfaced is audit-logged through Scrutiny with the requesting visitor's verified email attached, so a regulator can answer "which Reach user saw this member's mobile, and when" from Scrutiny's audit table.

## Pages

| URL              | Purpose                                                                   |
| ---------------- | ------------------------------------------------------------------------- |
| `/reach/signin`  | OAuth buttons — Google, Microsoft, Facebook, Apple. No password, no account.  |
| `/reach/find`    | Postcode/area input + gender filter + nearest members list + sign-out.    |

Both pages render outside the WordPress theme — they're standalone mobile views.

## Sign-in flow

**Google / Microsoft / Facebook** — server-side OAuth 2.0 authorization-code flow:

```
/reach/signin
  → click "Continue with Google"
  → GET /reach/v1/oauth/start?provider=google
    (Reach mints CSRF state + nonce + PKCE verifier, stores them as a
     transient, 302 to the provider)
  → provider sign-in
  → GET /reach/v1/oauth/callback?code=...&state=...
    (Reach consumes the state, exchanges the code, verifies the ID token,
     sets the signed session cookie, 302 to /reach/find)
```

Facebook requires PKCE on the web flow and uses a GET token endpoint;
both are handled inside `FacebookProvider`. Google and Microsoft don't
need PKCE but receive a verifier anyway and ignore it — the controller
mints one for every server-side flow.

**Facebook relay addresses** — if a user declines to share their email
in Facebook's consent dialog, Facebook hands Reach back an anonymised
address on `*.facebook.com` (e.g. `hash@privaterelay.facebook.com`).
Reach can't use those as a contact address — Facebook doesn't forward
mail behind them — and a contactable email is required to verify a
user, so the OAuth callback refuses sign-in rather than issuing a
session. The user needs to sign in again and share their email, or use
a different provider. Apple's `privaterelay.appleid.com` is *not*
treated as anonymised: Apple genuinely forwards mail through its relay,
so those addresses are accepted as the contact email.

**Sign-in refusals are friendly pages, not JSON.** When the server-side
callback can prove who someone is but can't let them in — an
unregistered member (`not_eligible`), an unusable relay address
(`email_required`), or a failed/expired attempt (`signin_failed`) — it
redirects the browser back to `/reach/signin?reach_error=<code>`
instead of returning a raw `WP_Error`. The sign-in template maps each
code to a styled notice (the M3 error-container banner) so the user
gets a readable message and the sign-in buttons to try again. The
client-side Apple flow does the same: on a refused verification its JS
reloads the sign-in page with the matching `reach_error` code. Unknown
codes fall back to a generic message, so a bare JSON error is never
shown.

**Apple** — client-side flow via Apple's JS SDK:

```
/reach/signin
  → click "Continue with Apple"
  → GET /reach/v1/oauth/apple/start (returns {state, nonce})
  → AppleID.auth.signIn() opens Apple's popup
  → POST /reach/v1/oauth/apple {id_token, state}
    (Reach verifies the ID token against Apple's JWKS, sets the cookie,
     returns {redirect: "/reach/find"})
```

## Session model

After a successful sign-in Reach issues an HMAC-signed cookie (`reach_session`):

- `HttpOnly`, `Secure` (when over HTTPS), `SameSite=Lax`
- Payload contains the verified email, provider name, provider's `sub`, issued-at, and expiry — nothing else
- Signed with HMAC-SHA256 keyed by `wp_salt('logged_in')` so salt rotation invalidates all sessions
- 12-hour TTL

No WordPress users are created. There is no server-side session table. The cookie *is* the session.

## REST API

| Endpoint                                   | Method | Purpose                            |
| ------------------------------------------ | ------ | ---------------------------------- |
| `/reach/v1/oauth/start?provider=...`       | GET    | Start Google/Microsoft/Facebook flow |
| `/reach/v1/oauth/callback`                 | GET    | OAuth callback target              |
| `/reach/v1/oauth/apple/start`              | GET    | Issue state+nonce for Apple SDK    |
| `/reach/v1/oauth/apple`                    | POST   | Verify Apple ID token              |
| `/reach/v1/oauth/signout`                  | POST   | Clear the session cookie           |
| `/reach/v1/session`                        | GET    | Returns current session info       |
| `/reach/v1/nearest-members`                | GET    | Nearest 12th-step members by area  |
| `/reach/v1/call-attempts`                  | POST   | Record an attempt to call a member |
| `/reach/v1/auth/device/*`                  | —      | Hand handset enrolment — see below |
| `/reach/v1/alerts`                         | —      | Hand alert collection — see below  |

## Call attempts and responsiveness signal

Each result on the find page can show a short badge — *Reached recently*, *No recent reply*, or *Number may be out of date* — and three outcome buttons under the contact links: **Spoke**, **No answer**, **Wrong / bad number**. The badge is a coarse hint to the next caller; the buttons let the current caller record what happened.

Outcomes are stored in `wp_reach_call_attempts` (one row per attempt). Rules:

- Only the Reach user who was shown a member can log an outcome for that member. Each result carries a short-lived HMAC token binding (viewer email, member id); the POST verifies it.
- Repeat taps by the same viewer against the same member within 30 minutes update the existing row instead of creating duplicates.
- Every recorded attempt creates one Scrutiny audit entry with the outcome and viewer in the source detail. The free-text `note` field is for the caller's own context and is **never** written to the audit trail.

Badges are computed at request time over the last 14 days from `wp_reach_call_attempts`. Thresholds (in `ResponsivenessScorer`):

- *Reached recently* — at least one `reached` outcome in the window. Beats everything else.
- *Number may be out of date* — `wrong_or_bad_number` reports from 2+ distinct viewers.
- *No recent reply* — `no_answer` outcomes totalling 3+, from 2+ distinct viewers, with no successful `reached` in the window.

The distinct-viewer requirement is deliberate: it prevents a single frustrated caller from labelling a member unresponsive.

### Privacy note

Members do not directly see these badges, but the signal *is* a new kind of data exposure: other Reach users can see that a member hasn't been answering recently. Admins should mention this in their member onboarding so it isn't a surprise. The badges are intentionally coarse — no counts, no dates, no caller identities are surfaced to other users.

### Admin view

The Reach plugin adds a top-level **Reach** menu in WP admin with two pages:

- **Call attempts** (default) — read-only list of every recorded attempt with filters for member ID, viewer email substring, outcome, and date range. Each row shows the member's anonymous name and area resolved live via Unity's `MemberRepository`, with the bare member ID alongside (and an explicit "member not found" note for deleted members). Clicking through to the detail view also shows the caller's free-text note — the one place that note is ever displayed.
- **Authentication** — the OAuth provider configuration page (previously under *Settings → Reach*). Same `manage_options` gate as before.

The Call attempts page is gated by `scrutiny_view_personal_data`, matching the rest of the personal-data surfaces in the stack. A WP admin without that capability sees the menu but not the Authentication submenu (which still needs `manage_options`).

Member lookup is batched: each page of results triggers exactly one `MemberRepository::findAll(['post__in' => ...])` call so the per-row hydration that follows lands on WP's object cache.

The Call attempts page is deliberately read-only. Edits and deletions would undermine Scrutiny's audit log (which records the attempt as having happened) and create a tempting "tidy up" path that quietly suppresses uncomfortable patterns. If a correction is needed, the caller can log a new attempt with the corrected outcome — the scorer's "reached recently wins" rule will surface the latest reality.

## Hand — alerting the telephone-responder rota

[Hand](https://github.com/bleedingdeacons/hand) is a .NET MAUI app for
intergroup members. Reach is the server side: it enrols the handsets,
holds the alerts, and pushes them.

### Who may use it

**A member Unity holds a usable record for: a valid email address and a
home group.** That is the whole rule.

It used to be much stricter — certified telephone responders and nobody
else, because Hand was the helpline handset and nothing more. It now
also carries messages between members, and gating that on a helpline
certification kept it from the people it was useful to. A 12th-stepper
with no responder role is admitted; so is a responder still working
towards certification.

The two halves of the rule each earn their place. The **address** is
what a handset is matched on — it is the identity a device token is
minted for and the thing an alert is routed by — so a member without one
cannot be reached and must not be able to enrol; it is validated rather
than merely counted, because `n/a` and half-typed addresses are in real
member data. The **home group** is what separates a current member from
a stub: Reconcile's imports and half-finished admin entries leave records
with a name and nothing else, and those should not put a handset on the
rota.

> **What loosening it gave up.** The old gate meant a lapsed
> certification stopped a handset at its next call, and that the
> encrypted caller details an alert can carry only ever reached somebody
> cleared to take a 12th-step call. Neither is true now: an alert's
> contact details reach every enrolled handset it is addressed to,
> whoever holds it. Scrutiny still audits every contact read, so "who
> saw this caller's number, and when" remains answerable — what changed
> is the size of the set that answer can come from.

The gate (`Reach\Devices\ResponderGate`) is re-run on **every**
authenticated request and again at dispatch time, rather than being
frozen into the device token when it is issued. That half is unchanged
and is the half worth keeping: a member whose record stops qualifying —
their address removed, their home group cleared — stops receiving alerts
at their next call, without anybody remembering to revoke the handset.

This is Hand's gate alone. The website's is `Reach\Auth\OutreachEligibility`
and is untouched: it still admits a 12th-stepper *or* a certified
responder, because who may look members up is a different question from
who may carry a handset.

### Enrolling a handset

Two ways in, both ending in the same long-lived bearer token:

```
SSO       Hand opens /reach/v1/auth/device/start in the system browser
          → the ordinary OAuth flow, carrying a validated app redirect
          → the callback mints a ONE-TIME CODE instead of a session cookie
          → 302 to hand://auth?code=…
          → Hand POSTs the code to /auth/device/exchange for its token

Password  Hand POSTs email + password to /auth/device/password
          → token, no browser involved
```

The code, not the token, is what travels through the browser — RFC 8252
("OAuth 2.0 for Native Apps"), because a redirect lands in browser
history and can be read by anything else registered for the scheme.
Redirect targets are an allow-list of exactly two shapes:
`hand://auth`, and `http://127.0.0.1:{port}/…` for the Windows head,
which cannot claim a custom scheme unless packaged as MSIX. `localhost`
is refused — it resolves through DNS and can be redirected; the literal
loopback address cannot.

Tokens are stored only as a keyed hash. Rotating `wp_salt('auth')`
invalidates every enrolled handset, which is the behaviour you want
after a suspected breach.

### The alerting API

**This is the part other plugins use.** Reach itself raises no alerts.

```php
$alertId = reach_send_alert([
    'kind'      => 'shift_uncovered',   // required
    'subject'   => 'Helpline shift uncovered',  // required (alias: title)
    'message'   => 'Tonight 22:00–08:00 has nobody signed up.', // alias: body
    'source'    => 'trusted',
    'reference' => 'SHIFT-2026-08-15-N',
    'level'     => 'red',               // red | yellow | blue; default yellow
    'response'  => 'first',             // first | none; default first
    'contact'   => 'Sam, 07700 900123', // see below — handled separately
    'target_email' => '',               // omit to alert the whole rota
    'ttl'       => 3600,                // seconds; default 1 hour
    'payload'   => [                    // named properties, passed through
        'shift' => 'night',
        'area'  => 'BS5',
    ],
]);

if (is_wp_error($alertId)) { /* refused — see the error */ }
```

`subject`/`message` and `title`/`body` are the same two fields; the wire
names win if both are sent, so existing integrations are unaffected.

#### Level — how loud it is

| Level | On the handset |
| --- | --- |
| `red` | Takes the screen over and rings like an incoming call until somebody answers. For what has to be dealt with now. |
| `yellow` | A heads-up notification with a sound. Audible, missable, and right for most things. **The default.** |
| `blue` | The tray, at ordinary importance. Reminders and information; wakes nobody. |

The level is also the colour of the card in Hand, which is what makes a
list of alerts readable without reading any of them.

`priority` (`normal` | `urgent`) is the older spelling and is still
accepted — `urgent` means `red`, `normal` means `yellow`. An explicit
`level` wins if both are sent. New callers should use `level`, because
`priority` cannot ask for `blue` at all. Reach still *sends* a derived
`priority` on the wire so handsets predating the level keep working.

#### Response — whether somebody has to take it on

| Response | What happens |
| --- | --- |
| `first` | The first responder to acknowledge deals with it. Everyone else is told who answered and it clears off their handsets. Their button says **Acknowledge**. **The default.** |
| `none` | Everybody reads it and closes their own copy. Nobody is told anything, and one responder closing it leaves it on every other screen. Their button says **Close**. |

The two are independent: a red alert can be informational (a drill
everybody must see) and a blue one can be first-to-respond (a
low-priority job somebody still has to pick up).

`payload` is a flat, named string map — a plugin's own properties,
carried through to the handset untouched and readable there as
`HandAlert.Payload`. Flat and string-valued because that is what the
push can carry: FCM's data block is a string→string map, and anything
nested would need re-encoding per transport with a different shape on
each. It is capped at 2KB in total, and the alert's own fields win on a
name collision, so a plugin cannot redefine `title` or `alert_id` by
putting one in its payload. **The same rule applies as to everything
else: no personal data.**

### One send, one message

Every alert carries a `message_uuid`, and every alert raised by the same
send carries the same one.

Usually that is a distinction without a difference — a broadcast is one
row addressed to everybody. It matters when a send raises several rows:
an administrator messaging a responder who holds a phone and a tablet
raises one alert per handset, so that delivery to each can be seen
separately. The uuid is what says those are nonetheless one message.

That per-handset split originally went further: each row carried its own
acknowledgement, so a silent handset could not hide behind the other one
answering. "When somebody answers" below narrows that — answering on
either handset now clears both — and the reasoning is there. What
survives is that the acknowledgement records *which* handset answered.

Callers do not supply it — Reach mints one — unless they are
deliberately raising several alerts that are one message, in which case
`message_uuid` accepts one they generated. Any RFC 9562 uuid will do,
whatever version; it is an opaque grouping key and nothing reads meaning
out of it. A value that is not a uuid at all is replaced rather than
refused, because a send must never fail over an identifier that exists
to group rows for display.

### Contact details

`contact` is the one field that may hold personal data, and it is
handled completely differently from everything else:

| | Everything else | `contact` |
| --- | --- | --- |
| In the alerts table | yes | no — its own table |
| Encrypted at rest | no | yes, AES-256-GCM |
| In the FCM push payload | yes | **never** |
| In the poll response | yes | **never** — only a `has_contact` flag |
| On the lock screen | yes | **never** |
| Read by | anyone near the handset | the responder, on request |
| Audited | no | **yes, every read** |

The responder taps *Show contact* in Hand, which fetches it over TLS from
`GET /reach/v1/alerts/{id}/contact`. That call is authorised the same way
as acknowledging — the handset must be one the alert could have gone to —
and writes a Scrutiny audit entry, so "which responder saw this caller's
number, and when" is answerable from the audit table exactly as it is for
the rest of the stack.

Contacts are purged with their alerts, contacts first so no orphaned
personal data is left behind.

> Put the caller's name and number in `contact` and nowhere else. A phone
> number in `subject` or `message` is a phone number on a lock screen and
> in Google's logs.

Plugins that would rather not depend on a function existing can
`do_action('reach/send_alert', [...])`, which is inert when Reach is not
active. `do_action('reach/alert_dispatched', $alert, $devices, $pushed)`
fires afterwards, for a further notifier such as an SMS fallback.

> **Never put personal data in an alert.** The text passes through
> Google's push infrastructure and onto a lock screen anyone nearby can
> read. Send a reference and let the responder look the details up
> through a private channel — the same rule Reach already applies to its
> own call requests, whose caller details are emailed and never stored.

### Delivery

Store first, deliver second. Every alert is durable before any push is
attempted, and every handset polls as well as listening, so a failed
push delays an alert rather than losing it.

| Head | Alert while the app is closed |
| --- | --- |
| Android | Yes. Data-only FCM message at high priority; Hand builds a full-screen-intent notification on an alarm-category channel, so the handset rings like a call. |
| iOS | Yes, capped at 30s — a terminated app runs no code, so the APNs payload carries the sound and the system plays it. Bypassing the ringer switch and Do Not Disturb needs Apple's Critical Alerts entitlement (see Settings). |
| Windows / macOS | No FCM coverage. Hand runs as a login-start tray app and polls, so "closed" means not on screen rather than not running. |

Android is sent a **data-only** message deliberately. A message carrying
a `notification` block is handled by the system tray when the app is
backgrounded and `onMessageReceived` never runs — so Hand would never
get the chance to raise a full-screen intent, and a duty handset would
get one polite ding instead of ringing.

### When somebody answers

Acknowledging tells the rest of the rota. Reach raises a second message
of its own — kind `message_acknowledged` — addressed to everybody the
first one went to **except** the handset that answered, saying who
picked it up.

This exists because a broadcast rings every enrolled handset at once,
and the first responder to answer silences only their own. Everyone
else's went on shouting about a job already being done, and they found
out it was done by acknowledging it themselves — being woken for
nothing, and then telling Reach they had dealt with something they had
not.

The notice carries the message it reports on as payload properties:

| Property | Meaning |
| --- | --- |
| `ack_message_uuid` | The message that was answered |
| `ack_alert_id` | The alert row that was acknowledged |
| `ack_responder` | Who answered — a Unity anonymous name, never an address |
| `ack_device_id` | Which handset answered |
| `ack_at` | When, as a Unix timestamp |

In Hand it is deliberately quiet: it appears in the list and the
notification tray at ordinary importance, never alarms, and offers
**Close** instead of Acknowledge — there is nothing here to take on.
None of that is special-cased any more: the notice is raised as `blue`
and `none`, and Hand reads those two fields exactly as it reads them on
any other alert.

**An answered message is over, for everybody — where somebody was meant
to take it on.** For a `first` message the notice also *removes*
the alert it reports on from every other handset, and Reach stops serving
that message on the poll — otherwise the next poll would hand it straight
back. A responder taking a job clears it off the rest of the rota's
screens rather than leaving thirty people to dismiss it one by one. The
handset that answered keeps its own card, because that is where the
reference and the Show contact button are, and losing them the instant
you accept a job is precisely wrong.

A `none` message does none of that. Nobody is taking it on, so one
responder closing their own copy says nothing about anybody else's and
must not clear it from their screens — and no notice is raised, because
there is no first answer to report.

Two things are exempt from that suppression, and both are load-bearing.
**The notice itself** is addressed to everybody and read by each of them
separately, so the fastest handset to press Close must not decide nobody
else gets to read who answered. **The empty uuid** is shared by every row
written before that column existed, so matching on it would let any one
of them silence all the others; those keep the per-device behaviour they
were written under.

One cost, stated plainly: an administrator's message to a responder with
two handsets is deliberately two rows, so that Recent alerts can answer
"did *this* handset ring". Once either one is answered the other is
suppressed, so that screen now shows which handset answered rather than
that both rang. That is the intended trade — the same person dismissing
the same message twice was the thing worth removing.

Three things keep it from misbehaving. It is raised only on the
acknowledgement that actually landed, so a handset retrying after a
dropped response does not tell the rota twice. It is never raised for a
notice, or one answered call would become an unbounded correspondence.
And it is never urgent, whatever the alert it reports on was.

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/reach/v1/auth/device/start` | GET | Begin SSO enrolment in the system browser |
| `/reach/v1/auth/device/exchange` | POST | One-time code → device token |
| `/reach/v1/auth/device/password` | POST | Email + password → device token |
| `/reach/v1/auth/device/push` | POST | Record a rotated FCM registration token |
| `/reach/v1/auth/device/session` | GET | Who this handset is, and whether it is still allowed |
| `/reach/v1/auth/device/signout` | POST | Revoke this handset |
| `/reach/v1/alerts` | GET | Alerts this handset should be ringing about |
| `/reach/v1/alerts/{id}/contact` | GET | Contact details for one alert (audited) |
| `/reach/v1/alerts/{id}/ack` | POST | This handset has alarmed for one |

The poll has no client-side cursor: what a handset has handled is
recorded server-side as an acknowledgement, so a handset that is
reinstalled or restored from a backup neither re-alarms for everything
nor silently skips live alerts.

### Configuration and admin

**Reach → Settings** takes the Firebase service-account JSON (encrypted
at rest, write-only once saved) and the iOS critical-alerts switch.
Leave Firebase blank and everything still works by polling.

**Reach → Hand devices** lists enrolled handsets, shows recent alerts
and who acknowledged each, and offers three things per row or in bulk:

* **Send test alert** — to every live handset, or to a ticked selection.
  The delivery chain has a lot of links on other people's infrastructure
  and its failure mode is silence, so test it before you rely on it. Use
  the selection when you need to know *which* handset is deaf: a
  broadcast six phones answer and a seventh does not looks much like a
  broadcast seven answer. Selected handsets each get their own alert, so
  the recent-alerts table answers per handset.
* **Revoke** — cuts a handset's token dead now and keeps the row, so the
  list stays a record of what was enrolled and when it stopped.
* **Remove** — tells the handset it is off the rota, then deletes the row
  outright. For a pairing that should not be in the record at all: one
  enrolled by mistake, or a responder asking for it to be erased rather
  than merely disabled. The notice goes first and by push only — once
  the row is gone there is no token left to poll with.

**Reach → Help** opens the bundled admin guide
(`assets/docs/reach.html`) in its own tab: responder set-up and
certification, handset enrolment, push configuration, and the
troubleshooting order to work through when a handset isn't ringing.
Same pattern as Trusted's and Amber's Help submenus — the click is
intercepted so the guide's back button refocuses the admin tab rather
than reloading it.

## Audit logging

Every result returned by `/reach/v1/nearest-members` produces one `logBatch` entry in Scrutiny per member (one per audited PII field), with a structured `detail` string identifying the viewer:

```
caller:Alice K.#42
```

…or `caller:unknown` when the verified email matches no Unity member record. Call attempts logged via `/reach/v1/call-attempts` use the same prefix plus the outcome label:

```
caller:Alice K.#42;result:Spoke
```

Scrutiny's audit admin parses this shape and renders the name as a link to the viewer/caller's member edit page. The raw email is never written to the audit row.

So "which Reach visitor saw which member's mobile, and when, and which attempts they then logged" is answerable directly from Scrutiny's audit table.

## OAuth credentials

Settings → Reach. Client IDs are stored as plain options; client secrets are AES-256-GCM encrypted at rest, keyed by `wp_salt('auth')`. Empty submission of a secret field leaves the existing value untouched; an explicit checkbox is needed to remove a stored secret.

Redirect URIs to register with each provider:

- Google / Microsoft / Facebook: `https://your-site.example/wp-json/reach/v1/oauth/callback`
- Apple: `https://your-site.example/reach/signin` (used as the popup origin)

## Testing

Install the dev dependencies and run the suite from the plugin directory:

```bash
composer install
```

| Command | Description |
|---|---|
| `composer test` | Run the PHPUnit test suite |
| `composer phpstan` | Run PHPStan static analysis |

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/reach?branch=main)
on every CI run — see the coverage badge at the top of this file.

---

## License

MIT (Modified). See `LICENSE`.
