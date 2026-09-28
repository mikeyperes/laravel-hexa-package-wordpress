<?php

namespace hexa_package_wordpress\Connections\Concerns;

use Closure;
use hexa_package_whm\Models\HostingAccount;
use hexa_package_wordpress\Connections\WordPressConnectionRegistry;
use hexa_package_wordpress\Models\WordPressConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * For models that use a WordPress site: the site's facts live on the shared
 * {@see WordPressConnection}, and the model reads and writes them under its own
 * attribute names.
 *
 * A model lists its names in {@see wordpressConnectionAttributes()}, mapping
 * each to a connection fact:
 *
 * - site facts: `site_url`, `transport`, `whm_server_id`, `hosting_account_id`,
 *   `cpanel_username`, `wordpress_install_id`, `wordpress_path`,
 *   `rest_username`, `hws_key_id`, `last_error`, `last_connected_at`,
 *   `capability_checked_at`;
 * - `report:<section>` for one section of the capability report;
 * - `secret:<name>` for a vault secret (never serialized);
 * - `relation:server` / `relation:hostingAccount` (read only).
 *
 * Reading returns the connection's value. Writing (fill, update, create) is
 * applied to the connection when the model saves: a changed address, install
 * or transport points the model at the matching connection (created when new);
 * other facts are written to its connection. Requires a `wordpress_connection_id`
 * column.
 */
trait UsesWordPressConnection
{
    /** @var array<string, mixed> */
    private array $pendingWordPressConnectionFacts = [];

    private const WORDPRESS_IDENTITY_FACTS = ['site_url', 'transport', 'whm_server_id', 'hosting_account_id', 'cpanel_username', 'wordpress_install_id', 'wordpress_path'];

    private const WORDPRESS_DETAIL_FACTS = ['rest_username', 'hws_key_id', 'last_error', 'last_connected_at', 'capability_checked_at'];

    /**
     * Model attribute => connection fact.
     *
     * @return array<string, string>
     */
    abstract protected function wordpressConnectionAttributes(): array;

    /** Label for a connection this model creates. */
    protected function wordpressConnectionLabel(): string
    {
        return (string) ($this->getAttributeFromArray('name') ?? $this->getAttributeFromArray('label') ?? '');
    }

    /**
     * How this model names transports, when it differs from the connection's
     * `wptoolkit` / `rest` / `hws_base_tools`.
     *
     * @return array<string, string> connection transport => model value
     */
    protected function wordpressTransportNames(): array
    {
        return [];
    }

    public static function bootUsesWordPressConnection(): void
    {
        static::saving(static function (self $model): void {
            $model->syncWordPressConnection();
        });
    }

    public function initializeUsesWordPressConnection(): void
    {
        if (! in_array('wordpressConnection', $this->with, true)) {
            $this->with[] = 'wordpressConnection';
        }
        // The site's facts are serialized under the model's own names; the
        // connection row itself (with its full capability report) is not.
        if (! in_array('wordpressConnection', $this->hidden, true)) {
            $this->hidden[] = 'wordpressConnection';
        }
    }

    public function wordpressConnection(): BelongsTo
    {
        return $this->belongsTo(WordPressConnection::class, 'wordpress_connection_id');
    }

    /** Constrain by facts of the model's connection, e.g. `fn ($c) => $c->where('transport', 'wptoolkit')`. */
    public function scopeWhereWordPressConnection(Builder $query, Closure $constraint): Builder
    {
        return $query->whereHas('wordpressConnection', $constraint);
    }

    public function scopeOrWhereWordPressConnection(Builder $query, Closure $constraint): Builder
    {
        return $query->orWhereHas('wordpressConnection', $constraint);
    }

    /** Models whose site has this address's host (scheme, "www." and path ignored). */
    public function scopeWhereSiteHost(Builder $query, string $url): Builder
    {
        return $query->whereHas('wordpressConnection', static fn (Builder $connection) => $connection->where('host', WordPressConnection::hostFromUrl($url)));
    }

