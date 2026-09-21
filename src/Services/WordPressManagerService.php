<?php

namespace hexa_package_wordpress\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\Concerns\WordPressManager\HandlesWordPressRestAndToolkit;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ExecutesWordPressRestRoutes;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAcf;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAvatars;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressMedia;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressPosts;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressTaxonomies;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUserAccounts;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUsersAndMeta;
use hexa_package_wordpress\Services\Concerns\WordPressManager\VerifiesWordPressPostMutations;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WordPressManagerService
{
    use HandlesWordPressRestAndToolkit;
    use ExecutesWordPressRestRoutes;
    use ManagesWordPressAcf;
    use ManagesWordPressAvatars;
    use ManagesWordPressMedia;
    use ManagesWordPressPosts;
    use ManagesWordPressTaxonomies;
    use ManagesWordPressUserAccounts;
    use ManagesWordPressUsersAndMeta;
    use VerifiesWordPressPostMutations;

    public function __construct(
        protected WpToolkitService $wptoolkit,
        protected WordPressService $rest,
    ) {
    }

    public function normalizeTarget(array $target): array
    {
        $mode = (string) ($target["mode"] ?? $target["connection_type"] ?? "");
        $server = $target["server"] ?? null;
        if (is_array($server) && isset($server["id"])) {
            $server = WhmServer::query()->find((int) $server["id"]);
        } elseif (is_object($server) && !($server instanceof WhmServer) && isset($server->id)) {
            $server = WhmServer::query()->find((int) $server->id);
        }
        $installId = isset($target["install_id"]) ? (int) $target["install_id"] : (isset($target["wordpress_install_id"]) ? (int) $target["wordpress_install_id"] : 0);

        if ($mode === "") {
            $mode = ($server instanceof WhmServer && $installId > 0) ? "wptoolkit" : "rest";
        }
        $wpPath = trim((string) ($target["wp_path"] ?? $target["wordpress_path"] ?? "public_html"));
        if ($wpPath === "") {
            $wpPath = "public_html";
        } elseif ($wpPath !== "/") {
            $wpPath = rtrim($wpPath, "/");
        }

        $mode = match ($mode) {
            "wptoolkit", "hws_base_tools" => $mode,
            default => "rest",
        };

        return [
            "mode" => $mode,
            "site_name" => (string) ($target["site_name"] ?? $target["name"] ?? "WordPress site"),
            "url" => rtrim((string) ($target["url"] ?? $target["site_url"] ?? ""), "/"),
            "username" => (string) ($target["username"] ?? $target["wp_username"] ?? ""),
            "application_password" => (string) ($target["application_password"] ?? $target["wp_application_password"] ?? $target["app_password"] ?? ""),
            "hws_key_id" => (string) ($target["hws_key_id"] ?? $target["key_id"] ?? $target["api_key_id"] ?? ""),
            "hws_api_secret" => (string) ($target["hws_api_secret"] ?? $target["api_secret"] ?? $target["secret"] ?? ""),
            "server" => $server instanceof WhmServer ? $server : null,
            "install_id" => $installId > 0 ? $installId : null,
            "cpanel_user" => (string) ($target["cpanel_user"] ?? $target["cpanel_username"] ?? ""),
            "wp_path" => $wpPath,
            "default_author" => (string) ($target["default_author"] ?? ""),
            "site_id" => isset($target["site_id"]) ? (int) $target["site_id"] : null,
        ];
    }

    public function usesWpToolkit(array $target): bool
    {
        $target = $this->normalizeTarget($target);
        return $target["mode"] === "wptoolkit" && $target["server"] instanceof WhmServer && !empty($target["install_id"]);
    }

    public function usesPluginTransport(array $target): bool
    {
        return $this->normalizeTarget($target)["mode"] === "hws_base_tools";
    }

    public function connectionMode(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionMode($target["server"]);
        }

        return $target["mode"];
    }

    public function connectionLabel(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionLabel($target["server"]);
        }

        return match ($target["mode"]) {
            "hws_base_tools" => "HWS Base Tools API",
            default => "REST API",
        };
    }

    /**
     * The article-delivery contract, expressed independently of the transport.
     * Pipeline-owned stages remain local; WordPress-owned stages identify the
     * exact capability that each connection mode must provide and test.
     */
    public function publicationFeatures(array $target): array
    {
        $target = $this->normalizeTarget($target);
        $mode = $this->usesWpToolkit($target) ? 'wptoolkit' : $target['mode'];
        $externalMeta = $mode === 'rest' ? 'conditional' : 'supported';

        return [
            'mode' => $mode,
            'label' => $this->connectionLabel($target),
            'features' => [
                ['key' => 'connection', 'label' => 'Connection and authentication', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'html_sanitize', 'label' => 'HTML sanitization', 'owner' => 'pipeline', 'support' => 'supported'],
                ['key' => 'excerpt', 'label' => 'Excerpt', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'post_summary', 'label' => 'ACF post summary', 'owner' => 'wordpress', 'support' => $externalMeta],
                ['key' => 'faq_repeater', 'label' => 'ACF FAQ repeater', 'owner' => 'wordpress', 'support' => $externalMeta],
                ['key' => 'author', 'label' => 'Author resolution and author URL', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'inline_media', 'label' => 'Inline media upload and metadata', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'inline_media_rewrite', 'label' => 'Inline image URL rewrite', 'owner' => 'pipeline', 'support' => 'supported'],
                ['key' => 'featured_media', 'label' => 'Featured media upload and assignment', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'categories', 'label' => 'Category resolution and creation', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'tags', 'label' => 'Tag resolution and creation', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'article_type', 'label' => 'Article-type taxonomy', 'owner' => 'wordpress', 'support' => 'conditional'],
                ['key' => 'internal_links', 'label' => 'Internal-link validation and refill', 'owner' => 'pipeline', 'support' => 'supported'],
                ['key' => 'integrity', 'label' => 'Delivery integrity checks', 'owner' => 'pipeline', 'support' => 'supported'],
                ['key' => 'post_write', 'label' => 'Post create, update, and status', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'permalink', 'label' => 'Permalink readback', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'author_readback', 'label' => 'Author confirmation readback', 'owner' => 'wordpress', 'support' => 'supported'],
                ['key' => 'article_audio', 'label' => 'Article audio generation', 'owner' => 'wordpress', 'support' => 'conditional'],
                ['key' => 'cleanup', 'label' => 'Temporary upload cleanup', 'owner' => 'pipeline', 'support' => 'supported'],
            ],
            'optional' => [
                ['key' => 'rank_math_readback', 'label' => 'Rank Math score readback', 'support' => $externalMeta],
                ['key' => 'cache_purge', 'label' => 'Site cache purge', 'support' => $mode === 'rest' ? 'conditional' : 'supported'],
            ],
        ];
    }

    public function warmConnection(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            $ssh = $this->wptoolkit->getConnection($target["server"]);

            return [
                "success" => (bool) ($ssh["success"] ?? false),
                "message" => (bool) ($ssh["success"] ?? false)
                    ? $this->connectionLabel($target) . " ready."
                    : (string) ($ssh["error"] ?? "WP Toolkit connection failed."),
                "mode" => $this->connectionMode($target),
                "label" => $this->connectionLabel($target),
            ];
        }

        $credentialsMissing = $target["mode"] === "hws_base_tools"
            ? ($target["url"] ?? "") === "" || ($target["hws_key_id"] ?? "") === "" || ($target["hws_api_secret"] ?? "") === ""
            : ($target["url"] ?? "") === "" || ($target["username"] ?? "") === "" || ($target["application_password"] ?? "") === "";
        if ($credentialsMissing) {
            return [
                "success" => false,
                "message" => $target["mode"] === "hws_base_tools"
                    ? "HWS Base Tools key credentials are incomplete."
                    : "WordPress Application Password credentials are incomplete.",
                "mode" => $target["mode"],
                "label" => $this->connectionLabel($target),
            ];
        }

        return [
            "success" => true,
            "message" => $this->connectionLabel($target)." credentials ready.",
            "mode" => $target["mode"],
            "label" => $this->connectionLabel($target),
        ];
    }

    private function toolkitCacheBase(array $target, string $bucket): string
    {
        $target = $this->normalizeTarget($target);
        $server = $target["server"] instanceof WhmServer ? $target["server"] : null;
        $serverKey = $server ? ((string) ($server->id ?: $server->hostname)) : "rest";
        $installKey = (string) ($target["install_id"] ?: "0");

        return "wordpress-manager:" . $bucket . ":server:" . $serverKey . ":install:" . $installKey;
    }

    private function toolkitCacheVersion(array $target, string $bucket): string
    {
        return (string) Cache::get($this->toolkitCacheBase($target, $bucket) . ":version", "1");
    }

    private function toolkitCacheKey(array $target, string $bucket, string $suffix = ""): string
    {
        return $this->toolkitCacheBase($target, $bucket) . ":v" . $this->toolkitCacheVersion($target, $bucket) . ($suffix !== "" ? (":" . $suffix) : "");
    }

    private function bumpToolkitCacheVersion(array $target, string $bucket): void
    {
        Cache::forever($this->toolkitCacheBase($target, $bucket) . ":version", (string) microtime(true));
    }

    private function filterUserRows(array $users, array $filters): array
    {
        $rows = array_values(array_filter($users, "is_array"));
        if ($filters["include"] !== []) {
            $include = array_flip(array_map("intval", $filters["include"]));
            $rows = array_values(array_filter($rows, static fn (array $user): bool => isset($include[(int) ($user["id"] ?? $user["ID"] ?? 0)])));
        }
        if ($filters["role"] !== "") {
            $role = strtolower($filters["role"]);
            $rows = array_values(array_filter($rows, static function (array $user) use ($role): bool {
                $roles = array_map(static fn ($item): string => strtolower((string) $item), (array) ($user["roles"] ?? []));
                return in_array($role, $roles, true);
            }));
        }
        if ($filters["search"] !== "") {
            $needle = strtolower($filters["search"]);
            $rows = array_values(array_filter($rows, static function (array $user) use ($needle): bool {
                $haystack = strtolower(trim(implode(" ", [
                    (string) ($user["user_login"] ?? ""),
                    (string) ($user["user_email"] ?? ""),
                    (string) ($user["display_name"] ?? ""),
                ])));
                return $needle === "" || str_contains($haystack, $needle);
            }));
        }

        return array_slice($rows, 0, $filters["per_page"]);
    }

    private function findExistingUser(array $target, string $login, string $email = "", bool $forceRefresh = false): array|null
    {
        $needles = array_values(array_unique(array_filter([strtolower(trim($login)), strtolower(trim($email))])));
        if ($needles === []) {
            return null;
        }

        foreach ($needles as $needle) {
            $result = $this->listUsers($target, ["search" => $needle, "per_page" => 200, "force_refresh" => $forceRefresh]);
            foreach ((array) ($result["users"] ?? []) as $user) {
                $userLogin = strtolower(trim((string) ($user["user_login"] ?? "")));
                $userEmail = strtolower(trim((string) ($user["user_email"] ?? "")));
                if (($login !== "" && $userLogin === strtolower($login)) || ($email !== "" && $userEmail === strtolower($email))) {
                    return $this->normalizeUserRow((array) $user);
                }
            }
        }

        return null;
    }

    public function discoverInstallsForAccount(WhmServer $server, string $cpanelUsername): array
    {
        return $this->wptoolkit->getInstallsForAccount($server, $cpanelUsername);
    }

    public function testConnection(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->wpCliTestWriteAccess($target["server"], (int) $target["install_id"]);
        }

        if ($this->usesPluginTransport($target)) {
            $route = $this->pluginPublishingRoute($target);
            $result = $this->rest->signedRequestRoute($target["url"], $target["hws_key_id"], $target["hws_api_secret"], "get", $route, timeoutSeconds: 15);
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($result["message"] ?? "Plugin publishing connection failed."), "data" => null];
            }
            $data = (array) ($result["data"] ?? []);
            return [
                "success" => ($data["enabled"] ?? false) === true,
                "message" => $this->connectionLabel($target)." connected and enabled.",
                "data" => $data,
            ];
        }

        return $this->rest->testConnection($target["url"], $target["username"], $target["application_password"]);
    }

    /**
     * Build a secret-free, persistable connection and capability report.
     *
     * The report intentionally retains only presence booleans, safe WordPress
     * actor fields, endpoint status, namespaces, and the publication feature
     * contract. It never returns a password, signing secret, auth header, nonce,
     * cookie, or raw remote payload.
     */
    public function connectionReport(array $target): array
    {
        $target = $this->normalizeTarget($target);
        $authentication = $this->testConnection($target);
        $contract = $this->publicationFeatures($target);
        $mode = $this->usesWpToolkit($target) ? 'wptoolkit' : $target['mode'];
        $modeId = $mode === 'rest' ? 'wp_rest_api' : $mode;
        $restIndex = $target['url'] !== ''
            ? $this->rest->discoverRestIndex($target['url'])
            : ['success' => false, 'message' => 'No WordPress URL is configured.', 'status' => null, 'namespaces' => [], 'route_count' => 0];
        $namespaces = array_values(array_filter((array) ($restIndex['namespaces'] ?? []), 'is_string'));

        $endpointDefinitions = [
            'posts' => ['label' => 'Posts', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'media' => ['label' => 'Media', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'categories' => ['label' => 'Categories', 'query' => ['per_page' => 1, '_fields' => 'id']],
            'tags' => ['label' => 'Tags', 'query' => ['per_page' => 1, '_fields' => 'id']],
            'users' => ['label' => 'Users', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'types' => ['label' => 'Post types', 'query' => ['context' => 'edit', '_fields' => 'slug,rest_base']],
            'taxonomies' => ['label' => 'Taxonomies', 'query' => ['context' => 'edit', '_fields' => 'slug,rest_base']],
        ];
        $endpoints = [];
        $taxonomies = [];

        if (($authentication['success'] ?? false) === true && $mode !== 'wptoolkit') {
            foreach ($endpointDefinitions as $resource => $definition) {
                $result = $this->restRequest($target, 'GET', $resource, query: $definition['query']);
                $endpoints[$resource] = [
                    'label' => $definition['label'],
                    'supported' => (bool) ($result['success'] ?? false),
                    'status' => is_numeric($result['status'] ?? null) ? (int) $result['status'] : null,
                ];
                if ($resource === 'taxonomies' && ($result['success'] ?? false) === true) {
                    $taxonomies = array_values(array_filter(array_map(
                        static fn (mixed $slug): string => is_string($slug)
                            && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $slug) === 1
                                ? $slug
                                : '',
                        array_keys((array) ($result['data'] ?? [])),
                    )));
                    sort($taxonomies);
                }
            }
        } elseif (($authentication['success'] ?? false) === true && $mode === 'wptoolkit') {
            foreach ($endpointDefinitions as $resource => $definition) {
                $endpoints[$resource] = [
                    'label' => $definition['label'],
                    'supported' => true,
                    'status' => null,
                ];
            }
        }

        $rankMathAvailable = $this->hasNamespace($namespaces, 'rankmath/');
        $yoastAvailable = $this->hasNamespace($namespaces, 'yoast/');
        $acfAvailable = $this->hasNamespace($namespaces, 'acf/');
        $articleAudioAvailable = $this->hasNamespace($namespaces, 'smp-tts/');
        $articleTaxonomyAvailable = count(array_intersect(
            $taxonomies,
            ['article_type', 'article-type', 'publication', 'publications', 'smp_article_type'],
        )) > 0;
        $indexKnown = (bool) ($restIndex['success'] ?? false);

        $features = array_map(function (array $feature) use ($acfAvailable, $articleAudioAvailable, $articleTaxonomyAvailable, $indexKnown, $mode): array {
            if (in_array($feature['key'], ['post_summary', 'faq_repeater'], true)) {
                $feature['support'] = $indexKnown ? ($acfAvailable ? 'conditional' : 'unsupported') : 'conditional';
                $feature['detail'] = $indexKnown
                    ? ($acfAvailable ? 'ACF REST namespace detected; field registration is verified during publication.' : 'No ACF REST namespace detected.')
                    : 'ACF REST availability was not determined.';
            } elseif ($feature['key'] === 'article_type') {
                $feature['support'] = $mode === 'wptoolkit'
                    ? 'conditional'
                    : ($articleTaxonomyAvailable ? 'supported' : ($indexKnown ? 'unsupported' : 'conditional'));
                $feature['detail'] = $articleTaxonomyAvailable
                    ? 'An article taxonomy REST resource was detected.'
                    : 'No article taxonomy REST resource was detected.';
            } elseif ($feature['key'] === 'article_audio') {
                $feature['support'] = $indexKnown ? ($articleAudioAvailable ? 'supported' : 'unsupported') : 'conditional';
                $feature['detail'] = $articleAudioAvailable
                    ? 'Article-audio namespace detected.'
                    : 'Article-audio namespace not detected.';
            }

            return $feature;
        }, (array) ($contract['features'] ?? []));

        $optional = array_map(function (array $feature) use ($rankMathAvailable, $yoastAvailable, $indexKnown): array {
            if ($feature['key'] === 'rank_math_readback') {
                $feature['support'] = $indexKnown ? ($rankMathAvailable ? 'supported' : 'unsupported') : 'conditional';
                $feature['detail'] = $rankMathAvailable
                    ? 'Rank Math namespace detected.'
                    : ($yoastAvailable ? 'Yoast is active instead of Rank Math.' : 'Rank Math namespace not detected.');
            }

            return $feature;
        }, (array) ($contract['optional'] ?? []));

        $endpointReady = $endpoints !== []
            && collect($endpoints)->every(static fn (array $endpoint): bool => $endpoint['supported'] === true);
        $transportReady = (bool) ($authentication['success'] ?? false);
        $capabilityState = ! $transportReady
            ? 'blocked'
            : ($endpointReady ? 'ready' : 'limited');
        $authData = is_array($authentication['data'] ?? null) ? $authentication['data'] : [];

        $report = [
            'schema_version' => 1,
            'validated_at' => now()->utc()->toIso8601String(),
            'mode_id' => $modeId,
            'mode_label' => $this->connectionLabel($target),
            'configuration_state' => $this->connectionConfigured($target) ? 'configured' : 'incomplete',
            'transport_state' => $transportReady ? 'ready' : 'blocked',
            'capability_state' => $capabilityState,
            'validation' => [
                'success' => $transportReady,
                'message' => trim((string) ($authentication['message'] ?? '')),
            ],
            'authentication' => [
                'method' => match ($mode) {
                    'wptoolkit' => 'Protected WP Toolkit installation binding',
                    'hws_base_tools' => 'HMAC-signed HWS Base Tools key',
                    default => 'WordPress Application Password',
                },
                'credentials' => match ($mode) {
                    'wptoolkit' => [
                        'server_binding' => $target['server'] instanceof WhmServer,
                        'installation_binding' => ! empty($target['install_id']),
                    ],
                    'hws_base_tools' => [
                        'key_id' => trim($target['hws_key_id']) !== '',
                        'signing_secret' => $target['hws_api_secret'] !== '',
                    ],
                    default => [
                        'username' => trim($target['username']) !== '',
                        'application_password' => $target['application_password'] !== '',
                    ],
                },
                'actor' => array_filter([
                    'configured_username' => trim($target['username']) !== '' ? trim($target['username']) : trim($target['default_author']),
                    'user_id' => isset($authData['user_id']) ? (int) $authData['user_id'] : null,
                    'display_name' => isset($authData['user_name']) ? trim((string) $authData['user_name']) : null,
                    'slug' => isset($authData['user_slug']) ? trim((string) $authData['user_slug']) : null,
                    'roles' => array_values(array_filter((array) ($authData['roles'] ?? []), 'is_string')),
                ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
            ],
            'rest' => [
                'index_available' => $indexKnown,
                'index_status' => is_numeric($restIndex['status'] ?? null) ? (int) $restIndex['status'] : null,
                'route_count' => (int) ($restIndex['route_count'] ?? 0),
                'namespaces' => $namespaces,
                'endpoints' => $endpoints,
            ],
            'integrations' => [
                'seo_plugin' => $rankMathAvailable ? 'rank_math' : ($yoastAvailable ? 'yoast' : ($indexKnown ? 'none_detected' : 'unknown')),
                'rank_math_available' => $rankMathAvailable,
                'yoast_available' => $yoastAvailable,
                'acf_rest_available' => $indexKnown ? $acfAvailable : null,
                'article_audio_available' => $indexKnown ? $articleAudioAvailable : null,
                'custom_taxonomies' => array_values(array_diff($taxonomies, ['category', 'post_tag'])),
            ],
            'features' => $features,
            'optional_features' => $optional,
        ];

        return $authentication + [
            'connection_report' => $report,
            'publication_features' => [
                'mode' => $contract['mode'] ?? $mode,
                'label' => $contract['label'] ?? $this->connectionLabel($target),
                'features' => $features,
                'optional' => $optional,
            ],
        ];
    }

    /** @param array<int, string> $namespaces */
    private function hasNamespace(array $namespaces, string $prefix): bool
    {
        return collect($namespaces)->contains(
            static fn (string $namespace): bool => str_starts_with(strtolower($namespace), strtolower($prefix)),
        );
    }

    private function connectionConfigured(array $target): bool
    {
        if ($this->usesWpToolkit($target)) {
            return $target['server'] instanceof WhmServer && ! empty($target['install_id']);
        }

        if ($this->usesPluginTransport($target)) {
            return $target['url'] !== '' && $target['hws_key_id'] !== '' && $target['hws_api_secret'] !== '';
        }

        return $target['url'] !== '' && $target['username'] !== '' && $target['application_password'] !== '';
    }

    private function pluginPublishingRoute(array $target, string $suffix = ""): string
    {
        $base = "hws-base-tools/v1/external-publishing";

        return $base.($suffix !== "" ? "/".ltrim($suffix, "/") : "");
    }

    private function pluginOperationId(): string
    {
        return "publish:".(string) Str::uuid();
    }

    public function testWriteAccess(array $target): array
    {
        return $this->testConnection($target);
    }

    public function inspectPlugin(array $target, string $slug, array $bootstrapCandidates = ['initialization.php', 'plugin.php']): array
    {
        $target = $this->normalizeTarget($target);
        $slug = trim($slug, " \t\n\r\0\x0B/");
        $bootstrapCandidates = array_values(array_filter(array_map(static fn ($candidate) => trim((string) $candidate), $bootstrapCandidates)));

        if ($slug === '') {
            return ['success' => false, 'message' => 'Plugin slug is required.', 'plugin' => null];
        }

        if (!$this->usesWpToolkit($target)) {
            return [
                'success' => false,
                'message' => 'Plugin inspection is only available on WP Toolkit targets.',
                'plugin' => null,
            ];
        }

        $parts = [
            'require_once ABSPATH . "wp-admin/includes/plugin.php";',
            '$slug=' . var_export($slug, true) . ';',
            '$bootstrapCandidates=' . var_export($bootstrapCandidates, true) . ';',
            '$dir=trailingslashit(WP_PLUGIN_DIR) . $slug;',
            '$found=is_dir($dir);',
            '$plugins=$found ? (array) get_plugins("/" . $slug) : [];',
            '$availableFiles=array_values(array_map("strval", array_keys($plugins)));',
            '$bootstrapFile=""; $pluginFile=""; $pluginData=[];',
            'foreach ($bootstrapCandidates as $candidate) { if (isset($plugins[$candidate])) { $bootstrapFile=(string) $candidate; $pluginFile=$slug . "/" . $bootstrapFile; $pluginData=(array) $plugins[$candidate]; break; } }',
            'if ($pluginFile === "" && !empty($plugins)) { $firstKey=array_key_first($plugins); $bootstrapFile=(string) $firstKey; $pluginFile=$slug . "/" . $bootstrapFile; $pluginData=(array) ($plugins[$firstKey] ?? []); }',
            '$active=$pluginFile !== "" && (is_plugin_active($pluginFile) || (function_exists("is_plugin_active_for_network") && is_plugin_active_for_network($pluginFile)));',
            '$payload=[',
            '"slug"=>$slug,',
            '"found"=>$found,',
            '"active"=>$active,',
            '"directory"=>$dir,',
            '"plugin_file"=>$pluginFile,',
            '"bootstrap_file"=>$bootstrapFile,',
            '"available_files"=>$availableFiles,',
            '"name"=>(string) ($pluginData["Name"] ?? ""),',
            '"version"=>(string) ($pluginData["Version"] ?? ""),',
            '"description"=>(string) ($pluginData["Description"] ?? ""),',
            '"author"=>(string) ($pluginData["Author"] ?? ""),',
            '];',
            'echo "HEXA_PLUGIN_INSPECT:" . wp_json_encode($payload);',
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Plugin inspection failed.'), 'plugin' => null];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_PLUGIN_INSPECT:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse plugin inspection output.', 'plugin' => null];
        }

        $found = (bool) ($payload['found'] ?? false);
        $active = (bool) ($payload['active'] ?? false);
        $message = !$found
            ? "Plugin {$slug} was not found."
            : ($active ? "Plugin {$slug} is active." : "Plugin {$slug} is installed but inactive.");

        return [
            'success' => true,
            'message' => $message,
            'plugin' => $payload,
        ];
    }


    public function syncPluginFromGitHub(array $target, array $plugin): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ['success' => false, 'message' => 'Plugin GitHub sync is only available on WP Toolkit targets.'];
        }

        $slug = trim((string) ($plugin['slug'] ?? $plugin['plugin_directory'] ?? ''), " \t\n\r\0\x0B/");
        $githubUrl = rtrim(trim((string) ($plugin['github_url'] ?? '')), '/');
        $bootstrap = trim((string) ($plugin['bootstrap'] ?? $plugin['bootstrap_file'] ?? 'initialization.php'), " \t\n\r\0\x0B/");
        $cpanelUser = trim((string) ($plugin['cpanel_user'] ?? $target['cpanel_user'] ?? ''));
        $wpPath = trim((string) ($plugin['wp_path'] ?? $plugin['wordpress_path'] ?? $target['wp_path'] ?? 'public_html'), '/');

        if ($cpanelUser === '') {
            return ['success' => false, 'message' => 'cPanel username is required for plugin GitHub sync.'];
        }

        return $this->wptoolkit->syncPluginFromGitHub(
            $target['server'],
            $cpanelUser,
            $wpPath !== '' ? $wpPath : 'public_html',
            $slug,
            $githubUrl,
            $bootstrap !== '' ? $bootstrap : 'initialization.php'
        );
    }

}
