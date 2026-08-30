<?php

namespace hexa_package_wordpress\Services;

use hexa_package_whm\Models\WhmServer;
use RuntimeException;

class WordPressLoginUrlService
{
    public function __construct(private readonly WordPressManagerService $wordpress) {}

    /**
     * Generate a validated, single-use WP Toolkit login for a configured target.
     * Passing no user ID uses the installation's registered administrator.
     *
     * @param array<string, mixed> $target
     * @return array{url: string, expires_in: int, user: string, site_url: string}
     */
    public function create(array $target, ?int $userId = null, string $redirectPath = ''): array
    {
        $configuredUrl = rtrim((string) ($target['url'] ?? $target['site_url'] ?? ''), '/');
        $cpanelUser = strtolower(trim((string) (
            $target['cpanel_user'] ?? $target['cpanel_username'] ?? ''
        )));
        if (preg_match('/\A[a-z][a-z0-9_]{0,15}\z/', $cpanelUser) !== 1) {
            throw new RuntimeException('The saved WordPress cPanel account is invalid.');
        }

        $normalized = $this->wordpress->normalizeTarget(array_replace($target, [
            'mode' => 'wptoolkit',
            'cpanel_user' => $cpanelUser,
            'url' => $configuredUrl,
        ]));
        $server = $normalized['server'] ?? null;
        if (! $server instanceof WhmServer || ! $server->is_active) {
            throw new RuntimeException('The saved WP Toolkit server is unavailable.');
        }

        $installResult = $this->wordpress->getInstallInfo($normalized);
        if (! ($installResult['success'] ?? false)) {
            throw new RuntimeException(
                'WP Toolkit could not resolve the saved install: '
                .(string) ($installResult['message'] ?? 'unknown install error')
            );
        }

        $install = (array) ($installResult['install'] ?? []);
        $installId = (int) ($normalized['install_id'] ?? 0);
        $installPath = rtrim((string) ($install['path'] ?? ''), '/');
        $installUrl = rtrim((string) ($install['url'] ?? ''), '/');
        if ((int) ($install['id'] ?? 0) !== $installId) {
            throw new RuntimeException('WP Toolkit returned an unexpected install ID.');
        }
        if (! str_starts_with($installPath.'/', '/home/'.$cpanelUser.'/')) {
            throw new RuntimeException('The WP Toolkit install path does not match the saved cPanel account.');
        }
        if (
            $this->scheme($installUrl) !== 'https'
            || $this->host($installUrl) === ''
            || $this->host($installUrl) !== $this->host($configuredUrl)
        ) {
            throw new RuntimeException('The WP Toolkit install host does not match the saved target.');
        }

        $resolvedTarget = array_replace($normalized, [
            'url' => $installUrl,
            'wp_path' => $installPath,
        ]);
        $wpUser = $userId && $userId > 0
            ? $this->userLogin($resolvedTarget, $userId)
            : trim((string) ($install['admin_user'] ?? ''));
        if ($wpUser === '') {
            $administrators = $this->wordpress->listUsers($resolvedTarget, [
                'role' => 'administrator',
                'per_page' => 1,
                'force_refresh' => true,
            ]);
            $wpUser = trim((string) data_get($administrators, 'users.0.user_login', ''));
        }
        if ($wpUser === '') {
            throw new RuntimeException('WP Toolkit could not resolve a WordPress login account.');
        }

        $loginResult = $this->wordpress->generateLoginUrl(
            $resolvedTarget,
            $wpUser,
            ltrim($redirectPath, '/')
        );
        $loginUrl = trim((string) ($loginResult['url'] ?? ''));
        if (! ($loginResult['success'] ?? false) || $loginUrl === '') {
            throw new RuntimeException(
                'WP Toolkit could not create the secure login: '
                .(string) ($loginResult['error'] ?? $loginResult['message'] ?? 'unknown login error')
            );
        }
        if ($this->scheme($loginUrl) !== 'https' || $this->host($loginUrl) !== $this->host($installUrl)) {
            throw new RuntimeException('WP Toolkit returned a login URL for an unexpected host.');
        }

        return [
            'url' => $loginUrl,
            'expires_in' => max(1, (int) ($loginResult['expires_in'] ?? 300)),
            'user' => $wpUser,
            'site_url' => $installUrl,
        ];
    }

    /** @param array<string, mixed> $target */
    private function userLogin(array $target, int $userId): string
    {
        $profile = $this->wordpress->getUserProfile($target, $userId, true);
        $wpUser = trim((string) data_get($profile, 'data.user_login', ''));
        if (! ($profile['success'] ?? false) || $wpUser === '') {
            throw new RuntimeException(
                "WP Toolkit could not resolve WordPress user #{$userId}: "
                .(string) ($profile['message'] ?? 'unknown user error')
            );
        }

        return $wpUser;
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function scheme(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME));
    }
}
