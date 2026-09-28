<?php

namespace hexa_package_wordpress\Connections;

use hexa_core\Services\CredentialService;
use hexa_package_wordpress\Models\WordPressConnection;
use InvalidArgumentException;

/**
 * The one place that finds, records and reads WordPress connections.
 *
 * Tools that talk to WordPress (Publish sites, journalist publications,
 * verified-profile sites) keep only a `wordpress_connection_id`. This registry
 * turns facts about a site into that shared row, stores its secrets in Core's
 * CredentialService, and builds the target array WordPressManagerService takes.
 */
class WordPressConnectionRegistry
{
    public const SECRET_APPLICATION_PASSWORD = 'application_password';

    public const SECRET_HWS_API_SECRET = 'hws_api_secret';

    private const SECRETS = [self::SECRET_APPLICATION_PASSWORD, self::SECRET_HWS_API_SECRET];

    private const TRANSPORT_ALIASES = [
        'wptoolkit' => WordPressConnection::TRANSPORT_WPTOOLKIT,
        'wp_toolkit' => WordPressConnection::TRANSPORT_WPTOOLKIT,
        'rest' => WordPressConnection::TRANSPORT_REST,
        'wp_rest_api' => WordPressConnection::TRANSPORT_REST,
        'rest_api' => WordPressConnection::TRANSPORT_REST,
        'hws_base_tools' => WordPressConnection::TRANSPORT_HWS_BASE_TOOLS,
    ];

    public function __construct(private readonly CredentialService $credentials) {}

    /**
     * Find the connection for one site, creating it when new.
     *
     * A WP Toolkit site is identified by its server and install; any other site
     * by its host. Known facts are filled in when blank and never erased;
     * `$overwrite` replaces non-empty facts with the ones given.
     *
     * @param  array{site_url?: string, url?: string, label?: string, transport?: string, whm_server_id?: int|null, hosting_account_id?: int|null, cpanel_username?: string|null, wordpress_install_id?: int|string|null, wordpress_path?: string|null, rest_username?: string|null, hws_key_id?: string|null}  $facts
     */
    public function remember(array $facts, bool $overwrite = false): WordPressConnection
    {
        $facts = $this->normalizeFacts($facts);
        $connection = $this->find($facts);
        if (! $connection) {
            if ($facts['site_url'] === '') {
                throw new InvalidArgumentException('A new WordPress connection needs the site URL.');
            }
            $connection = new WordPressConnection(['status' => 'unknown', 'label' => $facts['label'] !== '' ? $facts['label'] : $facts['host']]);
        }

        foreach ($facts as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $current = $connection->getAttribute($column);
            if ($overwrite || $current === null || $current === '' || ! $connection->exists) {
                $connection->setAttribute($column, $value);
            }
        }

        if ($connection->isDirty()) {
            $connection->save();
        }

        return $connection;
    }

    /** The existing connection for these facts, or null. */
    public function find(array $facts): ?WordPressConnection
    {
        $facts = $this->normalizeFacts($facts);
        if ($facts['whm_server_id'] && $facts['wordpress_install_id']) {
            $byInstall = WordPressConnection::query()
                ->where('whm_server_id', $facts['whm_server_id'])
                ->where('wordpress_install_id', $facts['wordpress_install_id'])
                ->first();
            if ($byInstall) {
                return $byInstall;
            }
        }

        return $facts['host'] !== '' ? WordPressConnection::query()->where('host', $facts['host'])->first() : null;
    }

    public function secret(WordPressConnection $connection, string $name): ?string
    {
        $this->assertSecretName($name);

        return $connection->exists ? $this->credentials->get($connection->credentialSlug(), $name) : null;
    }

    /** Store a secret; an empty value removes it. */
    public function storeSecret(WordPressConnection $connection, string $name, ?string $value): void
    {
        $this->assertSecretName($name);
        if (! $connection->exists) {
            throw new InvalidArgumentException('Save the connection before storing its secrets.');
        }

        $value = trim((string) $value);
        $value === ''
            ? $this->credentials->delete($connection->credentialSlug(), $name)
            : $this->credentials->store($connection->credentialSlug(), $name, $value);
    }

    public function hasSecret(WordPressConnection $connection, string $name): bool
    {
        return trim((string) $this->secret($connection, $name)) !== '';
    }

