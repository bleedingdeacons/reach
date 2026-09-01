=== Reach ===
Contributors: thebleedingdeacons
Requires at least: 6.1
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.8.1
Build date: 2026/09/01 19:05:52
License: MIT (Modified)

Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, or Apple, plus a mobile-first finder UI. Requires Unity and Scrutiny.

== Description ==

Reach provides two pages — a sign-in page that verifies the visitor's email via Google, Microsoft, or Apple OAuth (no API access requested beyond email proof), and a mobile-optimised finder UI that shows the nearest 12th-step members to a postcode or place. No WordPress account required for visitors; sessions are 12-hour HMAC-signed cookies.

Every member surfaced is audit-logged through Scrutiny with the requesting visitor's verified email attached.

== Upgrade Notice ==

= 2.0.0 =
Breaking: the push payload sent to Android handsets is now encrypted in full. A handset running a Hand build older than 1.6.3 cannot read it and will ignore pushed alerts, falling back to the poll. Update the handsets.

== Changelog ==

= 2.0.0 =
* **Breaking (push wire format).** The whole FCM data payload sent to an Android handset is now sealed to that handset's key and travels as a single `ciphertext` field. Previously only the title, body and reference were encrypted, while the alert id, kind, source, priority, channel, sound and the raising plugin's extras travelled in the clear. Requires Hand 1.6.3 or later; an older handset ignores the push and collects the alert by polling instead.
* Alerts carrying no personal data was a convention that `AlertRequest` could not enforce — it caps lengths and strips markup, it does not read meaning. Encrypting the whole payload removes the question rather than policing it. Raised as CWE-359.
* The payload is gzipped before sealing. Without it the largest alert the API accepts seals to 4616 bytes and would be rejected by FCM, which caps a data message at 4096.
* A sealed payload that would exceed FCM's budget is refused and logged as an error, so it surfaces on Sentinel rather than failing inside FCM. The data key counts towards that budget as well as the value.
* Unchanged: the poll (`GET reach/v1/alerts`) stays in plaintext — it is HTTPS to our own server, and it is what keeps a handset with a broken key receiving alerts at all. iOS also stays in plaintext until Hand's notification service extension can be built.

= 1.3.0 =
* Add a "Default search area" setting that disambiguates ambiguous UK place names toward your intergroup's region. With a bias configured (e.g. "BS5"), a search for "Kingswood" returns Bristol's Kingswood rather than whichever Kingswood postcodes.io happens to rank first.
* Member area fields may now contain multiple pipe-separated entries (e.g. "Kingswood|BS15|Hanham"). Each entry is geocoded; the member is attributed to whichever entry is closest to the caller. A single bad entry within the list no longer disqualifies the member.
* Find-page results show only the chosen entry from a pipe-separated member area, not the raw stored string. A member stored as "Kingswood|Hanham" whose Kingswood entry won the distance race is rendered as "Kingswood" in the list, matching the reported distance.
* Admin: the "Authentication" submenu is renamed "Settings" and now hosts both the find-page configuration and the OAuth provider credentials.

= 0.1.0 =
* Initial release.
