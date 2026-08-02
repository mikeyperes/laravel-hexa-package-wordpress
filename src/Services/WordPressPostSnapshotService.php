<?php

namespace hexa_package_wordpress\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WordPressPostSnapshotService
{
    public function __construct(
        private readonly WordPressManagerService $wordpress,
        private readonly WordPressPostSnapshotExtensionRegistry $extensions,
    ) {}

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function cached(array $target, int $postId, string $postType = 'post'): array
    {
        $snapshot = Cache::get($this->cacheKey($target, $postId, $postType));
        if (! is_array($snapshot) || ! ($snapshot['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'No cached WordPress snapshot is available yet. Press Refresh post to build it.',
                'post' => null,
                'extensions' => [],
                'extension_errors' => [],
                'cache_rebuilt_at' => null,
                'cached' => false,
                'cache_miss' => true,
            ];
        }

        $snapshot['cached'] = true;
        $snapshot['cache_miss'] = false;
        $snapshot['refresh_succeeded'] = null;

        return $snapshot;
    }

    public function refresh(array $target, int $postId, string $postType = 'post'): array
    {
        $snapshot = $this->loadRemote($target, $postId, $postType);
        if ($snapshot['success'] ?? false) {
            Cache::forever($this->cacheKey($target, $postId, $postType), $snapshot);

            return $snapshot;
        }

        $cached = $this->cached($target, $postId, $postType);
        if ($cached['success'] ?? false) {
            $cached['stale'] = true;
            $cached['refresh_succeeded'] = false;
            $cached['refresh_error'] = (string) ($snapshot['message'] ?? 'WordPress refresh failed.');
            $cached['message'] = 'WordPress refresh failed. The previous cached snapshot is still shown.';

            return $cached;
        }

        return $snapshot;
    }

    /**
     * Backward-compatible live read. Consumers that render a page should call cached() instead.
     *
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function fetch(array $target, int $postId, string $postType = 'post'): array
    {
        return $this->refresh($target, $postId, $postType);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function loadRemote(array $target, int $postId, string $postType): array
    {
        $result = $this->wordpress->getPostSnapshot($target, $postId, $postType);
        if (! ($result['success'] ?? false) || ! is_array($result['post'] ?? null)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'The WordPress post could not be loaded.'),
                'post' => null,
                'extensions' => [],
                'extension_errors' => [],
                'cache_rebuilt_at' => null,
                'cached' => false,
                'refresh_succeeded' => false,
            ];
        }

        $post = (array) $result['post'];
        $extended = $this->extensions->apply($target, $post);
        unset($post['meta']);

        $post['title'] = html_entity_decode(
            (string) ($post['title'] ?? ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $contentText = html_entity_decode(
            strip_tags((string) ($post['content_html'] ?? $post['content_raw'] ?? '')),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $post['word_count'] = Str::wordCount($contentText);

        $rebuiltAt = now()->toIso8601String();

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? 'WordPress cache rebuilt.'),
            'post' => $post,
            'extensions' => $extended['data'],
            'extension_errors' => $extended['errors'],
            'cache_rebuilt_at' => $rebuiltAt,
            'refreshed_at' => $rebuiltAt,
            'cached' => true,
            'cache_miss' => false,
            'stale' => false,
            'refresh_succeeded' => true,
        ];
    }

    /** @param array<string, mixed> $target */
    private function cacheKey(array $target, int $postId, string $postType): string
    {
        $identity = [
            'server' => (int) data_get($target, 'server.id', data_get($target, 'server_id', 0)),
            'install' => (int) ($target['install_id'] ?? $target['wordpress_install_id'] ?? 0),
            'url' => strtolower(rtrim((string) ($target['url'] ?? $target['site_url'] ?? ''), '/')),
            'post' => $postId,
            'type' => $postType,
        ];

        return 'wordpress:post-snapshot:v1:'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
