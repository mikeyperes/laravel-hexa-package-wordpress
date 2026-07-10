<?php

namespace hexa_package_wordpress\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\Concerns\WordPressManager\HandlesWordPressRestAndToolkit;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAcf;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAvatars;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressMedia;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressPosts;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressTaxonomies;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUserAccounts;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUsersAndMeta;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;

class WordPressManagerService
{
    use HandlesWordPressRestAndToolkit;
    use ManagesWordPressAcf;
    use ManagesWordPressAvatars;
    use ManagesWordPressMedia;
    use ManagesWordPressPosts;
    use ManagesWordPressTaxonomies;
    use ManagesWordPressUserAccounts;
    use ManagesWordPressUsersAndMeta;

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

        return [
            "mode" => $mode === "wptoolkit" ? "wptoolkit" : "rest",
            "site_name" => (string) ($target["site_name"] ?? $target["name"] ?? "WordPress site"),
            "url" => rtrim((string) ($target["url"] ?? $target["site_url"] ?? ""), "/"),
            "username" => (string) ($target["username"] ?? $target["wp_username"] ?? ""),
            "application_password" => (string) ($target["application_password"] ?? $target["wp_application_password"] ?? $target["app_password"] ?? ""),
            "server" => $server instanceof WhmServer ? $server : null,
            "install_id" => $installId > 0 ? $installId : null,
            "cpanel_user" => (string) ($target["cpanel_user"] ?? $target["cpanel_username"] ?? ""),
            "wp_path" => trim((string) ($target["wp_path"] ?? $target["wordpress_path"] ?? "public_html"), "/"),
            "default_author" => (string) ($target["default_author"] ?? ""),
            "site_id" => isset($target["site_id"]) ? (int) $target["site_id"] : null,
        ];
    }

    public function usesWpToolkit(array $target): bool
    {
        $target = $this->normalizeTarget($target);
        return $target["mode"] === "wptoolkit" && $target["server"] instanceof WhmServer && !empty($target["install_id"]);
    }

    public function connectionMode(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionMode($target["server"]);
        }

        return "rest";
    }

    public function connectionLabel(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionLabel($target["server"]);
        }

        return "REST API";
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

        if (($target["url"] ?? "") === "" || ($target["username"] ?? "") === "" || ($target["application_password"] ?? "") === "") {
            return [
                "success" => false,
                "message" => "REST credentials are incomplete.",
                "mode" => "rest",
                "label" => "REST API",
            ];
        }

        return [
            "success" => true,
            "message" => "REST credentials ready.",
            "mode" => "rest",
            "label" => "REST API",
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

        return $this->rest->testConnection($target["url"], $target["username"], $target["application_password"]);
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
