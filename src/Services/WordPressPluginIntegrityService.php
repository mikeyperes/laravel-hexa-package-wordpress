<?php

namespace hexa_package_wordpress\Services;

use hexa_core\Security\Archives\ArchiveSecurityPolicy;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use ZipArchive;

class WordPressPluginIntegrityService
{
    private const MAX_ARCHIVE_BYTES = 16777216;

    private const MAX_ARCHIVE_ENTRIES = 10000;

    private const MAX_UNCOMPRESSED_BYTES = 268435456;

    private const MAX_PLUGIN_FILE_BYTES = 67108864;

    private const MAX_PLUGIN_HEADER_BYTES = 8192;

    public function __construct(
        protected WpToolkitService $wpToolkit,
        protected SafeOutboundHttpClient $http,
        protected ArchiveSecurityPolicy $archiveSecurity,
    ) {
    }

    public function inspectInstalledPlugin(WhmServer $server, int $installId, string $slug, array $bootstrapCandidates = []): array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return ['success' => false, 'message' => 'Plugin slug is invalid.', 'plugin' => null, 'manifest' => []];
        }

        $bootstrapCandidates = $bootstrapCandidates ?: [$slug . '.php', 'initialization.php', 'plugin.php'];
        $bootstrapCandidates = $this->normalizePluginFiles($bootstrapCandidates);
        if ($bootstrapCandidates === null) {
            return ['success' => false, 'message' => 'Plugin bootstrap file is invalid.', 'plugin' => null, 'manifest' => []];
        }
        $php = <<<'PHP'
