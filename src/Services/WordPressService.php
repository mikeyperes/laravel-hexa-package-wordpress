<?php

namespace hexa_package_wordpress\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressService
{
    /**
     * Test connection to a WordPress site via REST API.
     *
     * @param string $siteUrl Base URL of the WordPress site.
     * @param string $username WordPress username.
     * @param string $appPassword WordPress application password.
     * @return array{success: bool, message: string, data: array|null}
     */
    public function testConnection(string $siteUrl, string $username, string $appPassword): array
    {
        $endpoint = rtrim($siteUrl, '/') . '/wp-json/wp/v2/users/me';

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                $user = $response->json();
                return [
                    'success' => true,
                    'message' => "Connected as '{$user['name']}' (ID: {$user['id']}).",
                    'data' => [
                        'user_id' => $user['id'],
                        'user_name' => $user['name'],
                        'user_slug' => $user['slug'] ?? null,
                        'roles' => $user['roles'] ?? [],
                    ],
                ];
            }

            if ($response->status() === 401) {
                return ['success' => false, 'message' => 'Authentication failed. Check username and application password.', 'data' => null];
            }

            if ($response->status() === 403) {
                return ['success' => false, 'message' => 'Access forbidden. The user may not have sufficient permissions.', 'data' => null];
            }

            if ($response->status() === 404) {
                return ['success' => false, 'message' => 'REST API not found. Verify the site URL and that REST API is enabled.', 'data' => null];
            }

