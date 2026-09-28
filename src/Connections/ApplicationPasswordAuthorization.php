<?php

namespace hexa_package_wordpress\Connections;

use hexa_package_wordpress\Models\WordPressConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * WordPress's own "Authorize Application" flow for a connection.
 *
 * {@see begin()} returns the site's /wp-admin/authorize-application.php URL.
 * A signed-in administrator approves it once, and WordPress sends the new
 * Application Password straight to {@see complete()} through the callback
 * route, so the password never passes through a person, chat or screenshot.
 * It is checked against /wp/v2/users/me and stored on the connection.
 */
class ApplicationPasswordAuthorization
{
    public const DEFAULT_APP_NAME = 'Hexa Web Systems';

    private const CACHE_PREFIX = 'wordpress_app_password_authorization:';

    private const TTL_MINUTES = 30;

    public function __construct(private readonly WordPressConnectionRegistry $connections) {}

    /**
     * Start one authorization for a site, creating its connection when new.
     *
     * @return array{connection_id: int, host: string, authorize_url: string, expires_at: string}
     */
    public function begin(string $siteUrl, string $label = '', string $appName = self::DEFAULT_APP_NAME): array
    {
        $connection = $this->connections->remember(array_filter([
            'site_url' => $siteUrl,
            'label' => $label,
            'transport' => WordPressConnection::TRANSPORT_REST,
        ]));
        $state = Str::random(64);
        $expires = now()->addMinutes(self::TTL_MINUTES);
        Cache::put(self::CACHE_PREFIX.hash('sha256', $state), ['connection_id' => (int) $connection->getKey()], $expires);

        $callback = route('wordpress.app-password.callback', ['state' => $state]);
        $query = http_build_query([
            'app_name' => trim($appName) !== '' ? trim($appName) : self::DEFAULT_APP_NAME,
            'app_id' => $this->appId($connection),
            'success_url' => $callback,
            'reject_url' => $callback.'?rejected=1',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'connection_id' => (int) $connection->getKey(),
            'host' => (string) $connection->host,
            'authorize_url' => rtrim((string) $connection->site_url, '/').'/wp-admin/authorize-application.php?'.$query,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * Finish an authorization from WordPress's redirect. The state works once.
     *
     * @param  array<string, mixed>  $query
     * @return array{connection_id: int, host: string, username: string, user_id: int, roles: array<int, string>}
     */
    public function complete(string $state, array $query): array
    {
        $pending = Cache::pull(self::CACHE_PREFIX.hash('sha256', $state));
        if (! is_array($pending)) {
            throw new InvalidArgumentException('This authorization link is unknown, used or expired. Start a new one.');
        }
        $connection = WordPressConnection::query()->find((int) ($pending['connection_id'] ?? 0));
        if (! $connection) {
            throw new InvalidArgumentException('The WordPress connection for this authorization no longer exists.');
        }
        if (! empty($query['rejected'])) {
            throw new InvalidArgumentException('The administrator rejected the connection on '.$connection->host.'.');
        }

        $siteHost = WordPressConnection::hostFromUrl((string) ($query['site_url'] ?? ''));
        $username = trim((string) ($query['user_login'] ?? ''));
        $password = trim((string) ($query['password'] ?? ''));
        if ($siteHost !== $connection->host || $username === '' || $password === '') {
            throw new InvalidArgumentException('WordPress did not return a complete Application Password for '.$connection->host.'.');
        }

        $response = Http::withBasicAuth($username, $password)->acceptJson()->timeout(30)
            ->get(rtrim((string) $connection->site_url, '/').'/wp-json/wp/v2/users/me', ['context' => 'edit']);
        $roles = array_values(array_map('strval', (array) $response->json('roles')));
        $caps = (array) $response->json('capabilities');
        if (! $response->successful() || (! in_array('administrator', $roles, true) && empty($caps['manage_options']))) {
            $this->connections->recordCheck($connection, false, 'The authorized user is not a working administrator (HTTP '.$response->status().').');
            throw new InvalidArgumentException('The new Application Password on '.$connection->host.' is not a working administrator login.');
        }

        $connection->rest_username = $username;
        $connection->save();
        $this->connections->storeSecret($connection, WordPressConnectionRegistry::SECRET_APPLICATION_PASSWORD, $password);
        $this->connections->recordCheck($connection, true, null, ['authorization' => [
            'method' => 'authorize_application',
            'user_id' => (int) $response->json('id'),
            'roles' => $roles,
            'authorized_at' => now()->toIso8601String(),
        ]]);

        return [
            'connection_id' => (int) $connection->getKey(),
            'host' => (string) $connection->host,
            'username' => $username,
            'user_id' => (int) $response->json('id'),
            'roles' => $roles,
        ];
    }

    /** One stable UUID per connection, so WordPress groups repeat approvals. */
    private function appId(WordPressConnection $connection): string
    {
        $hash = md5('hexa-wordpress-connection:'.$connection->getKey());

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
