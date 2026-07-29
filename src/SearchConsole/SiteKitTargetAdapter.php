<?php

namespace hexa_package_wordpress\SearchConsole;

use hexa_package_google_search_console\Contracts\TargetAdapter;
use hexa_package_google_search_console\Models\GoogleSearchConsoleProperty;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class SiteKitTargetAdapter implements TargetAdapter
{
    private const PLUGIN_SLUG = 'google-site-kit';

    public function __construct(private readonly WpToolkitService $toolkit)
    {
    }

    public function key(): string
    {
        return 'wordpress-site-kit';
    }

    public function label(): string
    {
        return 'WordPress / Site Kit';
    }

    public function targets(bool $forceRefresh = false): array
    {
        $cacheKey = 'google-search-console:wordpress-site-kit:targets:v1';
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(10), function (): array {
            $targets = [];
            $errors = [];
            foreach (WhmServer::query()->where('is_active', true)->orderBy('name')->get() as $server) {
                $result = $this->toolkit->getAllInstalls($server);
                if (!($result['success'] ?? false)) {
                    $errors[] = $server->name . ': ' . (string) ($result['error'] ?? $result['message'] ?? 'Discovery failed.');
                    continue;
                }

                foreach ((array) ($result['installs'] ?? []) as $install) {
                    $installId = (int) ($install['id'] ?? 0);
                    if ($installId < 1) {
                        continue;
                    }
                    $url = rtrim((string) ($install['url'] ?? ''), '/');
                    $label = $url !== '' ? $url : ((string) ($install['name'] ?? "WordPress install {$installId}"));
                    $targets[] = [
                        'key' => $server->id . ':' . $installId,
                        'label' => $label . ' - ' . $server->name,
                        'url' => $url,
                        'meta' => [
                            'server_id' => $server->id,
                            'install_id' => $installId,
                            'path' => (string) ($install['path'] ?? ''),
                            'cpanel_user' => (string) ($install['cpanel_user'] ?? ''),
                            'admin_user' => (string) ($install['admin_user'] ?? ''),
                        ],
                    ];
                }
            }

            if ($targets === [] && $errors !== []) {
                throw new RuntimeException('WordPress discovery failed on every active server: ' . implode(' | ', $errors));
            }

            usort($targets, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

            return $targets;
        });
    }

    public function attach(GoogleSearchConsoleProperty $property, string $targetKey): array
    {
        [$server, $installId] = $this->resolveTarget($targetKey);
        $info = $this->installInfo($server, $installId);
        $siteUrl = rtrim((string) ($info['siteUrl'] ?? $info['url'] ?? ''), '/');
        if (!$this->propertyMatchesSite($property->site_url, $siteUrl)) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => "The selected Search Console property does not match WordPress target {$siteUrl}.",
            ];
        }

        $plugin = $this->toolkit->ensurePluginInstalledAndActive($server, $installId, self::PLUGIN_SLUG);
        if (!($plugin['success'] ?? false)) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => (string) ($plugin['message'] ?? 'Site Kit installation failed.'),
                'plugin' => $plugin,
            ];
        }

        return [
            'success' => true,
            'status' => 'awaiting_google_oauth',
            'message' => 'Site Kit is installed and active. Complete Google consent inside WordPress to finish the supported connection.',
            'action_url' => $this->siteKitActionUrl($server, $info, $siteUrl),
            'plugin' => [
                'slug' => self::PLUGIN_SLUG,
                'installed' => true,
                'active' => true,
                'changed' => (bool) ($plugin['changed'] ?? false),
                'version' => (string) data_get($plugin, 'plugin.version', ''),
            ],
            'property' => $property->site_url,
            'target_url' => $siteUrl,
            'consent_required' => true,
        ];
    }

    public function inspect(string $targetKey): array
    {
        [$server, $installId] = $this->resolveTarget($targetKey);
        $status = $this->toolkit->getPluginStatus($server, $installId, self::PLUGIN_SLUG);
        if (!($status['success'] ?? false)) {
            return ['success' => false, 'status' => 'failed', 'message' => (string) ($status['message'] ?? 'Site Kit inspection failed.')];
        }

        $installed = (bool) ($status['installed'] ?? false);
        $active = (bool) ($status['active'] ?? false);

        return [
            'success' => true,
            'status' => $active ? 'awaiting_google_oauth' : ($installed ? 'plugin_inactive' : 'plugin_missing'),
            'message' => $active
                ? 'Site Kit is active. Google consent and module state remain controlled by Site Kit.'
                : (string) ($status['message'] ?? 'Site Kit is not ready.'),
            'plugin' => $status['plugin'] ?? null,
            'consent_required' => $active,
        ];
    }

    /** @return array{0: WhmServer, 1: int} */
    private function resolveTarget(string $targetKey): array
    {
        if (!preg_match('/^(\d+):(\d+)$/', $targetKey, $matches)) {
            throw new InvalidArgumentException('WordPress target key is invalid.');
        }
        $server = WhmServer::query()->where('is_active', true)->find((int) $matches[1]);
        if (!$server) {
            throw new InvalidArgumentException('The WordPress target server is unavailable.');
        }

        return [$server, (int) $matches[2]];
    }

    /** @return array<string, mixed> */
    private function installInfo(WhmServer $server, int $installId): array
    {
        $result = $this->toolkit->getInstallInfo($server, $installId);
        if (!($result['success'] ?? false) || !is_array($result['data'] ?? null)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Unable to load the WordPress install.'));
        }

        return $result['data'];
    }

    private function propertyMatchesSite(string $property, string $siteUrl): bool
    {
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if ($siteHost === '') {
            return false;
        }
        if (str_starts_with($property, 'sc-domain:')) {
            $domain = strtolower(substr($property, strlen('sc-domain:')));

            return $siteHost === $domain || str_ends_with($siteHost, '.' . $domain);
        }

        return str_starts_with(rtrim($siteUrl, '/') . '/', rtrim($property, '/') . '/');
    }

    private function siteKitActionUrl(WhmServer $server, array $info, string $siteUrl): string
    {
        $path = (string) ($info['fullPath'] ?? $info['path'] ?? '');
        $cpanelUser = '';
        if (preg_match('#^/home/([^/]+)/#', $path, $matches)) {
            $cpanelUser = $matches[1];
        }
        $adminUser = (string) ($info['adminLogin'] ?? $info['admin_login'] ?? $info['adminUser'] ?? '');
        if ($path !== '' && $cpanelUser !== '' && $adminUser !== '' && $siteUrl !== '') {
            $login = $this->toolkit->generateWordPressLoginUrl(
                $server,
                $path,
                $cpanelUser,
                $adminUser,
                $siteUrl,
                'admin.php?page=googlesitekit-dashboard',
            );
            if (($login['success'] ?? false) && filled($login['url'] ?? null)) {
                return (string) $login['url'];
            }
        }

        return rtrim($siteUrl, '/') . '/wp-admin/admin.php?page=googlesitekit-dashboard';
    }
}
