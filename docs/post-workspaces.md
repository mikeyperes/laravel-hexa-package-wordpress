# WordPress Post Workspaces

`WordPressPostSnapshotService` is the package-owned read model for remote post
previews. It returns standard post fields, author and login data, taxonomies,
featured media, content, status, timestamps, and a refresh timestamp. Raw post
metadata is never returned to the browser.

Provider packages can implement `WordPressPostSnapshotExtension` and register
the implementation with `WordPressPostSnapshotExtensionRegistry`. Extensions
receive the internal snapshot before metadata is removed, allowing a provider
to expose a small, explicit payload such as distribution deliverables without
coupling the WordPress package to that provider.

Render `wordpress::post-workspace.shell` with a consumer-owned refresh URL and
optional dashboard/editor login URLs. The package-owned JavaScript fetches the
latest snapshot, renders a sandboxed post preview, displays post metadata and
extension output, and reports refresh failures without changing the post.

`WordPressLoginUrlService` owns WP Toolkit install, cPanel path, HTTPS host,
WordPress user, and returned-login-URL validation. Consumers can request a
specific user for customer access or omit the user ID to use the installation
administrator. Consumer controllers remain responsible for authorization,
auditing, throttling, and deciding the redirect path.
