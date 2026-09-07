<?php

namespace hexa_package_wordpress\Services;

use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_package_media\Data\MediaArtifact;
use hexa_package_media\Exceptions\MediaPipelineException;
use Illuminate\Support\Facades\Log;
use Throwable;

class WordPressService
{
    public function __construct(
        private readonly WordPressHttpTransport $http,
        private readonly WordPressMediaSourceService $media,
    ) {}

    /** @return array{success: bool, message: string, data: array|null} */
    public function testConnection(string $siteUrl, string $username, string $appPassword): array
    {
        $result = $this->request($siteUrl, $username, $appPassword, 'get', 'users/me', timeoutSeconds: 15);
        if ($result['success']) {
            $user = (array) $result['data'];
            $id = (int) ($user['id'] ?? 0);
            $name = trim((string) ($user['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                return ['success' => false, 'message' => 'WordPress returned an invalid account response.', 'data' => null];
            }

            return [
                'success' => true,
                'message' => "Connected as '{$name}' (ID: {$id}).",
                'data' => [
                    'user_id' => $id,
                    'user_name' => $name,
                    'user_slug' => isset($user['slug']) ? (string) $user['slug'] : null,
                    'roles' => array_values(array_filter((array) ($user['roles'] ?? []), 'is_string')),
                ],
            ];
        }

        return match ($result['status']) {
            401 => ['success' => false, 'message' => 'Authentication failed. Check username and application password.', 'data' => null],
            403 => ['success' => false, 'message' => 'Access forbidden. The user may not have sufficient permissions.', 'data' => null],
            404 => ['success' => false, 'message' => 'REST API not found. Verify the site URL and that REST API is enabled.', 'data' => null],
            default => ['success' => false, 'message' => (string) $result['message'], 'data' => null],
        };
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function createPost(string $siteUrl, string $username, string $appPassword, array $postData): array
    {
        $payload = [
            'title' => $postData['title'] ?? '',
            'content' => $postData['content'] ?? '',
            'status' => $postData['status'] ?? 'draft',
        ];

        foreach (['excerpt', 'categories', 'tags', 'featured_media'] as $field) {
            if (! empty($postData[$field])) {
                $payload[$field] = $postData[$field];
            }
        }

        $result = $this->request($siteUrl, $username, $appPassword, 'post', 'posts', $payload);
        if (! $result['success']) {
            return ['success' => false, 'message' => 'WordPress error: '.$result['message'], 'data' => null];
        }

        $post = (array) $result['data'];
        $postId = (int) ($post['id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'message' => 'WordPress returned an invalid post response.', 'data' => null];
        }

        return [
            'success' => true,
            'message' => 'Post created: \''.$this->rendered($post['title'] ?? '')."' (ID: {$postId}).",
            'data' => [
                'post_id' => $postId,
                'post_url' => isset($post['link']) ? (string) $post['link'] : null,
                'post_status' => (string) ($post['status'] ?? ''),
                'post_title' => $this->rendered($post['title'] ?? ''),
            ],
        ];
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function updatePost(string $siteUrl, string $username, string $appPassword, int $postId, array $postData): array
    {
        $payload = [];
        foreach (['title', 'content', 'status', 'excerpt', 'date'] as $field) {
            if (isset($postData[$field])) {
                $payload[$field] = $postData[$field];
            }
        }
        foreach (['categories', 'tags', 'featured_media'] as $field) {
            if (! empty($postData[$field])) {
                $payload[$field] = $postData[$field];
            }
        }
        if (! empty($postData['author']) && is_numeric($postData['author'])) {
            $payload['author'] = (int) $postData['author'];
        }

        $result = $this->request($siteUrl, $username, $appPassword, 'post', 'posts/'.$postId, $payload);
        if (! $result['success']) {
            return ['success' => false, 'message' => 'WordPress error: '.$result['message'], 'data' => null];
        }

        $post = (array) $result['data'];
        $resolvedId = (int) ($post['id'] ?? 0);
        if ($resolvedId <= 0) {
            return ['success' => false, 'message' => 'WordPress returned an invalid post response.', 'data' => null];
        }

        return [
            'success' => true,
            'message' => "Post updated (ID: {$resolvedId}).",
            'data' => [
                'post_id' => $resolvedId,
                'post_url' => isset($post['link']) ? (string) $post['link'] : null,
                'post_status' => isset($post['status']) ? (string) $post['status'] : null,
                'post_title' => $this->rendered($post['title'] ?? ''),
                'post_date' => isset($post['date']) ? (string) $post['date'] : null,
            ],
        ];
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function getPost(string $siteUrl, string $username, string $appPassword, int $postId): array
    {
        $result = $this->request(
            $siteUrl,
            $username,
            $appPassword,
            'get',
            'posts/'.$postId,
            query: ['context' => 'edit'],
        );
        if (! $result['success']) {
            return ['success' => false, 'message' => 'WordPress error: '.$result['message'], 'data' => null];
        }

        $post = (array) $result['data'];
        $resolvedId = (int) ($post['id'] ?? 0);
        if ($resolvedId <= 0) {
            return ['success' => false, 'message' => 'WordPress returned an invalid post response.', 'data' => null];
        }

        return [
            'success' => true,
            'message' => "Post fetched (ID: {$resolvedId}).",
            'data' => [
                'post_id' => $resolvedId,
                'post_url' => isset($post['link']) ? (string) $post['link'] : null,
                'post_status' => isset($post['status']) ? (string) $post['status'] : null,
                'post_title' => $this->rendered($post['title'] ?? ''),
                'post_date' => isset($post['date']) ? (string) $post['date'] : null,
            ],
        ];
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function uploadMedia(
        string $siteUrl,
        string $username,
        string $appPassword,
        string $filePath,
        string $fileName = '',
        string $altText = '',
    ): array {
        $artifact = null;

        try {
            $artifact = $this->media->acquire($filePath, $fileName);
            $response = $this->http->uploadImage(
                $this->endpoint($siteUrl, 'media'),
                $username,
                $appPassword,
                $artifact->path,
                $artifact->filename,
                $artifact->mimeType,
                $artifact->bytes,
            );
            $payload = $this->decodeResponse($response);
            if (! $response->successful()) {
                return ['success' => false, 'message' => 'WordPress error: '.$this->remoteMessage($payload, $response->status), 'data' => null];
            }

            $mediaId = (int) ($payload['id'] ?? 0);
            if ($mediaId <= 0) {
                return ['success' => false, 'message' => 'WordPress returned an invalid media response.', 'data' => null];
            }
            if ($altText !== '') {
                $this->updateMediaAltText($siteUrl, $username, $appPassword, $mediaId, $altText);
            }

            return [
                'success' => true,
                'message' => "Media uploaded: {$artifact->filename} (ID: {$mediaId}).",
                'data' => [
                    'media_id' => $mediaId,
                    'media_url' => isset($payload['source_url']) ? (string) $payload['source_url'] : null,
                    'media_title' => $this->rendered($payload['title'] ?? $artifact->filename),
                ],
            ];
        } catch (MediaPipelineException $exception) {
            $this->logFailure('uploadMedia', $siteUrl, $exception, ['error_code' => $exception->errorCode]);

            return ['success' => false, 'message' => $this->mediaFailureMessage($exception), 'data' => null];
        } catch (Throwable $exception) {
            $this->logFailure('uploadMedia', $siteUrl, $exception);

            return ['success' => false, 'message' => 'The media upload could not be completed securely.', 'data' => null];
        } finally {
            if ($artifact instanceof MediaArtifact) {
                $artifact->release();
            }
        }
    }

    /**
     * Shared authenticated REST boundary for WordPressManagerService.
     *
     * @return array{success: bool, message: string, data: array|null, status: int|null}
     */
    public function request(
        string $siteUrl,
        string $username,
        string $appPassword,
        string $method,
        string $endpoint,
        array $body = [],
        array $query = [],
        int $timeoutSeconds = 30,
    ): array {
        if (trim($siteUrl) === '' || trim($username) === '' || $appPassword === '') {
            return ['success' => false, 'message' => 'REST credentials are incomplete.', 'data' => null, 'status' => null];
        }

        try {
            $response = $this->http->authenticatedJson(
                $method,
                $this->endpoint($siteUrl, $endpoint),
                $username,
                $appPassword,
                $body,
                $query,
                $timeoutSeconds,
            );
            $payload = $this->decodeResponse($response);
            if ($response->successful() && $payload === null) {
                return ['success' => false, 'message' => 'WordPress returned malformed JSON.', 'data' => null, 'status' => $response->status];
            }

            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'REST request succeeded.' : $this->remoteMessage($payload, $response->status),
                'data' => $payload,
                'status' => $response->status,
            ];
        } catch (Throwable $exception) {
            $this->logFailure('request', $siteUrl, $exception, [
                'endpoint' => substr(ltrim($endpoint, '/'), 0, 160),
                'method' => strtoupper($method),
            ]);

            return [
                'success' => false,
                'message' => $exception instanceof OutboundHttpException && $exception->failureCode() === 'invalid_request'
                    ? 'The WordPress request is invalid or exceeds its security limits.'
                    : 'The WordPress site could not be reached securely.',
                'data' => null,
                'status' => null,
            ];
        }
    }

    public function publicGet(
        string $url,
        array $query = [],
        array $headers = [],
        int $timeoutSeconds = 15,
        int $maxResponseBytes = WordPressHttpTransport::MAX_PUBLIC_DOCUMENT_BYTES,
        int $maxRedirects = 4,
    ): OutboundHttpResponse {
        return $this->http->publicGet($url, $query, $headers, $timeoutSeconds, $maxResponseBytes, $maxRedirects);
    }

    public function validatedPublicUrl(string $url): ?string
    {
        return $this->http->validatedPublicUrl($url);
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function getCategories(string $siteUrl, string $username, string $appPassword): array
    {
        return $this->getTerms($siteUrl, $username, $appPassword, 'categories');
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function getTags(string $siteUrl, string $username, string $appPassword): array
    {
        return $this->getTerms($siteUrl, $username, $appPassword, 'tags');
    }

    private function updateMediaAltText(string $siteUrl, string $username, string $appPassword, int $mediaId, string $altText): void
    {
        $result = $this->request($siteUrl, $username, $appPassword, 'post', 'media/'.$mediaId, ['alt_text' => $altText], timeoutSeconds: 15);
        if (! $result['success']) {
            Log::warning('WordPress media alt-text update failed', ['media_id' => $mediaId, 'status' => $result['status']]);
        }
    }

    /** @return array{success: bool, message: string, data: array|null} */
    private function getTerms(string $siteUrl, string $username, string $appPassword, string $endpoint): array
    {
        $result = $this->request(
            $siteUrl,
            $username,
            $appPassword,
            'get',
            $endpoint,
            query: ['per_page' => 100],
            timeoutSeconds: 15,
        );
        if (! $result['success']) {
            return ['success' => false, 'message' => (string) $result['message'], 'data' => null];
        }

        $terms = array_values(array_map(
            static fn (array $term): array => [
                'id' => (int) ($term['id'] ?? 0),
                'name' => (string) ($term['name'] ?? ''),
                'slug' => (string) ($term['slug'] ?? ''),
                'count' => (int) ($term['count'] ?? 0),
            ],
            array_filter((array) $result['data'], 'is_array'),
        ));

        return ['success' => true, 'message' => count($terms).' '.$endpoint.' found.', 'data' => $terms];
    }

    private function endpoint(string $siteUrl, string $endpoint): string
    {
        $siteUrl = trim($siteUrl);
        $parts = parse_url($siteUrl);
        if (
            ! is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new OutboundHttpException('invalid_request');
        }

        return rtrim($siteUrl, '/').'/wp-json/wp/v2/'.ltrim($endpoint, '/');
    }

    /** @return array<string, mixed>|null */
    private function decodeResponse(OutboundHttpResponse $response): ?array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    private function remoteMessage(?array $payload, int $status): string
    {
        $message = is_string($payload['message'] ?? null) ? (string) $payload['message'] : '';
        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags($message)));
        if ($message === '') {
            return 'HTTP '.$status;
        }

        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function rendered(mixed $value): string
    {
        return is_array($value) ? (string) ($value['rendered'] ?? $value['raw'] ?? '') : (string) $value;
    }

    private function mediaFailureMessage(MediaPipelineException $exception): string
    {
        return match ($exception->errorCode) {
            'media_file_unreadable' => 'The media file is missing or unreadable.',
            'media_file_empty', 'media_contents_empty' => 'The media file is empty.',
            'media_file_too_large' => 'The media file exceeds the 16 MB upload limit.',
            'unsupported_image_bytes' => 'The media file is not a supported image.',
            'image_dimensions_too_large' => 'The image dimensions exceed the upload limit.',
            'image_fully_transparent' => 'The image contains no visible pixels.',
            'remote_media_http_error' => 'The remote image server rejected the download.',
            default => 'The media file could not be prepared securely.',
        };
    }

    private function logFailure(string $operation, string $siteUrl, Throwable $exception, array $context = []): void
    {
        Log::warning('WordPress outbound operation failed', array_merge($context, [
            'operation' => $operation,
            'host' => strtolower((string) parse_url($siteUrl, PHP_URL_HOST)),
            'failure' => $exception instanceof OutboundHttpException ? $exception->failureCode() : $exception::class,
        ]));
    }
}
