<?php

namespace hexa_package_wordpress\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PublicationContextService
{
    public function __construct(protected WordPressManagerService $wordpress)
    {
    }

    public function inspect(array $target, bool $forceRefresh = false): array
    {
        $target = $this->wordpress->normalizeTarget($target);
        $cacheKey = $this->cacheKey($target);

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->withCacheMeta($cached, true);
            }
        }

        $result = $this->loadFresh($target);
        $result['synced_at'] = now()->toIso8601String();

        if ((bool) ($result['success'] ?? false)) {
            Cache::put($cacheKey, $result, now()->addDays(30));
        }

        return $this->withCacheMeta($result, false);
    }

    public function cacheKey(array $target): string
    {
        $server = $target['server'] ?? null;
        $serverKey = is_object($server) ? (string) ($server->id ?: $server->hostname ?: 'server') : 'rest';
        $installKey = (string) ($target['install_id'] ?? '0');

        return 'wordpress:publication-context:server:' . $serverKey . ':install:' . $installKey;
    }

    protected function loadFresh(array $target): array
    {
        $plugins = [
            'hws_base_tools' => $this->pluginPayload('HWS Base Tools', 'hws-base-tools', $this->wordpress->inspectPlugin($target, 'hws-base-tools', ['initialization.php', 'hws-base-tools.php', 'plugin.php'])),
            'scale_my_publication' => $this->firstPluginPayload([
                ['Scale My Publication', 'scale-my-publication', ['scale-my-publication.php', 'initialization.php', 'plugin.php']],
                ['SMP Verified Profiles', 'smp-verified-profiles', ['smp-verified-profiles.php', 'initialization.php', 'plugin.php']],
            ], $target),
        ];

        $php = <<<'PHP'
$readField = function($key) {
    if (function_exists("get_field")) {
        $v = get_field($key, "option");
        if ($v !== null && $v !== false) {
            return $v;
        }
    }
    $v = get_option("options_" . $key);
    return $v !== false ? $v : null;
};
$normalize = function($v) use (&$normalize) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $item) {
            $out[$k] = $normalize($item);
        }
        return $out;
    }
    if (is_object($v)) {
        return $normalize((array) $v);
    }
    if (is_bool($v) || $v === null) {
        return $v;
    }
    return (string) $v;
};
$to1Line = function($v) {
    if (is_array($v) || is_object($v)) {
        $v = wp_json_encode($v);
    }
    $text = wp_strip_all_tags((string) $v);
    $text = preg_replace("/\\s+/", " ", $text);
    return trim((string) $text);
};
$mission = $readField("mission_statement");
$biography = $readField("biography");
$biographyShort = $readField("biography_short");
$website = $readField("website");
if (is_array($website)) {
    $mission = $mission ?? ($website["mission_statement"] ?? null);
    $biography = $biography ?? ($website["biography"] ?? null);
    $biographyShort = $biographyShort ?? ($website["biography_short"] ?? null);
}
$payload = [
    "site" => [
        "name" => get_bloginfo("name"),
        "description" => get_bloginfo("description"),
        "home_url" => home_url("/"),
        "site_url" => site_url("/"),
        "admin_email" => get_option("admin_email"),
        "wp_version" => get_bloginfo("version"),
    ],
    "context" => [
        "mission_statement" => $normalize($mission),
        "mission_statement_text" => $to1Line($mission),
        "about_full" => $normalize($biography),
        "about_full_text" => $to1Line($biography),
        "about_short" => $normalize($biographyShort),
        "about_short_text" => $to1Line($biographyShort),
        "website_option" => $normalize($website),
    ],
    "fetched_at" => gmdate("c"),
];
echo "HEXA_PUBLICATION_CONTEXT:" . wp_json_encode($payload);
PHP;

        $runtime = $this->wordpress->evaluatePhp($target, $php);
        if (!($runtime['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($runtime['message'] ?? 'Publication context lookup failed.'),
                'plugins' => $plugins,
                'target' => $this->safeTarget($target),
            ];
        }

        $context = $this->decodeMarkedPayload((string) ($runtime['stdout'] ?? ''), 'HEXA_PUBLICATION_CONTEXT:');
        if (!is_array($context)) {
            return [
                'success' => false,
                'message' => 'Failed to parse publication context output.',
                'plugins' => $plugins,
                'target' => $this->safeTarget($target),
            ];
        }

        return [
            'success' => true,
            'message' => 'Publication context loaded.',
            'plugins' => $plugins,
            'target' => $this->safeTarget($target),
            'publication' => $context,
        ];
    }

    protected function firstPluginPayload(array $candidates, array $target): array
    {
        $first = null;
        foreach ($candidates as $candidate) {
            [$label, $slug, $bootstrap] = $candidate;
            $payload = $this->pluginPayload($label, $slug, $this->wordpress->inspectPlugin($target, $slug, $bootstrap));
            $first ??= $payload;
            if (($payload['found'] ?? false)) {
                return $payload;
            }
        }

        return $first ?? ['label' => 'Scale My Publication', 'slug' => 'scale-my-publication', 'found' => false, 'active' => false, 'message' => 'Not inspected.'];
    }

    protected function pluginPayload(string $label, string $slug, array $inspection): array
    {
        $plugin = (is_array($inspection['plugin'] ?? null)) ? $inspection['plugin'] : [];

        return [
            'label' => $label,
            'slug' => $slug,
            'success' => (bool) ($inspection['success'] ?? false),
            'found' => (bool) ($plugin['found'] ?? false),
            'active' => (bool) ($plugin['active'] ?? false),
            'version' => (string) ($plugin['version'] ?? ''),
            'plugin_file' => (string) ($plugin['plugin_file'] ?? ''),
            'message' => (string) ($inspection['message'] ?? ''),
        ];
    }

    protected function decodeMarkedPayload(string $stdout, string $marker): array|null
    {
        foreach (preg_split("/\\r?\\n/", $stdout) ?: [] as $line) {
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

    protected function withCacheMeta(array $payload, bool $cached): array
    {
        $syncedAt = (string) ($payload['synced_at'] ?? '');
        $payload['cached'] = $cached;
        $payload['cache_age_seconds'] = $syncedAt !== '' ? max(0, Carbon::parse($syncedAt)->diffInSeconds(now())) : null;
        $payload['cache_age_label'] = $this->humanAge($syncedAt);

        return $payload;
    }

    protected function humanAge(string $syncedAt): string
    {
        if ($syncedAt === '') {
            return 'never';
        }

        return Carbon::parse($syncedAt)->diffForHumans();
    }

    protected function safeTarget(array $target): array
    {
        $server = $target['server'] ?? null;

        return [
            'mode' => (string) ($target['mode'] ?? ''),
            'site_name' => (string) ($target['site_name'] ?? ''),
            'url' => (string) ($target['url'] ?? ''),
            'install_id' => $target['install_id'] ?? null,
            'server_id' => is_object($server) ? ($server->id ?? null) : null,
            'server' => is_object($server) ? ((string) ($server->hostname ?? $server->ip_address ?? '')) : '',
            'cpanel_user' => (string) ($target['cpanel_user'] ?? ''),
        ];
    }
}
