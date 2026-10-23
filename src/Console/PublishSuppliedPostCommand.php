<?php

namespace hexa_package_wordpress\Console;

use hexa_package_wordpress\Connections\WordPressConnectionRegistry;
use hexa_package_wordpress\Services\WordPressManagerService;
use Illuminate\Console\Command;

/**
 * Write one caller-prepared post to one connected WordPress site: upload its
 * photos, place them in the body, resolve categories and tags, then create or
 * update the post. Without --confirm it only checks the packet and the site.
 */
class PublishSuppliedPostCommand extends Command
{
    protected $signature = 'wordpress:post {site : Site URL or host of a stored connection}
        {--packet= : Path to the post packet JSON}
        {--confirm : Write the post; without it nothing is written}';

    protected $description = 'Upload photos and create or update one supplied post on a connected WordPress site (preview without --confirm)';

    public function handle(WordPressConnectionRegistry $connections, WordPressManagerService $wordpress): int
    {
        $connection = $connections->find(['site_url' => (string) $this->argument('site')]);
        if (! $connection) {
            return $this->emit(['success' => false, 'error' => 'No stored WordPress connection for this site.']);
        }

        $packet = $this->readPacket((string) $this->option('packet'));
        if (isset($packet['error'])) {
            return $this->emit(['success' => false, 'error' => $packet['error']]);
        }

        $images = array_values((array) ($packet['images'] ?? []));
        $problems = [];
        foreach ($images as $index => $image) {
            $key = (string) ($image['key'] ?? '');
            $path = (string) ($image['path'] ?? '');
            if ($key === '' || ! is_file($path) || ! is_readable($path)) {
                $problems[] = 'Image '.($index + 1).' needs a key and a readable file path.';
            } elseif (! ($image['featured'] ?? false) && ! str_contains((string) $packet['content'], '{{image:'.$key.'}}')) {
                $problems[] = 'Inline image "'.$key.'" has no {{image:'.$key.'}} place in the content.';
            }
        }
        if (count(array_filter($images, static fn ($image) => (bool) ($image['featured'] ?? false))) > 1) {
            $problems[] = 'Only one image can be the featured image.';
        }

        $summary = [
            'connection_id' => (int) $connection->getKey(),
            'site' => $connection->site_url,
            'transport' => $connection->transport,
            'post_id' => (int) ($packet['post_id'] ?? 0) ?: null,
            'title' => $packet['title'],
            'status' => $packet['status'],
            'categories' => $packet['categories'],
            'tags' => $packet['tags'],
            'author' => $packet['author'],
            'images' => array_map(static fn ($image) => [
                'key' => $image['key'] ?? null,
                'file_name' => $image['file_name'] ?? basename((string) ($image['path'] ?? '')),
                'featured' => (bool) ($image['featured'] ?? false),
            ], $images),
        ];

        if ($problems !== [] || ! $this->option('confirm')) {
            return $this->emit(['success' => $problems === [], 'preview' => true, 'post' => $summary, 'problems' => $problems]);
        }

        $target = $connections->target($connection);
        $content = (string) $packet['content'];
        $featuredId = null;
        $uploaded = [];
        foreach ($images as $image) {
            $result = $wordpress->uploadMedia(
                $target,
                (string) $image['path'],
                (string) ($image['file_name'] ?? ''),
                (string) ($image['alt'] ?? ''),
                (string) ($image['caption'] ?? ''),
            );
            $mediaId = (int) ($result['media_id'] ?? $result['data']['media_id'] ?? $result['data']['id'] ?? $result['id'] ?? 0);
            $url = (string) ($result['url'] ?? $result['data']['url'] ?? $result['data']['source_url'] ?? $result['source_url'] ?? '');
            if (! ($result['success'] ?? false) || $mediaId <= 0) {
                return $this->emit(['success' => false, 'stage' => 'upload', 'image' => $image['key'], 'uploaded' => $uploaded, 'error' => $result['message'] ?? 'The photo upload failed.']);
            }
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
                $url = $wordpress->attachmentUrl($target, $mediaId);
            }
            if ($url === '') {
                return $this->emit(['success' => false, 'stage' => 'upload', 'image' => $image['key'], 'uploaded' => $uploaded, 'error' => 'The photo was uploaded but its web address could not be read.']);
            }
            $uploaded[] = ['key' => $image['key'], 'media_id' => $mediaId, 'url' => $url];
            if ($image['featured'] ?? false) {
                $featuredId = $mediaId;
            }
            $content = str_replace('{{image:'.$image['key'].'}}', $this->figure($mediaId, $url, (string) ($image['alt'] ?? ''), (string) ($image['caption'] ?? '')), $content);
        }

