# Reach — OAuth provider setup guide

Reach signs people in by proving their email address through an OAuth
provider. It supports four: **Google**, **Microsoft**, **Facebook**, and
**Apple**. You can enable any subset — the sign-in page only shows a
button for a provider once its client ID is saved.

This guide walks through each provider end to end: what to create on the
provider's side, what to copy back into Reach, and the exact redirect
URLs to register.

---

## Before you start

### Where Reach's settings live

In WordPress admin, go to **Reach → Authentication**. Each provider has a
**Client ID** field and (except Apple) a **Client secret** field. Client
IDs are stored in plain text; secrets are encrypted at rest, so once
saved a secret is never shown back to you — leave the field blank to keep
the existing value, or type a new one to replace it.

### Your two Reach URLs

Two URLs from your site are needed when registering apps with providers.
Replace `https://example.com` with your real site URL (it must be
**HTTPS** — every provider requires it).

| Purpose | URL | Used by |
|---|---|---|
| **OAuth callback** (redirect URI) | `https://example.com/wp-json/reach/v1/oauth/callback` | Google, Microsoft, Facebook |
| **Apple return URL / sign-in page** | `https://example.com/reach/signin` | Apple only |

> **Permalinks matter.** The callback URL above assumes "pretty"
> permalinks are enabled (Settings → Permalinks set to anything other
> than "Plain"). If permalinks are Plain, `rest_url()` produces
> `https://example.com/?rest_route=/reach/v1/oauth/callback` instead,
> which some providers reject. Enabling pretty permalinks is recommended.
> Either way, register the **exact** callback URL your site emits.

### A note on email — required for access

Reach exists to verify a real, reachable email address. Two consequences
to keep in mind while configuring providers:

- The **email** scope is mandatory for every provider (the steps below
  request it). Without a verified email claim, sign-in fails.
- If a user signs in but the provider returns only an **anonymised relay
  address** that can't receive mail (notably a Facebook
  `*.facebook.com` relay when the user unticks email sharing), Reach
  **refuses the sign-in** with an "email address is required for access"
  error rather than letting them in. Apple's relay
  (`privaterelay.appleid.com`) is the exception — Apple forwards mail
  through it, so it's accepted as a real address.

---

## Google

Google uses the standard OpenID Connect authorization-code flow. Reach
requests the scopes `openid email` only and requires `email_verified` to
be true.

1. Go to the **Google Cloud Console** → <https://console.cloud.google.com/>.
2. Create or select a project (top bar project picker → **New Project**).
3. Open **APIs & Services → OAuth consent screen**. Choose **External**,
   fill in the app name, support email, and developer contact, and add
   your domain under **Authorized domains**. Add the `…/auth/userinfo.email`
   and `openid` scopes if prompted. Save.
4. Open **APIs & Services → Credentials → Create Credentials → OAuth
   client ID**.
5. **Application type:** Web application. Give it a name (e.g. "Reach").
6. Under **Authorized redirect URIs**, click **Add URI** and paste your
   callback URL:
   `https://example.com/wp-json/reach/v1/oauth/callback`
7. Click **Create**. Google shows a **Client ID** and **Client secret** —
   copy both.
8. In Reach (**Reach → Authentication**), paste them into the Google
   **Client ID** and **Client secret** fields and save.

**Test:** the Google button should appear on `/reach/signin`. Clicking it
should bounce to Google's account chooser (`prompt=select_account`) and
return you signed in.

---

## Microsoft

Microsoft uses Entra ID (formerly Azure AD) via the **consumers** tenant,
so only personal Microsoft accounts (Outlook.com, Hotmail, Live, etc.) can
sign in — work and school accounts are not accepted. Reach requests
`openid email profile`.

1. Go to the **Microsoft Entra admin center** →
   <https://entra.microsoft.com/> (or the Azure portal → Microsoft Entra
   ID). Open **App registrations → New registration**.
2. **Name:** e.g. "Reach".
3. **Supported account types:** choose **Personal Microsoft accounts
   only**. This is what makes the `consumers` tenant work; a broader choice
   would let work/school accounts through, which Reach's pinned consumer
   issuer rejects.
4. **Redirect URI:** set the platform to **Web** and paste your callback
   URL:
   `https://example.com/wp-json/reach/v1/oauth/callback`
5. Click **Register**.
6. On the app's **Overview** page, copy the **Application (client) ID**.
7. Go to **Certificates & secrets → Client secrets → New client secret**.
   Give it a description and expiry, click **Add**, then immediately copy
   the secret **Value** (not the Secret ID — the value is only shown
   once).
8. In Reach, paste the Application (client) ID into the Microsoft
   **Client ID** field and the secret value into **Client secret**, then
   save.

> Microsoft doesn't issue an `email_verified` claim — the issuer itself
> is the verification — so Reach uses the token's `email`, falling back to
> `preferred_username` when it's a valid email. No extra config needed.

**Test:** the Microsoft button appears on `/reach/signin` and returns you
signed in after the account chooser.

---

## Facebook

Facebook uses OpenID Connect authorization-code flow **with PKCE**, on API
version v21.0. Reach requests `openid email`.

1. Go to **Meta for Developers** → <https://developers.facebook.com/apps/>
   and click **Create app**.
