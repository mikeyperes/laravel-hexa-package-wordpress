# Agent instructions — laravel-hexa-package-wordpress

## Campaign bug log

- Do not read the whole BUGLOG. Before editing a file, find its guards with
  `grep -n "CRITICAL — see" <file>` and read only those entries
  (`grep -n "^## CAMPAIGN-BUG-NNN" BUGLOG.md`, here or in laravel-hexa-app-publish). To check an area for past
  incidents, search the entry titles: `grep -n "^## " BUGLOG.md`.
- Code marked `CRITICAL — see ... BUGLOG.md CAMPAIGN-BUG-NNN` is a regression
  guard for a production incident. Do not remove or "simplify" it. A refactor
  that moves it must keep the behavior and update that BUGLOG entry in the same
  commit.
- When you find or fix a critical or high-severity delivery bug, add a BUGLOG.md
  entry (symptom, impact, root cause, patch, guard) in the same commit and put
  the bug ID in the commit message.
