=== Reach ===
Contributors: thebleedingdeacons
Requires at least: 6.1
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.6.4
Build date: 2026/06/14 10:58:48
License: MIT (Modified)

Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, or Apple, plus a mobile-first finder UI. Requires Unity and Scrutiny.

== Description ==

Reach provides two pages — a sign-in page that verifies the visitor's email via Google, Microsoft, or Apple OAuth (no API access requested beyond email proof), and a mobile-optimised finder UI that shows the nearest 12th-step members to a postcode or place. No WordPress account required for visitors; sessions are 12-hour HMAC-signed cookies.

Every member surfaced is audit-logged through Scrutiny with the requesting visitor's verified email attached.

== Changelog ==

= 1.3.0 =
* Add a "Default search area" setting that disambiguates ambiguous UK place names toward your intergroup's region. With a bias configured (e.g. "BS5"), a search for "Kingswood" returns Bristol's Kingswood rather than whichever Kingswood postcodes.io happens to rank first.
* Member area fields may now contain multiple pipe-separated entries (e.g. "Kingswood|BS15|Hanham"). Each entry is geocoded; the member is attributed to whichever entry is closest to the caller. A single bad entry within the list no longer disqualifies the member.
* Find-page results show only the chosen entry from a pipe-separated member area, not the raw stored string. A member stored as "Kingswood|Hanham" whose Kingswood entry won the distance race is rendered as "Kingswood" in the list, matching the reported distance.
* Admin: the "Authentication" submenu is renamed "Settings" and now hosts both the find-page configuration and the OAuth provider credentials.

= 0.1.0 =
* Initial release.
