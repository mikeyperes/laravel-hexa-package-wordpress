# Laravel Hexa Package — WordPress

WordPress site management and verified post delivery (WP Toolkit and REST
transports) for HWS Laravel applications.

## WordPress connections

`wordpress_connections` holds one row per WordPress site we can reach: its
address, transport (`wptoolkit`, `rest` or `hws_base_tools`), WHM server,
hosting account and install, REST user and HWS key id, last check and
capability report. Secrets (application password, HWS API secret) are stored
only in Core's `CredentialService` under `wordpress_connection_{id}`.

Tools keep a `wordpress_connection_id` and their own tool-specific data:
journalist publications (laravel-hexa-package-profiles), verified-profile
sites (laravel-hexa-package-smp-verified-profiles) and Publish sites.

Use `hexa_package_wordpress\Connections\WordPressConnectionRegistry`:

- `remember($facts, $overwrite = false)` finds a site by server and install
  (WP Toolkit) or host (other transports), creates it when new, and fills
  blank facts without erasing known ones.
- `storeSecret()` / `secret()` / `hasSecret()` read and write the vault.
- `target($connection, $context)` builds the `WordPressManagerService` target;
  WP Toolkit targets never read secrets.
- `recordCheck()` saves the outcome and capability report of a check.

## Campaign bug log

[BUGLOG.md](BUGLOG.md) records every critical and high-severity delivery bug,
its root cause and the code that fixed it. Read it before changing post
mutation or verification code. Code marked `CRITICAL — see ... BUGLOG.md
CAMPAIGN-BUG-NNN` must not be removed or simplified away. New critical bugs are
logged there in the same commit as their patch.
