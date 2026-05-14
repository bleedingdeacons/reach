# Reach

Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, or Apple, and a mobile-first finder UI powered by Compass.

## Requirements

- WordPress 6.1+
- PHP 8.1+ with `openssl` and `json`
- [Unity](https://github.com/thebleedingdeacons/unity), [Scrutiny](https://github.com/thebleedingdeacons/scrutiny), and [Compass](https://github.com/thebleedingdeacons/compass) must be active

Reach hooks into Compass's `compass/loaded` action and uses Compass's resolver directly. It does not call Compass's REST endpoint; this keeps the audit trail attributable to the specific Reach visitor.

## Pages

| URL              | Purpose                                                                   |
| ---------------- | ------------------------------------------------------------------------- |
| `/reach/signin`  | Three OAuth buttons — Google, Microsoft, Apple. No password, no account.  |
| `/reach/find`    | Postcode/area input + gender filter + nearest members list + sign-out.    |

Both pages render outside the WordPress theme — they're standalone mobile views.

## Sign-in flow

**Google / Microsoft** — server-side OAuth 2.0 authorization-code flow:

```
/reach/signin
  → click "Continue with Google"
  → GET /reach/v1/oauth/start?provider=google
    (Reach mints CSRF state + nonce, stores them as a transient, 302 to Google)
  → Google sign-in
  → GET /reach/v1/oauth/callback?code=...&state=...
    (Reach consumes the state, exchanges the code, verifies the ID token,
     sets the signed session cookie, 302 to /reach/find)
```

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
| `/reach/v1/oauth/start?provider=...`       | GET    | Start Google or Microsoft flow     |
| `/reach/v1/oauth/callback`                 | GET    | OAuth callback target              |
| `/reach/v1/oauth/apple/start`              | GET    | Issue state+nonce for Apple SDK    |
| `/reach/v1/oauth/apple`                    | POST   | Verify Apple ID token              |
| `/reach/v1/oauth/signout`                  | POST   | Clear the session cookie           |
| `/reach/v1/session`                        | GET    | Returns current session info       |
| `/reach/v1/nearest-members`                | GET    | Same shape as Compass's endpoint   |

## Audit logging

Every result returned by `/reach/v1/nearest-members` produces one `logBatch` entry in Scrutiny per member, with the source tag:

```
reach:nearest-members; viewer=google/alice@example.com
```

So "which Reach visitor saw which member's mobile, and when" is answerable directly from Scrutiny's audit table.

## Optional capability gate

By default, an email-verified session is sufficient — that's the whole point of Reach. The **Settings → Reach** admin page has a toggle that also requires the visitor to be a logged-in WordPress user with `scrutiny_view_personal_data`. Useful for internal-only deployments where Reach is for officers, not general public.

## OAuth credentials

Settings → Reach. Client IDs are stored as plain options; client secrets are AES-256-GCM encrypted at rest, keyed by `wp_salt('auth')`. Empty submission of a secret field leaves the existing value untouched; an explicit checkbox is needed to remove a stored secret.

Redirect URIs to register with each provider:

- Google / Microsoft: `https://your-site.example/wp-json/reach/v1/oauth/callback`
- Apple: `https://your-site.example/reach/signin` (used as the popup origin)

## License

MIT (Modified). See `LICENSE`.
