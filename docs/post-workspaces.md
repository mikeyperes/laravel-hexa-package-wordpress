# WordPress Post Workspaces

`WordPressPostSnapshotService` is the package-owned read model for remote post
previews. It returns standard post fields, author and login data, taxonomies,
featured media, content, status, timestamps, and a cache rebuild timestamp. Raw post
metadata is never returned to the browser.

Page rendering must call `cached()`. It performs no remote request and returns
the last persistent snapshot immediately. Only an explicit user action should
call `refresh()`. A successful refresh rebuilds the persistent cache; a failed
refresh retains and returns the prior snapshot with a stale/error marker.

Provider packages can implement `WordPressPostSnapshotExtension` and register
the implementation with `WordPressPostSnapshotExtensionRegistry`. Extensions
receive the internal snapshot before metadata is removed, allowing a provider
to expose a small, explicit payload such as distribution deliverables without
coupling the WordPress package to that provider.

Render `wordpress::post-workspace.shell` with a consumer-owned refresh URL and
an `initialWorkspace` value from `cached()`, plus optional dashboard/editor login
URLs. The package-owned JavaScript renders that cache without a loader or remote
request. Pressing Refresh sends one CSRF-protected POST, rebuilds the cache,
renders the result, and reports its relative age. Refresh failures leave the
previous preview intact.

`WordPressLoginUrlService` owns WP Toolkit install, cPanel path, HTTPS host,
WordPress user, and returned-login-URL validation. Consumers can request a
specific user for customer access or omit the user ID to use the installation
administrator. Consumer controllers remain responsible for authorization,
auditing, throttling, and deciding the redirect path.
