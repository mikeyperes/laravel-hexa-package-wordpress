<?php

namespace hexa_package_wordpress\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class WordPressPluginIntegrityService
{
    public function __construct(
        protected WpToolkitService $wpToolkit
    ) {
    }

    public function inspectInstalledPlugin(WhmServer $server, int $installId, string $slug, array $bootstrapCandidates = []): array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return ['success' => false, 'message' => 'Plugin slug is required.', 'plugin' => null, 'manifest' => []];
        }

        $bootstrapCandidates = $bootstrapCandidates ?: [$slug . '.php', 'initialization.php', 'plugin.php'];
        $php = <<<'PHP'
require_once ABSPATH . "wp-admin/includes/plugin.php";
$slug = __SLUG__;
$bootstrapCandidates = __BOOTSTRAP_CANDIDATES__;
$root = trailingslashit(WP_PLUGIN_DIR) . $slug;
$found = is_dir($root);
$plugins = $found ? (array) get_plugins("/" . $slug) : [];
$availableFiles = array_values(array_map("strval", array_keys($plugins)));
$bootstrapFile = "";
$pluginFile = "";
$pluginData = [];
foreach ($bootstrapCandidates as $candidate) {
    if (isset($plugins[$candidate])) {
        $bootstrapFile = (string) $candidate;
        $pluginFile = $slug . "/" . $bootstrapFile;
        $pluginData = (array) $plugins[$candidate];
        break;
    }
}
if ($pluginFile === "" && !empty($plugins)) {
    $firstKey = array_key_first($plugins);
    $bootstrapFile = (string) $firstKey;
    $pluginFile = $slug . "/" . $bootstrapFile;
    $pluginData = (array) ($plugins[$firstKey] ?? []);
}
$active = $pluginFile !== "" && (is_plugin_active($pluginFile) || (function_exists("is_plugin_active_for_network") && is_plugin_active_for_network($pluginFile)));
$manifest = [];
if ($found) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $rel = str_replace("\\", "/", substr($path, strlen($root) + 1));
        if ($rel === "" || str_starts_with($rel, ".git/") || str_contains($rel, "/.git/")) {
            continue;
        }
        $manifest[$rel] = [
            "sha256" => hash_file("sha256", $path) ?: "",
            "bytes" => (int) filesize($path),
        ];
    }
    ksort($manifest);
}
$payload = [
    "slug" => $slug,
    "found" => $found,
    "active" => $active,
    "directory" => $root,
    "plugin_file" => $pluginFile,
    "bootstrap_file" => $bootstrapFile,
    "available_files" => $availableFiles,
    "name" => (string) ($pluginData["Name"] ?? ""),
    "version" => (string) ($pluginData["Version"] ?? ""),
    "description" => (string) ($pluginData["Description"] ?? ""),
    "author" => (string) ($pluginData["Author"] ?? ""),
    "manifest" => $manifest,
];
echo "HEXA_PLUGIN_MANIFEST:" . wp_json_encode($payload);
PHP;

        $php = str_replace(
            ['__SLUG__', '__BOOTSTRAP_CANDIDATES__'],
            [var_export($slug, true), var_export(array_values($bootstrapCandidates), true)],
            $php
        );

        $result = $this->wpToolkit->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Plugin inspection failed.'),
                'plugin' => null,
                'manifest' => [],
            ];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_PLUGIN_MANIFEST:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse plugin manifest output.', 'plugin' => null, 'manifest' => []];
        }

        $manifest = array_values(array_map(
            static fn (string $path, array $row): array => [
                'path' => $path,
                'sha256' => (string) ($row['sha256'] ?? ''),
                'bytes' => (int) ($row['bytes'] ?? 0),
            ],
            array_keys((array) ($payload['manifest'] ?? [])),
            (array) ($payload['manifest'] ?? [])
        ));

        unset($payload['manifest']);

        return [
            'success' => true,
            'message' => !empty($payload['found']) ? 'Installed plugin manifest loaded.' : 'Plugin is not installed.',
            'plugin' => $payload,
            'manifest' => $manifest,
        ];
    }

    public function githubManifest(string $repo, string $ref = 'main', ?string $mainFile = null): array
    {
        $repo = trim($repo, " \t\n\r\0\x0B/");
        $ref = trim($ref) ?: 'main';
        if ($repo === '' || !str_contains($repo, '/')) {
            return ['success' => false, 'message' => 'GitHub repo must be in owner/repo format.', 'manifest' => []];
        }

        $cacheKey = 'wp_plugin_github_manifest_' . sha1($repo . '@' . $ref . '|' . (string) $mainFile);
        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($repo, $ref, $mainFile) {
            return $this->buildGithubManifest($repo, $ref, $mainFile);
        });
    }

    public function comparePluginToGithub(
        WhmServer $server,
        int $installId,
        string $slug,
        string $repo,
        string $ref = 'main',
        ?string $mainFile = null
    ): array {
        $installed = $this->inspectInstalledPlugin($server, $installId, $slug, array_filter([$mainFile, $slug . '.php', 'initialization.php', 'plugin.php']));
        $remote = $this->githubManifest($repo, $ref, $mainFile);

        if (!($installed['success'] ?? false)) {
            return ['success' => false, 'message' => $installed['message'] ?? 'Installed plugin scan failed.', 'installed' => $installed, 'github' => $remote];
        }
        if (!($remote['success'] ?? false)) {
            return ['success' => false, 'message' => $remote['message'] ?? 'GitHub scan failed.', 'installed' => $installed, 'github' => $remote];
        }

        $localMap = $this->manifestMap((array) ($installed['manifest'] ?? []));
        $remoteMap = $this->manifestMap((array) ($remote['manifest'] ?? []));
        $missing = [];
        $extra = [];
        $changed = [];

        foreach ($remoteMap as $path => $hash) {
            if (!array_key_exists($path, $localMap)) {
                $missing[] = $path;
            } elseif ($localMap[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        foreach ($localMap as $path => $hash) {
            if (!array_key_exists($path, $remoteMap)) {
                $extra[] = $path;
            }
        }

        $localVersion = (string) ($installed['plugin']['version'] ?? '');
        $remoteVersion = (string) ($remote['version'] ?? '');
        $versionMatches = $localVersion !== '' && $remoteVersion !== '' && version_compare($localVersion, $remoteVersion, '=');
        $filesMatch = $missing === [] && $extra === [] && $changed === [];

        return [
            'success' => true,
            'message' => $filesMatch ? 'Installed plugin files match GitHub.' : 'Installed plugin differs from GitHub.',
            'matches' => $filesMatch,
            'version_matches' => $versionMatches,
            'local_version' => $localVersion,
            'remote_version' => $remoteVersion,
            'remote_head' => (string) ($remote['head'] ?? ''),
            'missing' => $missing,
            'extra' => $extra,
            'changed' => $changed,
            'installed' => $installed,
            'github' => $remote,
        ];
    }

    public function collectPluginUsageStats(
        WhmServer $server,
        int $installId,
        string $slug,
        string $optionName,
        array $usageMetaKeys = []
    ): array {
        $slug = $this->normalizeSlug($slug);
        $usageMetaKeys = array_values(array_filter(array_map('strval', $usageMetaKeys)));
        $php = <<<'PHP'
$slug = __SLUG__;
$optionName = __OPTION_NAME__;
$usageMetaKeys = __USAGE_META_KEYS__;
global $wpdb;
$publicTypes = get_post_types(["public" => true], "names");
unset($publicTypes["attachment"]);
$postTypes = array_values(array_filter(array_map("strval", (array) $publicTypes)));
if ($postTypes === []) {
    $postTypes = ["post", "page"];
}
$typeList = "'" . implode("','", array_map("esc_sql", $postTypes)) . "'";
$published = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$typeList})");
$contents = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$typeList})");
$wordCount = 0;
foreach ((array) $contents as $content) {
    $text = wp_strip_all_tags(strip_shortcodes((string) $content));
    $wordCount += str_word_count($text);
}
$usingPlugin = 0;
if ($usageMetaKeys !== []) {
    $metaList = "'" . implode("','", array_map("esc_sql", $usageMetaKeys)) . "'";
    $usingPlugin = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$metaList}) AND meta_value IS NOT NULL AND meta_value <> ''");
}
$settings = get_option($optionName, []);
$providerStatus = [];
$providers = is_array($settings["providers"] ?? null) ? $settings["providers"] : [];
foreach ($providers as $provider => $fields) {
    if (!is_array($fields)) {
        continue;
    }
    $configured = [];
    foreach ($fields as $field => $value) {
        $field = (string) $field;
        if (str_contains($field, "key") || str_contains($field, "token") || str_contains($field, "secret")) {
            $configured[$field] = trim((string) $value) !== "";
        }
    }
    $providerStatus[(string) $provider] = $configured;
}
$payload = [
    "success" => true,
    "post_types" => $postTypes,
    "published_posts" => $published,
    "posts_using_plugin" => $usingPlugin,
    "total_word_count" => $wordCount,
    "default_provider" => (string) ($settings["default_provider"] ?? ""),
    "default_profile" => (string) ($settings["default_profile"] ?? ""),
    "provider_credentials" => $providerStatus,
    "option_exists" => is_array($settings) && $settings !== [],
];
echo "HEXA_PLUGIN_USAGE:" . wp_json_encode($payload);
PHP;

        $php = str_replace(
            ['__SLUG__', '__OPTION_NAME__', '__USAGE_META_KEYS__'],
            [var_export($slug, true), var_export($optionName, true), var_export($usageMetaKeys, true)],
            $php
        );

        $result = $this->wpToolkit->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Plugin stats scan failed.')];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_PLUGIN_USAGE:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse plugin stats output.'];
        }

        return $payload;
    }

    public function updatePluginFromGithub(
        WhmServer $server,
        int $installId,
        string $slug,
        string $repo,
        string $ref = 'main',
        ?string $mainFile = null,
        bool $activate = true
    ): array {
        $slug = $this->normalizeSlug($slug);
        $repo = trim($repo, " \t\n\r\0\x0B/");
        $ref = trim($ref) ?: 'main';
        $mainFile = $mainFile ?: $slug . '.php';
        if ($slug === '' || $repo === '' || !str_contains($repo, '/')) {
            return ['success' => false, 'message' => 'Plugin slug and GitHub repo are required.'];
        }

        $zipUrl = 'https://github.com/' . $repo . '/archive/' . rawurlencode($ref) . '.zip';
        $php = <<<'PHP'
require_once ABSPATH . "wp-admin/includes/plugin.php";
$slug = __SLUG__;
$mainFile = __MAIN_FILE__;
$zipUrl = __ZIP_URL__;
$activate = __ACTIVATE__;
$target = trailingslashit(WP_PLUGIN_DIR) . $slug;
$pluginFile = $slug . "/" . $mainFile;
$wasActive = is_plugin_active($pluginFile);
$tmpBase = trailingslashit(WP_CONTENT_DIR) . "upgrade/hexa-plugin-update-" . $slug . "-" . time() . "-" . wp_generate_password(6, false, false);
$zipPath = $tmpBase . ".zip";
$extractDir = $tmpBase;
wp_mkdir_p(dirname($zipPath));
wp_mkdir_p($extractDir);
$response = wp_remote_get($zipUrl, ["timeout" => 60, "headers" => ["User-Agent" => "HexaWordPressPluginIntegrity/1.0"]]);
if (is_wp_error($response)) {
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => $response->get_error_message()]);
    return;
}
$code = (int) wp_remote_retrieve_response_code($response);
if ($code < 200 || $code >= 300) {
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "GitHub archive returned HTTP " . $code]);
    return;
}
if (false === file_put_contents($zipPath, wp_remote_retrieve_body($response))) {
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "Could not write temporary archive."]);
    return;
}
$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    @unlink($zipPath);
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "Could not open GitHub ZIP archive."]);
    return;
}
$zip->extractTo($extractDir);
$zip->close();
@unlink($zipPath);
$source = "";
foreach (glob(trailingslashit($extractDir) . "*", GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file(trailingslashit($dir) . $mainFile)) {
        $source = $dir;
        break;
    }
}
if ($source === "") {
    foreach (glob(trailingslashit($extractDir) . "*", GLOB_ONLYDIR) ?: [] as $dir) {
        $source = $dir;
        break;
    }
}
if ($source === "" || !is_dir($source)) {
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "GitHub archive did not contain an extractable plugin folder."]);
    return;
}
$backup = $target . ".hexa-backup-" . gmdate("YmdHis");
if (is_dir($target) && !@rename($target, $backup)) {
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "Could not move existing plugin folder to backup."]);
    return;
}
if (!@rename($source, $target)) {
    if (is_dir($backup)) {
        @rename($backup, $target);
    }
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "Could not move GitHub plugin into wp-content/plugins."]);
    return;
}
$cleanup = function ($dir) use (&$cleanup) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? $cleanup($path) : @unlink($path);
    }
    @rmdir($dir);
};
$cleanup($extractDir);
if ($activate || $wasActive) {
    if (!is_plugin_active($pluginFile)) {
        $activation = activate_plugin($pluginFile);
        if (is_wp_error($activation)) {
            echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => false, "message" => "Plugin updated but activation failed: " . $activation->get_error_message(), "backup" => $backup]);
            return;
        }
    }
}
echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode(["success" => true, "message" => "Plugin updated from GitHub.", "backup" => is_dir($backup) ? $backup : "", "plugin_file" => $pluginFile]);
PHP;

        $php = str_replace(
            ['__SLUG__', '__MAIN_FILE__', '__ZIP_URL__', '__ACTIVATE__'],
            [var_export($slug, true), var_export($mainFile, true), var_export($zipUrl, true), $activate ? 'true' : 'false'],
            $php
        );

        $result = $this->wpToolkit->wpCliEval($server, $installId, $php);
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Plugin update failed.')];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_PLUGIN_UPDATE:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse plugin update output.', 'stdout' => (string) ($result['stdout'] ?? '')];
        }

        return $payload;
    }

    private function buildGithubManifest(string $repo, string $ref, ?string $mainFile): array
    {
        $head = '';
        try {
            $commit = Http::withHeaders(['User-Agent' => 'HexaWordPressPluginIntegrity/1.0'])
                ->timeout(20)
                ->get('https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref));
            if ($commit->successful()) {
                $head = (string) data_get($commit->json(), 'sha', '');
            }
        } catch (\Throwable) {
            $head = '';
        }

        $zipUrl = 'https://github.com/' . $repo . '/archive/' . rawurlencode($ref) . '.zip';
        $response = Http::withHeaders(['User-Agent' => 'HexaWordPressPluginIntegrity/1.0'])->timeout(60)->get($zipUrl);
        if (!$response->successful()) {
            return ['success' => false, 'message' => 'GitHub archive returned HTTP ' . $response->status(), 'manifest' => [], 'head' => $head];
        }

        $base = storage_path('app/wp-plugin-integrity/' . Str::uuid()->toString());
        $zipPath = $base . '.zip';
        $extractDir = $base;
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }
        file_put_contents($zipPath, $response->body());
        mkdir($extractDir, 0755, true);

        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath)) {
            @unlink($zipPath);
            $this->removeDirectory($extractDir);
            return ['success' => false, 'message' => 'Could not open GitHub ZIP archive.', 'manifest' => [], 'head' => $head];
        }
        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($zipPath);

        $root = null;
        foreach (glob($extractDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($mainFile && is_file($dir . '/' . $mainFile)) {
                $root = $dir;
                break;
            }
            $root ??= $dir;
        }

        if (!$root || !is_dir($root)) {
            $this->removeDirectory($extractDir);
            return ['success' => false, 'message' => 'GitHub archive did not contain files.', 'manifest' => [], 'head' => $head];
        }

        $manifest = [];
        $version = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($rel === '' || str_starts_with($rel, '.git/') || str_contains($rel, '/.git/')) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($mainFile && $rel === $mainFile && is_string($contents)) {
                $version = $this->parsePluginHeader($contents, 'Version');
            }
            $manifest[] = [
                'path' => $rel,
                'sha256' => hash_file('sha256', $path) ?: '',
                'bytes' => (int) filesize($path),
            ];
        }

        usort($manifest, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        $this->removeDirectory($extractDir);

        return [
            'success' => true,
            'message' => 'GitHub archive manifest loaded.',
            'repo' => $repo,
            'ref' => $ref,
            'head' => $head,
            'version' => $version,
            'manifest' => $manifest,
        ];
    }

    private function manifestMap(array $manifest): array
    {
        $map = [];
        foreach ($manifest as $row) {
            if (!is_array($row) || empty($row['path'])) {
                continue;
            }
            $map[(string) $row['path']] = (string) ($row['sha256'] ?? '');
        }
        ksort($map);
        return $map;
    }

    private function parsePluginHeader(string $contents, string $header): string
    {
        foreach (preg_split("/\r\n|\r|\n/", $contents) ?: [] as $line) {
            $line = ltrim($line, " \t/*#@");
            if (stripos($line, $header . ':') === 0) {
                return trim(substr($line, strlen($header) + 1));
            }
        }
        return '';
    }

    private function normalizeSlug(string $slug): string
    {
        return trim($slug, " \t\n\r\0\x0B/");
    }

    private function decodeMarkedPayload(string $stdout, string $marker): ?array
    {
        foreach (preg_split("/\r?\n/", $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, $marker)) {
                continue;
            }
            $json = substr($line, strpos($line, $marker) + strlen($marker));
            $decoded = json_decode(trim($json), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