            return ['success' => false, 'message' => "Unexpected response: HTTP {$response->status()}", 'data' => null];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['success' => false, 'message' => "Connection failed: could not reach {$siteUrl}. Check the URL.", 'data' => null];
        } catch (\Exception $e) {
            Log::error('WordPressService::testConnection error', ['url' => $siteUrl, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Create a post on a WordPress site.
     *
     * @param string $siteUrl Base URL of the WordPress site.
     * @param string $username WordPress username.
     * @param string $appPassword WordPress application password.
     * @param array $postData Post data: title, content, status, excerpt, categories, tags, featured_media.
     * @return array{success: bool, message: string, data: array|null}
     */
    public function createPost(string $siteUrl, string $username, string $appPassword, array $postData): array
    {
        $endpoint = rtrim($siteUrl, '/') . '/wp-json/wp/v2/posts';

        $payload = [
            'title' => $postData['title'] ?? '',
            'content' => $postData['content'] ?? '',
            'status' => $postData['status'] ?? 'draft',
        ];

        if (!empty($postData['excerpt'])) {
            $payload['excerpt'] = $postData['excerpt'];
        }

        if (!empty($postData['categories'])) {
            $payload['categories'] = $postData['categories'];
        }

        if (!empty($postData['tags'])) {
            $payload['tags'] = $postData['tags'];
        }

        if (!empty($postData['featured_media'])) {
            $payload['featured_media'] = $postData['featured_media'];
        }

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(30)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $post = $response->json();
                return [
                    'success' => true,
                    'message' => "Post created: '{$post['title']['rendered']}' (ID: {$post['id']}).",
                    'data' => [
                        'post_id' => $post['id'],
                        'post_url' => $post['link'] ?? null,
                        'post_status' => $post['status'],
                        'post_title' => $post['title']['rendered'] ?? '',
                    ],
                ];
            }

            $error = $response->json();
            $errorMsg = $error['message'] ?? "HTTP {$response->status()}";
            return ['success' => false, 'message' => "WordPress error: {$errorMsg}", 'data' => null];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['success' => false, 'message' => "Connection failed: could not reach {$siteUrl}.", 'data' => null];
        } catch (\Exception $e) {
            Log::error('WordPressService::createPost error', ['url' => $siteUrl, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Update an existing post on a WordPress site.
     *
     * @param string $siteUrl Base URL of the WordPress site.
     * @param string $username WordPress username.
     * @param string $appPassword WordPress application password.
     * @param int $postId WordPress post ID.
     * @param array $postData Fields to update.
     * @return array{success: bool, message: string, data: array|null}
     */
    public function updatePost(string $siteUrl, string $username, string $appPassword, int $postId, array $postData): array
    {
        $endpoint = rtrim($siteUrl, '/') . "/wp-json/wp/v2/posts/{$postId}";

        $payload = [];
        foreach (['title', 'content', 'status', 'excerpt', 'date'] as $field) {
            if (isset($postData[$field])) {
                $payload[$field] = $postData[$field];
            }
        }

        if (!empty($postData['categories'])) {
            $payload['categories'] = $postData['categories'];
        }

        if (!empty($postData['tags'])) {
            $payload['tags'] = $postData['tags'];
        }

        if (!empty($postData['featured_media'])) {
            $payload['featured_media'] = $postData['featured_media'];
        }

        if (!empty($postData['author']) && is_numeric($postData['author'])) {
            $payload['author'] = (int) $postData['author'];
        }

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(30)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $post = $response->json();
                return [
                    'success' => true,
                    'message' => "Post updated (ID: {$post['id']}).",
                    'data' => [
                        'post_id' => $post['id'],
                        'post_url' => $post['link'] ?? null,
                        'post_status' => $post['status'] ?? null,
                        'post_title' => $post['title']['rendered'] ?? '',
                        'post_date' => $post['date'] ?? null,
                    ],
                ];
            }

            $error = $response->json();
            $errorMsg = $error['message'] ?? "HTTP {$response->status()}";
            return ['success' => false, 'message' => "WordPress error: {$errorMsg}", 'data' => null];

        } catch (\Exception $e) {
            Log::error('WordPressService::updatePost error', ['url' => $siteUrl, 'postId' => $postId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Fetch an existing post from a WordPress site.
     *
     * @param string $siteUrl
     * @param string $username
     * @param string $appPassword
     * @param int $postId
     * @return array{success: bool, message: string, data: array|null}
     */
    public function getPost(string $siteUrl, string $username, string $appPassword, int $postId): array
    {
        $endpoint = rtrim($siteUrl, '/') . "/wp-json/wp/v2/posts/{$postId}?context=edit";

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(30)
                ->get($endpoint);

            if ($response->successful()) {
                $post = $response->json();
                return [
                    'success' => true,
                    'message' => "Post fetched (ID: {$post['id']}).",
                    'data' => [
                        'post_id' => $post['id'],
                        'post_url' => $post['link'] ?? null,
                        'post_status' => $post['status'] ?? null,
                        'post_title' => $post['title']['rendered'] ?? '',
                        'post_date' => $post['date'] ?? null,
                    ],
                ];
            }

            $error = $response->json();
            $errorMsg = $error['message'] ?? "HTTP {$response->status()}";
            return ['success' => false, 'message' => "WordPress error: {$errorMsg}", 'data' => null];

        } catch (\Exception $e) {
            Log::error('WordPressService::getPost error', ['url' => $siteUrl, 'postId' => $postId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Upload media (image) to a WordPress site.
     *
     * @param string $siteUrl Base URL of the WordPress site.
     * @param string $username WordPress username.
     * @param string $appPassword WordPress application password.
     * @param string $filePath Local file path or URL of the image.
     * @param string $fileName Desired filename on WordPress.
     * @param string $altText Alt text for the image.
     * @return array{success: bool, message: string, data: array|null}
     */
    public function uploadMedia(string $siteUrl, string $username, string $appPassword, string $filePath, string $fileName = '', string $altText = ''): array
    {
        $endpoint = rtrim($siteUrl, '/') . '/wp-json/wp/v2/media';

        try {
            // If $filePath is a URL, download it first
            if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                $imageResponse = Http::timeout(30)->get($filePath);
                if (!$imageResponse->successful()) {
                    return ['success' => false, 'message' => "Failed to download image from {$filePath}.", 'data' => null];
                }
                $imageContent = $imageResponse->body();
                $contentType = $imageResponse->header('Content-Type') ?: 'image/jpeg';
                if (!$fileName) {
                    $fileName = basename(parse_url($filePath, PHP_URL_PATH)) ?: 'image.jpg';
                }
            } else {
                if (!file_exists($filePath)) {
                    return ['success' => false, 'message' => "File not found: {$filePath}", 'data' => null];
                }
                $imageContent = file_get_contents($filePath);
                $contentType = mime_content_type($filePath) ?: 'image/jpeg';
                if (!$fileName) {
                    $fileName = basename($filePath);
                }
            }

            $response = Http::withBasicAuth($username, $appPassword)
                ->withHeaders([
                    'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                    'Content-Type' => $contentType,
                ])
                ->timeout(60)
                ->withBody($imageContent, $contentType)
                ->post($endpoint);

            if ($response->successful()) {
                $media = $response->json();

                // Set alt text if provided
                if ($altText && isset($media['id'])) {
                    $this->updateMediaAltText($siteUrl, $username, $appPassword, $media['id'], $altText);
                }

                return [
                    'success' => true,
                    'message' => "Media uploaded: {$fileName} (ID: {$media['id']}).",
                    'data' => [
                        'media_id' => $media['id'],
                        'media_url' => $media['source_url'] ?? null,
                        'media_title' => $media['title']['rendered'] ?? $fileName,
                    ],
                ];
            }

            $error = $response->json();
            $errorMsg = $error['message'] ?? "HTTP {$response->status()}";
            return ['success' => false, 'message' => "WordPress error: {$errorMsg}", 'data' => null];

        } catch (\Exception $e) {
            Log::error('WordPressService::uploadMedia error', ['url' => $siteUrl, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Update alt text on an uploaded media item.
     *
     * @param string $siteUrl
     * @param string $username
     * @param string $appPassword
     * @param int $mediaId
     * @param string $altText
     * @return void
     */
    private function updateMediaAltText(string $siteUrl, string $username, string $appPassword, int $mediaId, string $altText): void
    {
        $endpoint = rtrim($siteUrl, '/') . "/wp-json/wp/v2/media/{$mediaId}";

        try {
            Http::withBasicAuth($username, $appPassword)
                ->timeout(15)
                ->post($endpoint, ['alt_text' => $altText]);
        } catch (\Exception $e) {
            Log::warning('WordPressService::updateMediaAltText failed', ['mediaId' => $mediaId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get categories from a WordPress site.
     *
     * @param string $siteUrl
     * @param string $username
     * @param string $appPassword
     * @return array{success: bool, message: string, data: array|null}
     */
    public function getCategories(string $siteUrl, string $username, string $appPassword): array
    {
        $endpoint = rtrim($siteUrl, '/') . '/wp-json/wp/v2/categories?per_page=100';

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                $categories = collect($response->json())->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'slug' => $c['slug'],
                    'count' => $c['count'],
                ])->toArray();

                return ['success' => true, 'message' => count($categories) . ' categories found.', 'data' => $categories];
            }

            return ['success' => false, 'message' => "HTTP {$response->status()}", 'data' => null];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Get tags from a WordPress site.
     *
     * @param string $siteUrl
     * @param string $username
     * @param string $appPassword
     * @return array{success: bool, message: string, data: array|null}
     */
    public function getTags(string $siteUrl, string $username, string $appPassword): array
    {
        $endpoint = rtrim($siteUrl, '/') . '/wp-json/wp/v2/tags?per_page=100';

        try {
            $response = Http::withBasicAuth($username, $appPassword)
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                $tags = collect($response->json())->map(fn($t) => [
                    'id' => $t['id'],
                    'name' => $t['name'],
                    'slug' => $t['slug'],
                    'count' => $t['count'],
                ])->toArray();

                return ['success' => true, 'message' => count($tags) . ' tags found.', 'data' => $tags];
            }

            return ['success' => false, 'message' => "HTTP {$response->status()}", 'data' => null];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }
}
