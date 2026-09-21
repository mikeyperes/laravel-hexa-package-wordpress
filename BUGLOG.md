# Campaign Bug Log — laravel-hexa-package-wordpress

Permanent record of critical and high-severity article campaign bugs involving
WordPress delivery and verification in this package. Bug IDs are shared with the
[laravel-hexa-app-publish BUGLOG](https://github.com/mikeyperes/laravel-hexa-app-publish/blob/main/BUGLOG.md).

## Rules for every contributor and AI agent

1. Read this file before changing post creation, update, staging, readback or
   rollback code.
2. Code marked `CRITICAL — see ... BUGLOG.md CAMPAIGN-BUG-NNN` is a regression
   guard. Do not remove or "simplify" it; a refactor that moves it must keep the
   behavior and update the entry in the same commit.
3. Log every new critical or high-severity delivery bug here in the same commit
   as its patch, with the bug ID in the commit message. Never delete an entry.
4. Timestamps are EST (UTC−05:00).

---

## CAMPAIGN-BUG-083 — REST finalization rejected WordPress's generated slug

- **Severity:** High (prepared external articles could remain stranded as drafts)
- **Status:** Patched 2026-09-21 04:23:00 EST in 2.0.78.
- **Impact:** Her Forward HWS Base Tools operation 6878 completed preparation,
  including three WordPress media uploads and featured media 57011. Resumed
  publish operation 6877 then reverted post 57007 to draft because WordPress
  generated its normal permalink slug during the final status transition.

**Root cause.** WP Toolkit verification already predicts and binds the slug
that core assigns when an empty-slug draft becomes public. The shared REST/HWS
verifier instead carried the empty staging slug into final expectations, so its
exact readback treated the valid generated slug as an unauthorized rewrite.

**Patch.** When no slug was requested and an empty-slug REST draft enters a
public/final state, the verifier binds the slug returned by that exact status
mutation. Its independent final readback must preserve the same value
byte-for-byte; supplied slugs and all other fields remain strictly verified.

**Guard — do not remove.** Accept a WordPress-generated slug only for the exact
empty-draft final-status transition. Never relax supplied-slug verification or
skip the independent final readback.

---

## CAMPAIGN-BUG-078 — HWS author identity omitted the real WordPress login

- **Severity:** High (configured bridge authors could not be verified)
- **Status:** Patched 2026-09-21 02:47:53 EST in 2.0.77 and HWS Base Tools 13.2.20.
- **Impact:** Her Forward's complete 136-user HWS directory contained actor ID
  9, but Publish could not match the campaign's configured WordPress login
  because that account's nicename differs from its login.

**Root cause.** The signed plugin directory returned `slug` from
`user_nicename` and the package relabeled that value as `user_login`. The real
login was omitted despite the route already requiring HMAC authentication and
the administrator `list_users` capability.

**Patch.** HWS Base Tools now returns `login` explicitly. The package prefers
that field, retains the nicename separately as `slug`, and keeps slug fallback
for native core REST responses that do not expose WordPress logins.

**Guard — do not remove.** Never relabel a WordPress nicename as a login when
the selected transport supplies both. Keep native REST slug compatibility.

## CAMPAIGN-BUG-077 — REST author discovery stopped after the first 100 users

- **Severity:** High (configured campaign authors outside page 1 could not publish)
- **Status:** Patched 2026-09-21 02:45:15 EST in 2.0.77.
- **Impact:** Her Forward's HWS bridge returned 100 valid authors, but the site
  has 136 users and the selected campaign author was outside the first page.
  Campaign integrity therefore stopped operation 6871 before generation.

**Root cause.** WP Toolkit author inventory already loaded the complete bounded
directory, while native REST and HWS Base Tools requested only the first
100-row WordPress REST page and treated it as complete.

**Patch.** Both external transports now traverse consecutive 100-row author
pages until the final partial page, deduplicate by WordPress user ID and stop
at the existing 10,000-user safety bound. A page failure fails the lookup
instead of returning a misleading partial directory.

**Guard — do not remove.** A REST-backed author may not be declared absent from
page 1 alone. Native Application Password and HWS bridge discovery must share
the same bounded pagination behavior.

## CAMPAIGN-BUG-075 — REST field filtering erased the HWS author list

- **Severity:** High (all HWS bridge campaign operations stopped before generation)
- **Status:** Patched 2026-09-21 02:37:23 EST in 2.0.76.
- **Impact:** Her Forward operation 6871 authenticated and reached the dedicated
  author endpoint, but Publish received an empty author list. No article body,
  AI charge, WordPress post or media was created.

**Root cause.** The shared caller reused the native WordPress users query for
the custom HWS authors route, including `_fields=id,name,slug,email,roles`.
WordPress applied that filter to the custom route's top-level numeric list and
removed every row, returning HTTP 200 with `[]` even though the endpoint found
the site's users.

**Patch.** HWS author discovery now removes `_fields` only after translating
`users` to the custom signed `authors` route. Native Application Password
author discovery keeps its core REST field filter, and all other bridge
queries are unchanged.

**Guard — do not remove.** Never send a top-level `_fields` filter to the HWS
authors route unless that route first adopts a response schema on which the
filter is explicitly supported. Native `/wp/v2/users` requests must retain the
bounded field filter.

## CAMPAIGN-BUG-074 — HWS bridge author discovery used a protected core users path

- **Severity:** High (all HWS bridge campaign operations stopped before generation)
- **Status:** Patched 2026-09-21 02:06:03 EST in 2.0.75 and HWS Base Tools 13.2.19.
- **Impact:** Her Forward operation 6871 passed HMAC authentication but failed author resolution with `Sorry, you are not allowed to list users.` No article body, AI charge, WordPress post or media was created.

**Root cause.** The package routed HWS author discovery through the generic bridge path `/external-publishing/wp/v2/users`. Her Forward's WordPress security layer rejected that outer users path before HWS Base Tools could run its signed bridge permission callback.

**Patch.** HWS Base Tools exposes a dedicated signed `/external-publishing/authors` endpoint with its own `list_users` gate. The WordPress package routes only HWS author discovery to that endpoint; native Application Password and WP Toolkit author discovery are unchanged.

**Guard — do not remove.** HWS bridge author discovery must use the dedicated authors endpoint and must never fall back to Basic authentication or the protected generic users proxy.

## CAMPAIGN-BUG-065 — REST taxonomy resolution missed existing terms after page 1

- **Severity:** High (finished articles could fail before WordPress post creation)
- **Status:** Patched 2026-09-21 01:25:03 EST in 2.0.74.
- **Impact:** Her Forward operation 6865 could not resolve the existing `Law and
  Legal Services` category (term 8701). The pipeline uploaded media and then
  stopped before creating a post, leaving the paid article unpublished.

**Root cause.** The REST-backed taxonomy resolver treated the first 100 terms
returned by the collection endpoint as a complete inventory. When an older term
was not on that page, it attempted a duplicate create and treated WordPress's
`term_exists` response as a hard failure even though the response contained the
canonical existing term ID.

**Patch.** Every requested name absent from the initial page is now searched
directly through the taxonomy endpoint. A race or stale search that still
reaches duplicate creation recovers the existing ID from WordPress's
`term_exists` response. Both native Application Password and HWS Base Tools
transports use the same corrected resolver.

**Guard — do not remove.** A requested REST-backed taxonomy term must not be
declared missing solely because it is outside the first collection page, and a
valid `term_exists` response must resolve to its returned term ID.

## CAMPAIGN-BUG-064 — Plugin bridge stopped before complete article delivery

- **Severity:** High (bridge-only campaigns could not complete the WordPress feature set)
- **Status:** Patched 2026-09-21 00:17:10 EST in 2.0.73 and Publish app 18.17.43.
- **Impact:** The HWS Base Tools transport could authenticate and mutate posts,
  but media uploads, authors, categories, tags, article-type taxonomy, owned
  metadata, article audio and cache purge were either unavailable or still
  routed through native REST credentials.

**Root cause.** The first bridge implementation treated posts as a special case
instead of routing the bounded article-delivery contract through the selected
WordPress transport.

**Patch.** The package now maps posts, media, users and public post taxonomies
to an allowlisted HWS Base Tools proxy, streams media with a body-bound HMAC,
routes plugin-owned audio and cache actions explicitly, and exposes one
19-stage publication feature contract for WP Toolkit, Application Password and
HWS bridge connections.

**Guard — do not remove.** HWS bridge requests must never carry Basic
authentication or silently fall back to Application Password credentials.
Every mutating bridge request remains operation-ID-bound and every route remains
inside the explicit article-publishing allowlist.

---

## CAMPAIGN-BUG-046 — Draft REST updates failed verification after WordPress advanced the post date

- **Severity:** High (external-site drafts could be created but not updated)
- **Status:** Initial preservation attempt in 2.0.71; corrected
  2026-09-19 23:53 EST in 2.0.72.
- **Impact:** Both native WordPress REST and the HWS Base Tools bridge created,
  read, and deleted Her Forward drafts successfully, but an update that omitted
  the date failed closed with `REST post verification failed during staging:
  post_date.`

**Root cause.** WordPress advances an unpublished post's date when it receives
an update without an explicit date. The verifier treated that draft-owned clock
as immutable. Supplying the preflight date in 2.0.71 did not change WordPress's
behavior on the live site.

**Patch.** Explicit dates remain strictly verified. Updates to posts that were
already published, private, or scheduled still require the original date to be
preserved. For an unpublished draft or pending post whose caller supplied no
date, the verifier accepts WordPress's normal clock advancement while continuing
to verify every other field.

**Guard — do not remove.** Never fail an unpublished REST update solely because
WordPress advanced an implicit date. Explicit dates and dates on previously
published, private, or scheduled posts remain verification-bound.

## CAMPAIGN-BUG-045 — Plugin bridge duplicated SMP and reused WordPress passwords

- **Severity:** High (the requested independent plugin-authentication boundary
  was not implemented)
- **Status:** Patched 2026-09-19 23:34 EST in 2.0.70
- **Impact:** External publication exposed equivalent bridge routes through both
  SMP Publication Integration and HWS Base Tools, while both still depended on
  a WordPress username and Application Password. This created redundant plugin
  ownership and did not provide the separately revocable bridge credential the
  connection design required.

**Root cause.** The first transport change treated the two plugin names as two
publishing methods. The intended methods were native WordPress REST and one
plugin bridge, with HWS Base Tools owning the latter.

**Patch.** The package now recognizes only `hws_base_tools` as the plugin
transport and signs each bridge request with a dedicated key ID and secret,
timestamp, nonce, exact route, and SHA-256 body hash. Native REST continues to
use WordPress Application Passwords. Unsupported bridge operations fail closed
instead of silently falling back to Basic authentication.

**Guard — do not remove.** HWS bridge requests must never include an
`Authorization: Basic` header or reuse the WordPress REST credential fields.

## CAMPAIGN-BUG-013 — Staging verification rejected content rewritten by Post Hygiene

- **Severity:** High (paid, finished articles turned back into drafts)
- **Status:** Patched 2026-09-17 in 2.0.68
- **Impact:** Operation #6635 (breaking9to5.com, "National Life Group Earns Third
  Forbes Insurance Recognition") failed with "WordPress post verification failed
  during staging: post_content" and the post was forced back to draft. The same
  message appears on earlier failures (for example article 7339 on 2026-09-14).

**Root cause.** SMP Publication Integration's Post Hygiene hooks
`wp_insert_post_data` (`PostHygiene::sanitize_post_data`, priority 20) and runs
`wp_kses` over `post_content`. WordPress 7.1 stores an apostrophe inside an
attribute as `&apos;` (image alt text `displaying 'Insurance'`). The toolkit
mutation script predicts only core KSES on `content_save_pre`, so its expected
content kept `'` and the exact comparison failed. Hygiene can also strip spans,
inline styles, classes, ids and `data-*` attributes depending on site settings.

**Patch.** `VerifiesWordPressPostMutations` `$canonicalizeKsesField` also runs the
registered `PostHygiene::sanitize_post_data` callback (and no other
`wp_insert_post_data` callback) on expected content, with the post type and ID.

**Evidence.** On breaking9to5.com, inserting the real article 7476 body stored
5,330 bytes that differ from the raw body; the hygiene-aware prediction was
byte-identical to what WordPress stored.

**Guard — do not remove.** The Post Hygiene prediction in the staging expected
content.

**Follow-up.** REST-transport sites compute expected content in Laravel and
cannot run the plugin callback; they would need the plugin to expose its
normalized result.