require_once ABSPATH . "wp-admin/includes/plugin.php";
$slug = __SLUG__;
$bootstrapCandidates = __BOOTSTRAP_CANDIDATES__;
$maxManifestEntries = __MAX_MANIFEST_ENTRIES__;
$maxManifestBytes = __MAX_MANIFEST_BYTES__;
$maxPluginFileBytes = __MAX_PLUGIN_FILE_BYTES__;
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
$manifestError = "";
if ($found) {
    $pluginRoot = realpath(WP_PLUGIN_DIR);
    $rootReal = realpath($root);
    if (is_link($root) || $pluginRoot === false || $rootReal === false || !str_starts_with($rootReal, trailingslashit($pluginRoot))) {
        $manifestError = "Plugin directory is outside the canonical plugin root or is a symbolic link.";
    } else {
        $entryCount = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                $manifestError = "Plugin manifest contains a symbolic link.";
                break;
            }
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, trailingslashit($rootReal))) {
                $manifestError = "Plugin manifest contains a file outside the plugin directory.";
                break;
            }
            $rel = str_replace("\\", "/", substr($path, strlen($rootReal) + 1));
            if ($rel === "" || str_starts_with($rel, ".git/") || str_contains($rel, "/.git/")) {
                continue;
            }
            $bytes = (int) $file->getSize();
            $entryCount++;
            $totalBytes += $bytes;
            if ($entryCount > $maxManifestEntries || $totalBytes > $maxManifestBytes || $bytes > $maxPluginFileBytes) {
                $manifestError = "Plugin manifest exceeded its file or byte limit.";
                break;
            }
            $handle = @fopen($path, "rb");
            if (!is_resource($handle)) {
                $manifestError = "Plugin manifest file could not be hashed.";
                break;
            }
            $hash = hash_init("sha256");
            $hashedBytes = 0;
            while (!feof($handle)) {
                $remaining = ($maxPluginFileBytes + 1) - $hashedBytes;
                if ($remaining < 1) {
                    break;
                }
                $chunk = fread($handle, min(1048576, $remaining));
                if (!is_string($chunk)) {
                    $manifestError = "Plugin manifest file could not be hashed.";
                    break;
                }
                if ($chunk === "") {
                    break;
                }
                $hashedBytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
            fclose($handle);
            clearstatcache(true, $path);
            $pathAfter = realpath($path);
            if ($manifestError !== "") {
                break;
            }
            if ($hashedBytes !== $bytes || $hashedBytes > $maxPluginFileBytes || $pathAfter !== $path || is_link($path)) {
                $manifestError = "Plugin manifest file changed or exceeded its limit while being hashed.";
                break;
            }
            $manifest[$rel] = [
                "sha256" => hash_final($hash),
                "bytes" => $hashedBytes,
            ];
        }
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
    "manifest_error" => $manifestError,
    "manifest" => $manifest,
];
echo "HEXA_PLUGIN_MANIFEST:" . wp_json_encode($payload);
PHP;

        $php = str_replace(
            ['__SLUG__', '__BOOTSTRAP_CANDIDATES__', '__MAX_MANIFEST_ENTRIES__', '__MAX_MANIFEST_BYTES__', '__MAX_PLUGIN_FILE_BYTES__'],
            [
                var_export($slug, true),
                var_export(array_values($bootstrapCandidates), true),
                (string) self::MAX_ARCHIVE_ENTRIES,
                (string) self::MAX_UNCOMPRESSED_BYTES,
                (string) self::MAX_PLUGIN_FILE_BYTES,
            ],
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
        if ((string) ($payload['manifest_error'] ?? '') !== '') {
            return [
                'success' => false,
                'message' => (string) $payload['manifest_error'],
                'plugin' => null,
                'manifest' => [],
            ];
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

        unset($payload['manifest'], $payload['manifest_error']);

        return [
            'success' => true,
            'message' => !empty($payload['found']) ? 'Installed plugin manifest loaded.' : 'Plugin is not installed.',
            'plugin' => $payload,
            'manifest' => $manifest,
        ];
    }

    public function githubManifest(string $repo, string $ref = 'main', ?string $mainFile = null): array
    {
        $repo = $this->normalizeRepo($repo);
        $ref = $this->normalizeRef($ref);
        $mainFile = $this->normalizePluginFile($mainFile);
        if ($repo === '') {
            return ['success' => false, 'message' => 'GitHub repo must be a valid owner/repo identifier.', 'manifest' => []];
        }
        if ($ref === '') {
            return ['success' => false, 'message' => 'GitHub ref is invalid.', 'manifest' => []];
        }
        if ($mainFile === false) {
            return ['success' => false, 'message' => 'Plugin main file is invalid.', 'manifest' => []];
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
        $slug = $this->normalizeSlug($slug);
        $repo = $this->normalizeRepo($repo);
        $ref = $this->normalizeRef($ref);
        $mainFile = $this->normalizePluginFile($mainFile);
        if ($slug === '' || $repo === '' || $ref === '' || $mainFile === false) {
            return [
                'success' => false,
                'message' => 'Plugin comparison coordinates are invalid.',
                'installed' => ['success' => false],
                'github' => ['success' => false],
            ];
        }

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
        if ($slug === '') {
            return ['success' => false, 'message' => 'Plugin slug is invalid.'];
        }
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
        $repo = $this->normalizeRepo($repo);
        $ref = $this->normalizeRef($ref);
        $mainFile = $this->normalizePluginFile($mainFile ?? ($slug !== '' ? $slug . '.php' : null));
        if ($slug === '' || $repo === '' || $ref === '' || !is_string($mainFile)) {
            return ['success' => false, 'message' => 'Plugin update coordinates are invalid.'];
        }

        $manifest = $this->githubManifest($repo, $ref, $mainFile);
        if (!($manifest['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($manifest['message'] ?? 'GitHub archive validation failed.')];
        }
        $head = (string) ($manifest['head'] ?? '');
        $archiveSha256 = (string) ($manifest['archive_sha256'] ?? '');
        if (preg_match('/\A[a-f0-9]{40}\z/D', $head) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $archiveSha256) !== 1) {
            return ['success' => false, 'message' => 'GitHub archive validation did not return immutable evidence.'];
        }

        $zipUrl = 'https://github.com/' . $repo . '/archive/' . $head . '.zip';
        $php = <<<'PHP'
require_once ABSPATH . "wp-admin/includes/plugin.php";
require_once ABSPATH . "wp-admin/includes/file.php";
$slug = __SLUG__;
$mainFile = __MAIN_FILE__;
$zipUrl = __ZIP_URL__;
$archiveSha256 = __ARCHIVE_SHA256__;
$maxArchiveBytes = __MAX_ARCHIVE_BYTES__;
$activate = __ACTIVATE__;
$target = trailingslashit(WP_PLUGIN_DIR) . $slug;
$pluginFile = $slug . "/" . $mainFile;
$wasActive = is_plugin_active($pluginFile);
$tmpBase = trailingslashit(WP_CONTENT_DIR) . "upgrade/hexa-plugin-update-" . $slug . "-" . time() . "-" . wp_generate_password(6, false, false);
$zipPath = $tmpBase . ".zip";
$extractDir = $tmpBase;
wp_mkdir_p(dirname($zipPath));
wp_mkdir_p($extractDir);
$filesystemReady = function_exists("WP_Filesystem") && WP_Filesystem();
global $wp_filesystem;
$cleanup = function () use ($zipPath, $extractDir, &$wp_filesystem) {
    if (is_object($wp_filesystem) && file_exists($zipPath)) {
        $wp_filesystem->delete($zipPath, false);
    }
    if (is_object($wp_filesystem) && is_dir($extractDir)) {
        $wp_filesystem->delete($extractDir, true);
    }
};
$respond = function (array $payload) use ($cleanup) {
    $cleanup();
    echo "HEXA_PLUGIN_UPDATE:" . wp_json_encode($payload);
};
if (!$filesystemReady || !is_object($wp_filesystem)) {
    $respond(["success" => false, "message" => "WordPress filesystem access is unavailable for the plugin update."]);
    return;
}
$response = wp_safe_remote_get($zipUrl, [
    "timeout" => 60,
    "redirection" => 3,
    "reject_unsafe_urls" => true,
    "stream" => true,
    "filename" => $zipPath,
    "limit_response_size" => $maxArchiveBytes + 1,
    "headers" => ["User-Agent" => "HexaWordPressPluginIntegrity/1.0"],
]);
if (is_wp_error($response)) {
    $respond(["success" => false, "message" => "The verified GitHub archive could not be downloaded."]);
    return;
}
$code = (int) wp_remote_retrieve_response_code($response);
if ($code < 200 || $code >= 300) {
    $respond(["success" => false, "message" => "GitHub archive returned HTTP " . $code]);
    return;
}
$archiveBytes = is_file($zipPath) ? (int) filesize($zipPath) : 0;
if ($archiveBytes < 1 || $archiveBytes > $maxArchiveBytes) {
    $respond(["success" => false, "message" => "GitHub archive exceeded the download size limit."]);
    return;
}
$downloadSha256 = hash_file("sha256", $zipPath);
if (!is_string($downloadSha256) || !hash_equals($archiveSha256, $downloadSha256)) {
    $respond(["success" => false, "message" => "GitHub archive did not match the locally verified immutable archive."]);
    return;
}
$unpacked = unzip_file($zipPath, $extractDir);
$wp_filesystem->delete($zipPath, false);
if (is_wp_error($unpacked)) {
    $respond(["success" => false, "message" => "WordPress rejected the verified plugin archive."]);
    return;
}
$roots = array_values(array_filter(glob(trailingslashit($extractDir) . "*", GLOB_ONLYDIR) ?: [], "is_dir"));
if (count($roots) !== 1) {
    $respond(["success" => false, "message" => "GitHub archive must contain one plugin root directory."]);
    return;
}
$source = $roots[0];
$sourceReal = realpath($source);
$extractReal = realpath($extractDir);
$sourceMain = $sourceReal !== false ? realpath(trailingslashit($sourceReal) . $mainFile) : false;
if (is_link($source) || $sourceReal === false || $extractReal === false || $sourceMain === false || !is_file($sourceMain) || is_link($sourceMain)
    || !str_starts_with($sourceReal, trailingslashit($extractReal))
    || !str_starts_with($sourceMain, trailingslashit($sourceReal))) {
    $respond(["success" => false, "message" => "Verified archive did not contain the exact plugin main file."]);
    return;
}
$backup = $target . ".hexa-backup-" . gmdate("YmdHis") . "-" . wp_generate_password(6, false, false);
$hadTarget = is_dir($target);
if (is_link($target)) {
    $respond(["success" => false, "message" => "The installed plugin directory is a symbolic link and cannot be replaced safely."]);
    return;
}
if ((file_exists($backup) || is_link($backup)) || ($hadTarget && !@rename($target, $backup))) {
    $respond(["success" => false, "message" => "Could not create the isolated plugin rollback directory."]);
    return;
}
if (!@rename($source, $target)) {
    $rollbackComplete = !$hadTarget || (is_dir($backup) && @rename($backup, $target));
    $respond(["success" => false, "message" => "Could not install the verified plugin archive.", "rollback_complete" => $rollbackComplete]);
    return;
}
$rollback = function () use ($target, $backup, $hadTarget, &$wp_filesystem) {
    $removedNewTarget = !is_dir($target) || $wp_filesystem->delete($target, true);
    if (!$removedNewTarget) {
        return false;
    }
    return !$hadTarget || (is_dir($backup) && @rename($backup, $target));
};
$validation = validate_plugin($pluginFile);
if (is_wp_error($validation)) {
    $respond(["success" => false, "message" => "The verified archive is not a valid WordPress plugin.", "rollback_complete" => $rollback()]);
    return;
}
if ($activate || $wasActive) {
    if (!is_plugin_active($pluginFile)) {
        $activation = activate_plugin($pluginFile);
        if (is_wp_error($activation)) {
            $respond(["success" => false, "message" => "Plugin activation failed after update.", "rollback_complete" => $rollback()]);
            return;
        }
    }
}
$respond(["success" => true, "message" => "Plugin updated from the verified GitHub archive.", "backup" => is_dir($backup) ? $backup : "", "plugin_file" => $pluginFile, "archive_sha256" => $archiveSha256]);
PHP;

        $php = str_replace(
            ['__SLUG__', '__MAIN_FILE__', '__ZIP_URL__', '__ARCHIVE_SHA256__', '__MAX_ARCHIVE_BYTES__', '__ACTIVATE__'],
            [
                var_export($slug, true),
                var_export($mainFile, true),
                var_export($zipUrl, true),
                var_export($archiveSha256, true),
                (string) self::MAX_ARCHIVE_BYTES,
                $activate ? 'true' : 'false',
            ],
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
        try {
            $commit = $this->http->request(
                'GET',
                'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref),
                [
                    'headers' => [
                        'Accept' => 'application/vnd.github+json',
                        'User-Agent' => 'HexaWordPressPluginIntegrity/1.0',
                    ],
                    'timeout' => 20,
                    'max_bytes' => 1048576,
                    'max_redirects' => 2,
                ],
            );
        } catch (OutboundHttpException $exception) {
            return ['success' => false, 'message' => 'GitHub commit lookup failed: '.$exception->failureCode().'.', 'manifest' => []];
        }
        $head = (string) data_get($commit->json(), 'sha', '');
        if (!$commit->successful() || preg_match('/\A[a-f0-9]{40}\z/D', $head) !== 1) {
            return ['success' => false, 'message' => 'GitHub did not return an immutable commit.', 'manifest' => []];
        }

        $zipUrl = 'https://github.com/' . $repo . '/archive/' . $head . '.zip';
        try {
            $response = $this->http->request('GET', $zipUrl, [
                'headers' => [
                    'Accept' => 'application/zip',
                    'User-Agent' => 'HexaWordPressPluginIntegrity/1.0',
                ],
                'timeout' => 60,
                'max_bytes' => self::MAX_ARCHIVE_BYTES,
                'max_redirects' => 3,
            ]);
        } catch (OutboundHttpException $exception) {
            return [
                'success' => false,
                'message' => 'GitHub archive download failed: '.$exception->failureCode().'.',
                'manifest' => [],
                'head' => $head,
            ];
        }
        if (!$response->successful()) {
            return ['success' => false, 'message' => 'GitHub archive returned HTTP ' . $response->status(), 'manifest' => [], 'head' => $head];
        }
        $archiveBytes = strlen($response->body);
        if ($archiveBytes < 1 || $archiveBytes > self::MAX_ARCHIVE_BYTES) {
            return ['success' => false, 'message' => 'GitHub archive exceeded its compressed size limit.', 'manifest' => [], 'head' => $head];
        }

        $directory = storage_path('app/wp-plugin-integrity');
        if (is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            return ['success' => false, 'message' => 'Private archive workspace is unavailable.', 'manifest' => [], 'head' => $head];
        }
        $directoryModeSet = @chmod($directory, 0700);
        clearstatcache(true, $directory);
        if (!$directoryModeSet || ((int) @fileperms($directory) & 0777) !== 0700) {
            return ['success' => false, 'message' => 'Private archive workspace permissions could not be secured.', 'manifest' => [], 'head' => $head];
        }
        $zipPath = tempnam($directory, 'github-plugin-');
        if (!is_string($zipPath)) {
            return ['success' => false, 'message' => 'Private archive file could not be created.', 'manifest' => [], 'head' => $head];
        }
        $fileModeSet = @chmod($zipPath, 0600);
        clearstatcache(true, $zipPath);
        if (is_link($zipPath) || !$fileModeSet || ((int) @fileperms($zipPath) & 0777) !== 0600) {
            @unlink($zipPath);

            return ['success' => false, 'message' => 'Private archive file permissions could not be secured.', 'manifest' => [], 'head' => $head];
        }

        $zip = new ZipArchive();
        $zipOpened = false;
        try {
            if (file_put_contents($zipPath, $response->body, LOCK_EX) !== $archiveBytes) {
                return ['success' => false, 'message' => 'GitHub archive could not be staged completely.', 'manifest' => [], 'head' => $head];
            }
            if (true !== $zip->open($zipPath)) {
                return ['success' => false, 'message' => 'Could not open GitHub ZIP archive.', 'manifest' => [], 'head' => $head];
            }
            $zipOpened = true;
            $inspection = $this->archiveSecurity->inspect($zip);
            if (!($inspection['safe'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'GitHub archive failed the shared archive security policy.',
                    'manifest' => [],
                    'head' => $head,
                    'archive_errors' => array_values((array) ($inspection['errors'] ?? [])),
                ];
            }
            if ((int) ($inspection['entry_count'] ?? 0) > self::MAX_ARCHIVE_ENTRIES
                || (int) ($inspection['uncompressed_bytes'] ?? 0) > self::MAX_UNCOMPRESSED_BYTES) {
                return ['success' => false, 'message' => 'GitHub archive exceeded plugin manifest limits.', 'manifest' => [], 'head' => $head];
            }

            $archiveManifest = $this->manifestFromArchive($zip, $mainFile);
            if (!($archiveManifest['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($archiveManifest['message'] ?? 'GitHub archive manifest could not be read.'),
                    'manifest' => [],
                    'head' => $head,
                ];
            }

            return [
                'success' => true,
                'message' => 'GitHub archive manifest loaded.',
                'repo' => $repo,
                'ref' => $ref,
                'head' => $head,
                'archive_sha256' => hash('sha256', $response->body),
                'archive_bytes' => $archiveBytes,
                'version' => (string) ($archiveManifest['version'] ?? ''),
                'manifest' => (array) ($archiveManifest['manifest'] ?? []),
            ];
        } finally {
            if ($zipOpened) {
                $zip->close();
            }
            @unlink($zipPath);
        }
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

    /**
     * @return array{success:bool,message:string,version?:string,manifest:list<array{path:string,sha256:string,bytes:int}>}
     */
    private function manifestFromArchive(ZipArchive $zip, ?string $mainFile): array
    {
        $root = null;
        $manifest = [];
        $seenPaths = [];
        $mainHeader = null;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) {
                return ['success' => false, 'message' => 'GitHub archive entry could not be inspected.', 'manifest' => []];
            }

            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $isDirectory = str_ends_with($name, '/');
            $trimmed = rtrim($name, '/');
            $segments = explode('/', $trimmed);
            $entryRoot = (string) ($segments[0] ?? '');
            if ($entryRoot === '' || strlen($name) > 2048 || in_array('', $segments, true) || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                return ['success' => false, 'message' => 'GitHub archive contains an invalid root directory.', 'manifest' => []];
            }
            if ($root === null) {
                $root = $entryRoot;
            } elseif ($root !== $entryRoot) {
                return ['success' => false, 'message' => 'GitHub archive must contain one root directory.', 'manifest' => []];
            }

            if ($isDirectory) {
                continue;
            }
            if (count($segments) < 2) {
                return ['success' => false, 'message' => 'GitHub archive contains a file outside its root directory.', 'manifest' => []];
            }

            $relativePath = implode('/', array_slice($segments, 1));
            if ($relativePath === '' || strlen($relativePath) > 1024 || isset($seenPaths[$relativePath])) {
                return ['success' => false, 'message' => 'GitHub archive contains an invalid or duplicate plugin path.', 'manifest' => []];
            }
            if (str_starts_with($relativePath, '.git/') || str_contains($relativePath, '/.git/')) {
                continue;
            }
            $seenPaths[$relativePath] = true;

            $bytes = (int) ($stat['size'] ?? -1);
            if ($bytes < 0 || $bytes > self::MAX_PLUGIN_FILE_BYTES) {
                return ['success' => false, 'message' => 'GitHub archive contains a plugin file that exceeds its size limit.', 'manifest' => []];
            }

            $stream = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_resource($stream)) {
                return ['success' => false, 'message' => 'GitHub archive file could not be opened for hashing.', 'manifest' => []];
            }

            $hash = hash_init('sha256');
            $headerContents = '';
            try {
                if ($mainFile !== null && $relativePath === $mainFile) {
                    while (strlen($headerContents) < self::MAX_PLUGIN_HEADER_BYTES && !feof($stream)) {
                        $chunk = fread($stream, self::MAX_PLUGIN_HEADER_BYTES - strlen($headerContents));
                        if (!is_string($chunk)) {
                            return ['success' => false, 'message' => 'Plugin main file header could not be read.', 'manifest' => []];
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $headerContents .= $chunk;
                    }
                    hash_update($hash, $headerContents);
                }

                $remainingBytes = hash_update_stream($hash, $stream);
                if (!is_int($remainingBytes)) {
                    return ['success' => false, 'message' => 'GitHub archive file could not be hashed.', 'manifest' => []];
                }
                $streamedBytes = strlen($headerContents) + $remainingBytes;
            } finally {
                fclose($stream);
            }

            if ($streamedBytes !== $bytes) {
                return ['success' => false, 'message' => 'GitHub archive file size changed while it was inspected.', 'manifest' => []];
            }

            $manifest[] = [
                'path' => $relativePath,
                'sha256' => hash_final($hash),
                'bytes' => $bytes,
            ];
            if ($mainFile !== null && $relativePath === $mainFile) {
                $mainHeader = $headerContents;
            }
        }

        if ($root === null || $manifest === []) {
            return ['success' => false, 'message' => 'GitHub archive did not contain plugin files.', 'manifest' => []];
        }
        if ($mainFile !== null && $mainHeader === null) {
            return ['success' => false, 'message' => 'GitHub archive did not contain the requested plugin main file.', 'manifest' => []];
        }

        $version = $mainFile !== null
            ? $this->parsePluginHeader((string) $mainHeader, 'Version')
            : '';
        usort($manifest, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return [
            'success' => true,
            'message' => 'GitHub archive manifest loaded.',
            'version' => $version,
            'manifest' => $manifest,
        ];
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
        return strlen($slug) <= 191 && preg_match('/\A[a-z0-9][a-z0-9_-]{0,190}\z/D', $slug) === 1
            ? $slug
            : '';
    }

    private function normalizeRepo(string $repo): string
    {
        if (strlen($repo) > 140 || substr_count($repo, '/') !== 1) {
            return '';
        }

        [$owner, $name] = explode('/', $repo, 2);
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/D', $owner) !== 1
            || str_contains($owner, '--')
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $name) !== 1
            || $name === '.'
            || $name === '..') {
            return '';
        }

        return $repo;
    }

    private function normalizeRef(string $ref): string
    {
        if (strlen($ref) < 1 || strlen($ref) > 255
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,254}\z/D', $ref) !== 1
            || str_contains($ref, '..')
            || str_contains($ref, '@{')
            || str_contains($ref, '//')
            || str_ends_with($ref, '/')
            || str_ends_with($ref, '.')
            || str_ends_with(strtolower($ref), '.lock')) {
            return '';
        }

        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with(strtolower($segment), '.lock')) {
                return '';
            }
        }

        return $ref;
    }

    private function normalizePluginFile(?string $file): string|false|null
    {
        if ($file === null) {
            return null;
        }
        if (strlen($file) < 5 || strlen($file) > 512
            || str_contains($file, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $file) === 1
            || str_starts_with($file, '/')
            || preg_match('/\.php\z/iD', $file) !== 1) {
            return false;
        }

        foreach (explode('/', $file) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $segment) !== 1) {
                return false;
            }
        }

        return $file;
    }

    /** @return list<string>|null */
    private function normalizePluginFiles(array $files): ?array
    {
        if (count($files) > 32) {
            return null;
        }

        $normalized = [];
        foreach ($files as $file) {
            if (!is_string($file)) {
                return null;
            }
            $candidate = $this->normalizePluginFile($file);
            if (!is_string($candidate)) {
                return null;
            }
            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
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

}
