<?php

namespace hexa_package_wordpress\Services;

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
    public function fetch(array $target, int $postId, string $postType = 'post'): array
    {
        $result = $this->wordpress->getPostSnapshot($target, $postId, $postType);
        if (! ($result['success'] ?? false) || ! is_array($result['post'] ?? null)) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'The WordPress post could not be loaded.'),
                'post' => null,
                'extensions' => [],
                'extension_errors' => [],
                'refreshed_at' => now()->toIso8601String(),
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

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? 'WordPress post refreshed.'),
            'post' => $post,
            'extensions' => $extended['data'],
            'extension_errors' => $extended['errors'],
            'refreshed_at' => now()->toIso8601String(),
        ];
    }
}
