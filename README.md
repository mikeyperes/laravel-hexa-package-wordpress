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
- `recordCheck()` saves the outcome of a check; its report sections merge,
  so one tool's check never erases another's.

Models use the `Connections\Concerns\UsesWordPressConnection` trait and map
their own attribute names to connection facts in
`wordpressConnectionAttributes()` (`site_url`, `transport`, hosting facts,
`report:<section>`, `secret:<name>`, `relation:server`). Reads come from the
connection; writes are applied to it when the model saves, and a changed
address or install points the model at the matching connection instead of
rewriting a shared one. Scopes: `whereSiteHost($url)`,
`whereWordPressConnection(fn ($connection) => ...)`.

### Connecting a site with WordPress's Authorize Application

`wordpress:app-password <site> [--label=] [--app="Hexa PR Wire"]` remembers the
site as a `rest` connection and prints its one-time
`/wp-admin/authorize-application.php` link (valid 30 minutes). A signed-in
administrator opens it and clicks **Yes, I approve of this connection**;
WordPress sends the new Application Password straight to
`/wordpress/app-password/callback/{state}`, which checks it is a working
administrator (`/wp/v2/users/me`), stores it through the registry and records
an `authorization` report section. The password never passes through a person.
`--status` reports the connection with the secret as present/missing only.
WordPress puts the password in the callback URL's query string, so the
approving browser's history and the web server's access log hold it; use a
trusted browser profile.

## Person-to-page links

Records that tie something of ours to one WordPress object (a journalist to a
WordPress user, a verified profile to a WordPress post) use
`Connections\Concerns\AttachesToWordPressObject`. The model names its site
relation, object kind, id column, snapshot columns and sync columns in
`wordpressAttachment()`, and gets `wordpressPullAttributes()`,
`wordpressPushAttributes()`, `wordpressOutdatedAttributes()`,
`wordpressRemovedAttributes()`, `markWordPressOutdated()`, `remoteSnapshot()`
and `wordpressEditUrl()`. Every stored copy passes through
`WordPressSnapshot::clean()`, which drops passwords, activation keys, login
sessions and application-password hashes.

## Campaign bug log

[BUGLOG.md](BUGLOG.md) records every critical and high-severity delivery bug,
its root cause and the code that fixed it. Read it before changing post
mutation or verification code. Code marked `CRITICAL — see ... BUGLOG.md
CAMPAIGN-BUG-NNN` must not be removed or simplified away. New critical bugs are
logged there in the same commit as their patch.

## Verified PHP evaluation

`WordPressManagerService::evaluatePhp` is the shared owner for plugin-loaded evaluation. Each submitted program has flushed begin/end receipts; a zero shell exit alone never proves execution. Receipts are removed from returned user output. Started or uncertain payloads are never replayed, preserving mutation safety.

One deliberate second route is allowed here: a native process that completes with exit 0 and entirely empty output did not reach the begin receipt. The shared evaluator then makes one WP Toolkit CLI attempt, recording `execution_route`, `native_exit_code`, `evaluation_started` and `evaluation_completed`. It never retries a partial payload, a nonzero native failure or an uncertain transport result. Consumers call this evaluator once and do not implement transport fallbacks.

Receipt matching is independent of transport boundary whitespace; only complete bound receipt lines are removed from caller output.

## Author address changes

`WordPressManagerService::changeAuthorSlug($target, $userId, $newSlug, $redirectFrom = [])`
changes one author page address (`/author/<slug>/`, the user's `user_nicename`)
on a WP Toolkit site. The login, posts and profile stay the same. Every earlier
address gets a permanent (301) redirect in Rank Math's redirect manager, which
is switched on when it is off. It refuses an address another user owns or one
a Rank Math redirect already sends elsewhere, and undoes the change when the
redirect cannot be saved. Only the old and new author pages are cleared from
LiteSpeed; the site cache is never purged. Profiles calls it from
`profiles:journalist-author-address`.

### Author photo delivery

Require active Simple Local Avatars, reject competing providers, never write legacy avatar keys, remove outgoing SLA derivatives on rollback and expose served photo URLs.