    /**
     * WordPressManagerService target for this connection. Secrets are read only
     * for transports that use them. `$context` adds caller facts such as
     * site_id, site_name or default_author and wins over connection facts.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function target(WordPressConnection $connection, array $context = []): array
    {
        $usesSecrets = $connection->transport !== WordPressConnection::TRANSPORT_WPTOOLKIT;

        return array_replace([
            'mode' => $connection->usesWpToolkit() ? 'wptoolkit' : ($connection->transport === WordPressConnection::TRANSPORT_HWS_BASE_TOOLS ? 'hws_base_tools' : 'rest'),
            'connection_id' => (int) $connection->getKey(),
            'site_name' => (string) $connection->label,
            'url' => rtrim((string) $connection->site_url, '/'),
            'username' => (string) ($connection->rest_username ?? ''),
            'application_password' => $usesSecrets ? (string) $this->secret($connection, self::SECRET_APPLICATION_PASSWORD) : '',
            'hws_key_id' => (string) ($connection->hws_key_id ?? ''),
            'hws_api_secret' => $usesSecrets ? (string) $this->secret($connection, self::SECRET_HWS_API_SECRET) : '',
            'server' => $connection->whm_server_id ? $connection->server : null,
            'hosting_account' => $connection->hosting_account_id ? $connection->hostingAccount : null,
            'install_id' => $connection->wordpress_install_id ?: null,
            'cpanel_user' => (string) ($connection->cpanel_username ?? ''),
            'wp_path' => (string) ($connection->wordpress_path ?? ''),
        ], $context);
    }

    /**
     * Record the outcome of a connection check.
     *
     * The capability report is kept in named sections, one per kind of check
     * (for example `connection` from Publish's transport test, `publication`
     * and `plugins` from the journalist context inspection). A check replaces
     * only the sections it reports, so tools never erase each other's results.
     *
     * @param  array<string, mixed>|null  $sections  secret-free report sections
     */
    public function recordCheck(WordPressConnection $connection, bool $connected, ?string $error = null, ?array $sections = null): void
    {
        $connection->status = $connected ? 'connected' : 'error';
        $connection->last_error = $connected ? null : ($error ?: 'Connection check failed.');
        if ($connected) {
            $connection->last_connected_at = now();
        }
        if ($sections !== null) {
            $connection->capability_report = array_replace((array) $connection->capability_report, $sections);
            $connection->capability_checked_at = now();
        }
        $connection->save();
    }

    /** @return array<string, mixed> */
    private function normalizeFacts(array $facts): array
    {
        $url = trim((string) ($facts['site_url'] ?? $facts['url'] ?? ''));
        $url = $url === '' ? '' : rtrim(str_contains($url, '://') ? $url : 'https://'.$url, '/');
        $transport = strtolower(trim((string) ($facts['transport'] ?? $facts['connection_type'] ?? '')));
        $serverId = (int) ($facts['whm_server_id'] ?? 0);
        $installId = (int) ($facts['wordpress_install_id'] ?? 0);
        if ($transport === '') {
            $transport = $serverId > 0 && $installId > 0 ? WordPressConnection::TRANSPORT_WPTOOLKIT : WordPressConnection::TRANSPORT_REST;
        }
        if (! isset(self::TRANSPORT_ALIASES[$transport])) {
            throw new InvalidArgumentException("Unknown WordPress transport [{$transport}].");
        }

        $host = $url !== '' ? WordPressConnection::hostFromUrl($url) : '';
        $label = trim((string) ($facts['label'] ?? $facts['name'] ?? ''));

        return [
            'label' => $label,
            'site_url' => $url,
            'host' => $host,
            'transport' => self::TRANSPORT_ALIASES[$transport],
            'whm_server_id' => $serverId > 0 ? $serverId : null,
            'hosting_account_id' => (int) ($facts['hosting_account_id'] ?? 0) ?: null,
            'cpanel_username' => $this->nullableString($facts['cpanel_username'] ?? $facts['cpanel_user'] ?? null),
            'wordpress_install_id' => $installId > 0 ? $installId : null,
            'wordpress_path' => $this->nullableString($facts['wordpress_path'] ?? $facts['wp_path'] ?? null),
            'rest_username' => $this->nullableString($facts['rest_username'] ?? $facts['username'] ?? $facts['wp_username'] ?? null),
            'hws_key_id' => $this->nullableString($facts['hws_key_id'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertSecretName(string $name): void
    {
        if (! in_array($name, self::SECRETS, true)) {
            throw new InvalidArgumentException("Unknown WordPress connection secret [{$name}].");
        }
    }
}