2. Pick a use case that includes **Facebook Login** (e.g. "Authenticate
   and request data from users"). Complete the basic app details.
3. In the app dashboard, add the **Facebook Login** product if it isn't
   already present.
4. Go to **Facebook Login → Settings** and under **Valid OAuth Redirect
   URIs** paste your callback URL:
   `https://example.com/wp-json/reach/v1/oauth/callback`
   Save changes.
5. Make sure **Login with the OpenID Connect** / email permission is
   available. The `email` permission is granted by default; no App Review
   is needed for it on a standard login.
6. Go to **App settings → Basic**. Copy the **App ID** and the
   **App secret** (click **Show**).
7. In Reach, paste the App ID into the Facebook **Client ID** field and
   the App secret into **Client secret**, then save.
8. While testing, keep the app in **Development** mode and sign in with a
   developer/test user, or switch the app to **Live** for real users.

> **PKCE is automatic.** Reach mints a PKCE verifier on every sign-in and
> sends the `S256` challenge to Facebook — nothing to configure. If
> Facebook ever returns "No code_verifier specified…", it means the
> callback was reached without Reach's state, not a settings problem.

> **The email opt-out.** On Facebook's consent screen a user can untick
> email sharing, in which case Facebook returns a `*.facebook.com` relay.
> Reach treats that as "no usable email" and refuses access (see the
> email note above). There's nothing to fix in config — the user must
> sign in again and allow email sharing, or use another provider.

**Test:** the Facebook button appears on `/reach/signin`; signing in with
email sharing **on** should return you signed in.

---

## Apple

Apple is different from the other three: Reach uses Apple's **client-side
JavaScript flow** (the browser gets the ID token via Apple's SDK and posts
it back). This means **Apple needs only a Client ID in Reach — leave the
Client secret field blank.** The "Client ID" for Apple is a **Services
ID**, not an App ID.

You need a paid **Apple Developer Program** membership.

1. Go to the **Apple Developer** portal →
   <https://developer.apple.com/account/resources/identifiers/list>.
2. **Create an App ID** (if you don't have one): Identifiers → **+** →
   **App IDs** → App. Enable the **Sign in with Apple** capability. Save.
3. **Create a Services ID:** Identifiers → **+** → **Services IDs**.
   - Set a description and an **Identifier** (reverse-DNS, e.g.
     `com.example.reach.web`). **This identifier is the value you'll paste
     into Reach as the Apple Client ID.**
   - After creating it, edit it and tick **Sign in with Apple**, then
     click **Configure**.
4. In the Sign in with Apple configuration:
   - **Primary App ID:** select the App ID from step 2.
   - **Domains and Subdomains:** add your domain, e.g. `example.com`
     (no scheme, no path).
   - **Return URLs:** add your sign-in page URL exactly:
     `https://example.com/reach/signin`
   - Save. Apple may require you to verify domain ownership by hosting a
     verification file it provides — follow its prompt if so.
5. In Reach (**Reach → Authentication**), paste the **Services ID
   identifier** into the Apple **Client ID** field. Leave **Client
   secret** blank. Save.

> **Why no secret?** Apple's server-side flow would require a client
> secret that is itself a JWT signed with a downloaded `.p8` key and
> re-minted every six months. Reach deliberately avoids that by using the
> browser-side flow, so there is no secret to manage and no key to rotate.

> **Relay addresses are fine here.** If a user chooses "Hide My Email",
> Apple issues a `privaterelay.appleid.com` address and forwards real
> mail to it. Reach accepts these as genuine contact addresses.

**Test:** once a Client ID is saved, the **Continue with Apple** button
appears on `/reach/signin`. It loads Apple's SDK and opens Apple's sign-in
popup; on success the ID token is posted back and you're signed in.

---

## Quick reference

| Provider | Reach needs | Redirect / return URL to register | Flow |
|---|---|---|---|
| Google | Client ID + secret | `…/wp-json/reach/v1/oauth/callback` | Server-side (OIDC) |
| Microsoft | Client ID + secret | `…/wp-json/reach/v1/oauth/callback` | Server-side (OIDC, consumers tenant) |
| Facebook | App ID + App secret | `…/wp-json/reach/v1/oauth/callback` | Server-side (OIDC + PKCE) |
| Apple | Services ID only (no secret) | `…/reach/signin` | Client-side (JS SDK) |

## Troubleshooting

- **No button shows for a provider** — its Client ID isn't saved. Check
  **Reach → Authentication**.
- **`redirect_uri_mismatch` / "invalid redirect"** — the URL registered
  with the provider doesn't byte-for-byte match what Reach sends. Confirm
  HTTPS, the host, and whether pretty permalinks are on (see *Your two
  Reach URLs*). Copy the exact callback URL your site emits.
- **"An email address is required for access"** — the provider proved who
  the user is but didn't return a usable email. On Facebook, the user
  unticked email sharing; have them retry and allow it, or use another
  provider.
- **Sign-in fails silently after the provider** — usually a token
  verification problem: wrong client ID, an expired/incorrect client
  secret, or (Microsoft) a single-tenant registration. Re-check the IDs
  and that the secret was copied as the **value**, not the ID.
