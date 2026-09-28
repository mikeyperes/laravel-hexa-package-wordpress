<?php

namespace hexa_package_wordpress\Connections\Concerns;

use hexa_package_wordpress\Connections\WordPressSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * For records that tie something of ours to one object on a WordPress site: a
 * journalist to a WordPress user, a verified profile to a WordPress post.
 *
 * Each tool keeps its own table and names; it describes them once in
 * {@see wordpressAttachment()} and gets the same behavior:
 *
 * - `wordpressPullAttributes()`     save what WordPress returned;
 * - `wordpressPushAttributes()`     save what was written to WordPress;
 * - `wordpressOutdatedAttributes()` mark the WordPress copy as behind ours;
 * - `wordpressRemovedAttributes()`  the object was deleted on WordPress;
 * - `remoteSnapshot()`, `remoteObjectId()`, `wordpressEditUrl()`.
 *
 * The `*Attributes()` methods return columns for the caller's own
 * fill()->save(), so one save still writes everything. Every stored copy is
 * cleaned by {@see WordPressSnapshot::clean()}. The site relation must point at
 * a model that uses {@see UsesWordPressConnection}.
 */
trait AttachesToWordPressObject
{
    /**
     * @return array{
     *     site: string,
     *     object: 'user'|'post',
     *     remote_id: string,
     *     snapshot: array<string, string>,
     *     pulled_at: string,
     *     pushed_at?: string|null,
     *     checked_at?: string|null,
     *     sync?: array{status: string, outdated_at: string, message: string}|null
     * } relation name, WordPress object kind, and this table's column names;
     *   `snapshot` maps each section of the WordPress copy to its column
     */
    abstract protected function wordpressAttachment(): array;

    /** The site record (journalist publication, verified site, ...) this object lives on. */
    public function wordpressSite(): ?Model
    {
        return $this->getRelationValue($this->wordpressAttachment()['site']);
    }

    public function remoteObjectId(): string
    {
        return trim((string) $this->getAttribute($this->wordpressAttachment()['remote_id']));
    }

    /**
     * The stored WordPress copy: one section, or all sections keyed by name.
     *
     * @return array<array-key, mixed>
     */
    public function remoteSnapshot(?string $section = null): array
    {
        $columns = $this->wordpressAttachment()['snapshot'];
        if ($section !== null) {
            return (array) ($this->getAttribute($this->snapshotColumn($section)) ?? []);
        }

        return array_map(fn (string $column): array => (array) ($this->getAttribute($column) ?? []), $columns);
    }

    /** wp-admin edit screen for this user or post, or "" when the site or id is unknown. */
    public function wordpressEditUrl(): string
    {
        $base = rtrim((string) ($this->wordpressSite()?->getRelationValue('wordpressConnection')?->site_url ?? ''), '/');
        $id = $this->remoteObjectId();
        if ($base === '' || $id === '') {
            return '';
        }

        return $this->wordpressAttachment()['object'] === 'user'
            ? $base.'/wp-admin/user-edit.php?user_id='.rawurlencode($id)
            : $base.'/wp-admin/post.php?post='.rawurlencode($id).'&action=edit';
    }

    /**
     * Columns for a copy just read from WordPress. A record whose local changes
     * are still waiting to be written stays outdated: reading WordPress does not
     * apply them (see smp-verified-profiles BUGLOG.md VERIFIED-BUG-004).
     *
     * @param  array<string, array<array-key, mixed>>  $sections  section name => data
     * @param  bool  $merge  keep stored keys the read did not return
     * @return array<string, mixed>
     */
    public function wordpressPullAttributes(array $sections, bool $merge = false, ?string $message = null): array
    {
        $config = $this->wordpressAttachment();

        return array_merge(
            $this->snapshotAttributes($sections, $merge),
            [$config['pulled_at'] => now()],
            $this->checkedAttributes(),
            $this->hasPendingWordPressChanges() ? [] : $this->syncAttributes('synced', null, $message),
        );
    }

    /** Local changes are waiting to be written to WordPress. */
    public function hasPendingWordPressChanges(): bool
    {
        $sync = $this->wordpressAttachment()['sync'] ?? null;

        return $sync !== null && $this->getAttribute($sync['status']) === 'outdated';
    }

    /**
     * Columns for a copy just written to WordPress. Written sections are merged
     * into the stored copy.
     *
     * @param  array<string, array<array-key, mixed>>  $sections
     * @return array<string, mixed>
     */
    public function wordpressPushAttributes(array $sections, ?string $message = null): array
    {
        $config = $this->wordpressAttachment();

        return array_merge(
            $this->snapshotAttributes($sections, true),
            ! empty($config['pushed_at']) ? [$config['pushed_at'] => now()] : [$config['pulled_at'] => now()],
            $this->checkedAttributes(),
            $this->syncAttributes('synced', null, $message),
        );
    }

    /**
     * Columns for "our copy changed; WordPress needs an update". Sections, when
     * given, replace the stored copy with the locally edited version.
     *
     * @param  array<string, array<array-key, mixed>>  $sections
     * @return array<string, mixed>
     */
    public function wordpressOutdatedAttributes(string $message, array $sections = []): array
    {
        return array_merge($this->snapshotAttributes($sections, false), $this->syncAttributes('outdated', now(), $message));
    }

    /** @return array<string, mixed> columns for an object deleted on WordPress */
    public function wordpressRemovedAttributes(string $message): array
    {
        return array_merge($this->checkedAttributes(), $this->syncAttributes('deleted', false, $message));
    }

    /**
     * Mark every record in a query as behind its local source, in one update.
     *
     * @param  Builder<static>|Relation<static, *, *>  $query
     */
    public static function markWordPressOutdated(Builder|Relation $query, string $message): int
    {
        return $query->update((new static)->wordpressOutdatedAttributes($message));
    }

    /** @return array<string, mixed> */
    private function snapshotAttributes(array $sections, bool $merge): array
    {
        $attributes = [];
        foreach ($sections as $section => $data) {
            $column = $this->snapshotColumn((string) $section);
            $data = WordPressSnapshot::clean(is_array($data) ? $data : []);
            $attributes[$column] = $merge ? array_replace((array) ($this->getAttribute($column) ?? []), $data) : $data;
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function checkedAttributes(): array
    {
        $column = $this->wordpressAttachment()['checked_at'] ?? null;

        return $column ? [$column => now()] : [];
    }

    /**
     * @param  \DateTimeInterface|null|false  $outdatedAt  false leaves the column unchanged
     * @return array<string, mixed>
     */
    private function syncAttributes(string $status, \DateTimeInterface|null|false $outdatedAt, ?string $message): array
    {
        $sync = $this->wordpressAttachment()['sync'] ?? null;
        if (! $sync) {
            return [];
        }

        $attributes = [$sync['status'] => $status];
        if ($outdatedAt !== false) {
            $attributes[$sync['outdated_at']] = $outdatedAt;
        }
        if ($message !== null) {
            $attributes[$sync['message']] = $message;
        }

        return $attributes;
    }

    private function snapshotColumn(string $section): string
    {
        $columns = $this->wordpressAttachment()['snapshot'];
        if (! isset($columns[$section])) {
            throw new InvalidArgumentException(class_basename($this)." has no WordPress snapshot section [{$section}].");
        }

        return $columns[$section];
    }
}