    /**
     * WordPressManagerService target for this model's site.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function wordpressTarget(array $context = []): array
    {
        $connection = $this->getRelationValue('wordpressConnection');
        if (! $connection) {
            throw new \RuntimeException(class_basename($this).' #'.$this->getKey().' has no WordPress connection.');
        }

        return app(WordPressConnectionRegistry::class)->target($connection, $context);
    }

    public function getAttribute($key)
    {
        $map = $this->wordpressConnectionAttributes();
        if (! is_string($key) || ! array_key_exists($key, $map)) {
            return parent::getAttribute($key);
        }

        if (array_key_exists($key, $this->pendingWordPressConnectionFacts)) {
            return $this->pendingWordPressConnectionFacts[$key];
        }

        return $this->readWordPressConnectionFact($map[$key]);
    }

    public function setAttribute($key, $value)
    {
        $map = $this->wordpressConnectionAttributes();
        if (! is_string($key) || ! array_key_exists($key, $map)) {
            return parent::setAttribute($key, $value);
        }
        if (str_starts_with($map[$key], 'relation:')) {
            throw new InvalidArgumentException("[{$key}] is read from the WordPress connection and cannot be set.");
        }

        $this->pendingWordPressConnectionFacts[$key] = $value;

        return $this;
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();
        foreach ($this->wordpressConnectionAttributes() as $key => $fact) {
            if (str_starts_with($fact, 'secret:') || str_starts_with($fact, 'relation:') || in_array($key, $this->getHidden(), true)) {
                continue;
            }
            $value = $this->getAttribute($key);
            $attributes[$key] = $value instanceof \DateTimeInterface ? $this->serializeDate($value) : $value;
        }

        return $attributes;
    }

    /** Apply pending site facts to the shared connection; runs when the model saves. */
    public function syncWordPressConnection(): void
    {
        $map = $this->wordpressConnectionAttributes();
        $pending = $this->pendingWordPressConnectionFacts;
        if ($pending === []) {
            return;
        }
        $connection = $this->getRelationValue('wordpressConnection');

        $facts = [];
        foreach ($pending as $key => $value) {
            $facts[$map[$key]] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        $registry = app(WordPressConnectionRegistry::class);
        $identity = array_intersect_key($facts, array_flip(self::WORDPRESS_IDENTITY_FACTS));
        if (! $connection || $identity !== []) {
            $connection = $this->resolveWordPressConnection($registry, $connection, $identity);
            $this->setAttribute('wordpress_connection_id', $connection->getKey());
            $this->setRelation('wordpressConnection', $connection);
        }

        foreach ($facts as $fact => $value) {
            if (in_array($fact, self::WORDPRESS_DETAIL_FACTS, true)) {
                $connection->setAttribute($fact, $value === '' ? null : $value);
            } elseif (str_starts_with($fact, 'report:')) {
                $report = (array) $connection->capability_report;
                $report[substr($fact, 7)] = $value;
                $connection->capability_report = $report;
            }
        }
        if ($connection->isDirty()) {
            $connection->save();
        }

        foreach ($facts as $fact => $value) {
            if (str_starts_with($fact, 'secret:') && $value !== null) {
                $registry->storeSecret($connection, substr($fact, 7), (string) $value);
            }
        }

        $this->pendingWordPressConnectionFacts = [];
    }

    /** @param  array<string, mixed>  $identity  changed identity facts */
    private function resolveWordPressConnection(WordPressConnectionRegistry $registry, ?WordPressConnection $current, array $identity): WordPressConnection
    {
        // Explicit blanks clear a fact (a site moved off WP Toolkit has no install).
        $facts = array_replace(
            $current ? $current->only(self::WORDPRESS_IDENTITY_FACTS) : [],
            array_map(static fn ($value) => $value === '' ? null : $value, $identity),
        );
        if (array_key_exists('hosting_account_id', $identity) && empty($facts['hosting_account_id'])) {
            $facts['whm_server_id'] = $identity['whm_server_id'] ?? null;
            $facts['cpanel_username'] = $identity['cpanel_username'] ?? null;
        }
        if (! empty($facts['hosting_account_id']) && (empty($facts['whm_server_id']) || empty($facts['cpanel_username']))) {
            $account = HostingAccount::query()->find((int) $facts['hosting_account_id']);
            $facts['whm_server_id'] = $facts['whm_server_id'] ?? $account?->whm_server_id;
            $facts['cpanel_username'] = $facts['cpanel_username'] ?? $account?->username;
        }
        if (isset($identity['transport']) && isset($facts['transport'])) {
            $facts['transport'] = array_search($facts['transport'], $this->wordpressTransportNames(), true) ?: $facts['transport'];
        }

        return $registry->find($facts)
            ? $registry->remember($facts, overwrite: true)
            : $registry->remember($facts + ['label' => $this->wordpressConnectionLabel()]);
    }

    private function readWordPressConnectionFact(string $fact): mixed
    {
        $connection = $this->getRelationValue('wordpressConnection');
        if (! $connection) {
            return null;
        }

        return match (true) {
            $fact === 'relation:server' => $connection->whm_server_id ? $connection->server : null,
            $fact === 'relation:hostingAccount' => $connection->hosting_account_id ? $connection->hostingAccount : null,
            str_starts_with($fact, 'report:') => ((array) $connection->capability_report)[substr($fact, 7)] ?? null,
            str_starts_with($fact, 'secret:') => app(WordPressConnectionRegistry::class)->secret($connection, substr($fact, 7)),
            $fact === 'transport' => $this->wordpressTransportNames()[$connection->transport] ?? $connection->transport,
            default => $connection->getAttribute($fact),
        };
    }
}