        $options = array_filter([
            'excerpt' => $packet['excerpt'],
            'slug' => $packet['slug'],
            'author' => $packet['author'],
            'featured_media' => $featuredId,
        ], static fn ($value) => $value !== null && $value !== '');
        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $field => $taxonomy) {
            if ($packet[$field] === []) {
                continue;
            }
            $terms = $wordpress->ensureTerms($target, $packet[$field], $taxonomy);
            if (! ($terms['success'] ?? false)) {
                return $this->emit(['success' => false, 'stage' => $field, 'uploaded' => $uploaded, 'error' => $terms['message'] ?? 'The '.$field.' could not be resolved.']);
            }
            $options[$field] = array_values(array_map('intval', (array) ($terms['term_ids'] ?? [])));
        }

        // A new post is saved as a draft with its featured image first and only
        // then moved to its final status. Featured-image plugins (Featured Image
        // from URL) replaced the featured image when a post was created and
        // published in one write; the same publish as a separate update kept it.
        $postId = (int) ($packet['post_id'] ?? 0);
        $result = $postId > 0
            ? $wordpress->updatePost($target, $postId, $options + ['title' => $packet['title'], 'content' => $content, 'status' => $packet['status']])
            : $wordpress->createPost($target, $packet['title'], $content, 'draft', $options);
        $postId = $postId > 0 ? $postId : (int) ($result['post_id'] ?? $result['data']['post_id'] ?? $result['data']['id'] ?? $result['id'] ?? 0);
        if (($result['success'] ?? false) && ! ((int) ($packet['post_id'] ?? 0) > 0) && $packet['status'] !== 'draft' && $postId > 0) {
            $result = $wordpress->updatePost($target, $postId, array_filter(['status' => $packet['status'], 'featured_media' => $featuredId]));
        }
        if (! ($result['success'] ?? false) || $postId <= 0) {
            return $this->emit(['success' => false, 'stage' => 'post', 'uploaded' => $uploaded, 'post_id' => $postId ?: null, 'error' => $result['message'] ?? 'The post write failed.', 'details' => $result['data'] ?? null]);
        }

        $link = $wordpress->getPublicPostUrl($target, $postId);

        return $this->emit([
            'success' => true,
            'post' => $summary,
            'post_id' => $postId,
            'url' => $link['url'] ?? $link['data']['url'] ?? null,
            'featured_media_id' => $featuredId,
            'uploaded' => $uploaded,
            'categories' => $options['categories'] ?? [],
            'tags' => $options['tags'] ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    private function readPacket(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return ['error' => '--packet must be a readable JSON file.'];
        }
        $packet = json_decode((string) file_get_contents($path), true);
        if (! is_array($packet) || trim((string) ($packet['title'] ?? '')) === '' || trim((string) ($packet['content'] ?? '')) === '') {
            return ['error' => 'The packet needs a title and content.'];
        }
        $status = (string) ($packet['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'pending', 'publish'], true)) {
            return ['error' => 'status must be draft, pending or publish.'];
        }
        $list = static fn ($values) => array_values(array_filter(array_map(static fn ($value) => trim((string) $value), (array) $values)));

        return [
            'post_id' => (int) ($packet['post_id'] ?? 0),
            'title' => trim((string) $packet['title']),
            'content' => (string) $packet['content'],
            'status' => $status,
            'excerpt' => isset($packet['excerpt']) ? (string) $packet['excerpt'] : null,
            'slug' => isset($packet['slug']) ? (string) $packet['slug'] : null,
            'author' => isset($packet['author']) ? (string) $packet['author'] : null,
            'categories' => $list($packet['categories'] ?? []),
            'tags' => $list($packet['tags'] ?? []),
            'images' => (array) ($packet['images'] ?? []),
        ];
    }

    private function figure(int $mediaId, string $url, string $alt, string $caption): string
    {
        $img = '<img class="wp-image-'.$mediaId.'" src="'.e($url).'" alt="'.e($alt).'" />';
        $credit = $caption !== '' ? '<figcaption class="wp-element-caption">'.e($caption).'</figcaption>' : '';

        return '<figure class="wp-block-image size-large">'.$img.$credit.'</figure>';
    }

    private function emit(array $payload): int
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
