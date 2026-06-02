# Reach

Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, Apple, or Facebook, and a mobile-first finder UI.

**Version:** 1.3.1

## Requirements

- WordPress 6.1+
- PHP 8.1+ with `openssl` and `json`
- [Unity](https://github.com/thebleedingdeacons/unity) and [Scrutiny](https://github.com/thebleedingdeacons/scrutiny) must be active

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

## License

MIT (Modified). See `LICENSE`.
