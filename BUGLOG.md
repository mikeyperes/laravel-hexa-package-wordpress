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
