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
