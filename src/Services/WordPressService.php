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
        $result = $this->request(
            $siteUrl,
            $username,
            $appPassword,
            'get',
            'users/me',
            query: ['context' => 'edit'],
            timeoutSeconds: 15,
        );
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

    /** @return array{success: bool, message: string, data: array|null} */
    public function signedUploadMedia(
        string $siteUrl,
        string $route,
        string $keyId,
        string $secret,
        string $operationId,
        string $filePath,
        string $fileName = '',
    ): array {
        $artifact = null;

        try {
            $artifact = $this->media->acquire($filePath, $fileName);
            $route = ltrim($route, '/');
            $response = $this->http->hmacUploadImage(
                rtrim($siteUrl, '/').'/wp-json/'.$route,
                '/'.$route,
                $keyId,
                $secret,
                $operationId,
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

            return [
                'success' => true,
                'message' => "Media uploaded through HWS Base Tools: {$artifact->filename} (ID: {$mediaId}).",
                'data' => [
                    'media_id' => $mediaId,
                    'media_url' => isset($payload['source_url']) ? (string) $payload['source_url'] : null,
                    'media_title' => $this->rendered($payload['title'] ?? $artifact->filename),
                ],
            ];
        } catch (MediaPipelineException $exception) {
            $this->logFailure('signedUploadMedia', $siteUrl, $exception, ['error_code' => $exception->errorCode]);

            return ['success' => false, 'message' => $this->mediaFailureMessage($exception), 'data' => null];
        } catch (Throwable $exception) {
            $this->logFailure('signedUploadMedia', $siteUrl, $exception);

            return ['success' => false, 'message' => 'The signed media upload could not be completed securely.', 'data' => null];
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
        return $this->requestRoute($siteUrl, $username, $appPassword, $method, 'wp/v2/'.ltrim($endpoint, '/'), $body, $query, $timeoutSeconds);
    }

    /** Execute one authenticated request in a plugin or core REST namespace. */
    public function requestRoute(
        string $siteUrl,
        string $username,
        string $appPassword,
        string $method,
        string $endpoint,
        array $body = [],
        array $query = [],
        int $timeoutSeconds = 30,
    ): array {
        if (! preg_match('#^/?[A-Za-z0-9_-]+/v[0-9]+/[A-Za-z0-9_/%:.-]+$#D', $endpoint)
            || preg_match('~[\\x00-\\x20\\x7f\\\\\\\\?#]|(?:^|/)\\.\\.(?:/|$)~', rawurldecode($endpoint))) {
            return ['success' => false, 'message' => 'Invalid WordPress REST route.', 'data' => null, 'status' => 400];
        }
        if (trim($siteUrl) === '' || trim($username) === '' || $appPassword === '') {
            return ['success' => false, 'message' => 'REST credentials are incomplete.', 'data' => null, 'status' => null];
        }

        try {
            $response = $this->http->authenticatedJson(
                $method,
                rtrim($siteUrl, '/').'/wp-json/'.ltrim($endpoint, '/'),
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

    /** Execute one HMAC-authenticated HWS Base Tools request. */
    public function signedRequestRoute(
        string $siteUrl,
        string $keyId,
        string $secret,
        string $method,
        string $endpoint,
        array $body = [],
        array $query = [],
        int $timeoutSeconds = 30,
    ): array {
        if (! preg_match('#^/?[A-Za-z0-9_-]+/v[0-9]+/[A-Za-z0-9_/%:.-]+$#D', $endpoint)
            || preg_match('~[\\x00-\\x20\\x7f\\\\\\\\?#]|(?:^|/)\\.\\.(?:/|$)~', rawurldecode($endpoint))) {
            return ['success' => false, 'message' => 'Invalid WordPress REST route.', 'data' => null, 'status' => 400];
        }
        if (trim($siteUrl) === '' || trim($keyId) === '' || $secret === '') {
            return ['success' => false, 'message' => 'HWS Base Tools credentials are incomplete.', 'data' => null, 'status' => null];
        }

        $route = ltrim($endpoint, '/');
        try {
            $response = $this->http->hmacJson(
                $method,
                rtrim($siteUrl, '/').'/wp-json/'.$route,
                '/'.$route,
                $keyId,
                $secret,
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
                'message' => $response->successful() ? 'Signed plugin request succeeded.' : $this->remoteMessage($payload, $response->status),
                'data' => $payload,
                'status' => $response->status,
            ];
        } catch (Throwable $exception) {
            $this->logFailure('signedRequestRoute', $siteUrl, $exception, [
                'endpoint' => substr($route, 0, 160),
                'method' => strtoupper($method),
            ]);

            return [
                'success' => false,
                'message' => $exception instanceof OutboundHttpException && $exception->failureCode() === 'invalid_request'
                    ? 'The signed HWS Base Tools request is invalid or exceeds its security limits.'
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

    /**
     * Read the public WordPress REST index without returning route payloads.
     *
     * @return array{success: bool, message: string, status: int|null, namespaces: array<int, string>, route_count: int}
     */
    public function discoverRestIndex(string $siteUrl): array
    {
        $url = rtrim(trim($siteUrl), '/').'/wp-json/';
        $validatedUrl = $this->validatedPublicUrl($url);
        if ($validatedUrl === null) {
            return [
                'success' => false,
                'message' => 'The WordPress REST index URL is invalid.',
                'status' => null,
                'namespaces' => [],
                'route_count' => 0,
            ];
        }

        try {
            $response = $this->http->publicGet(
                $validatedUrl,
                timeoutSeconds: 15,
                maxResponseBytes: WordPressHttpTransport::MAX_PUBLIC_DOCUMENT_BYTES,
                maxRedirects: 0,
            );
            $payload = $this->decodeResponse($response);
            if (! $response->successful() || $payload === null) {
                return [
                    'success' => false,
                    'message' => $response->successful()
                        ? 'WordPress returned a malformed REST index.'
                        : 'WordPress REST index returned HTTP '.$response->status.'.',
                    'status' => $response->status,
                    'namespaces' => [],
                    'route_count' => 0,
                ];
            }

            $namespaces = array_values(array_unique(array_filter(array_map(
                static fn (mixed $namespace): string => is_string($namespace)
                    && preg_match('#^[A-Za-z0-9_-]+/v[0-9]+$#D', $namespace) === 1
                        ? $namespace
                        : '',
                (array) ($payload['namespaces'] ?? []),
            ))));
            sort($namespaces);

            return [
                'success' => true,
                'message' => 'WordPress REST index discovered.',
                'status' => $response->status,
                'namespaces' => $namespaces,
                'route_count' => count(array_filter((array) ($payload['routes'] ?? []), 'is_array')),
            ];
        } catch (Throwable $exception) {
            $this->logFailure('discoverRestIndex', $siteUrl, $exception);

            return [
                'success' => false,
                'message' => 'The WordPress REST index could not be reached securely.',
                'status' => null,
                'namespaces' => [],
                'route_count' => 0,
            ];
        }
    }

    /**
     * Read the public SMP publication manifest without coupling it to the
     * selected WordPress authentication or hosting transport.
     *
     * @return array{success: bool, message: string, status: int|null, state: string, data: array|null}
     */
    public function discoverPublicationManifest(string $siteUrl): array
    {
        $url = rtrim(trim($siteUrl), '/').'/wp-json/smpi/v1/publication-manifest';
        $validatedUrl = $this->validatedPublicUrl($url);
        if ($validatedUrl === null) {
            return [
                'success' => false,
                'message' => 'The SMP publication manifest URL is invalid.',
                'status' => null,
                'state' => 'invalid_url',
                'data' => null,
            ];
        }

        try {
            $response = $this->http->publicGet(
                $validatedUrl,
                ['_publish_connection_check' => 1],
                ['Accept' => 'application/json'],
                20,
                4 * 1024 * 1024,
                4,
            );
            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => $response->status === 404
                        ? 'SMP Publication Integration manifest was not detected.'
                        : 'SMP publication manifest returned HTTP '.$response->status.'.',
                    'status' => $response->status,
                    'state' => $response->status === 404 ? 'not_detected' : 'http_error',
                    'data' => null,
                ];
            }

            $contentType = strtolower(implode(', ', $response->headerValues('content-type')));
            $payload = $response->json();
            if ((! str_contains($contentType, 'application/json') && ! str_contains($contentType, '+json'))
                || ! is_array($payload)
                || array_is_list($payload)) {
                return [
                    'success' => false,
                    'message' => 'SMP publication manifest returned an invalid JSON document.',
                    'status' => $response->status,
                    'state' => 'invalid_payload',
                    'data' => null,
                ];
            }

            return [
                'success' => true,
                'message' => 'SMP publication manifest discovered.',
                'status' => $response->status,
                'state' => 'available',
                'data' => $payload,
            ];
        } catch (Throwable $exception) {
            $this->logFailure('discoverPublicationManifest', $siteUrl, $exception);

            return [
                'success' => false,
                'message' => 'The SMP publication manifest could not be reached securely.',
                'status' => null,
                'state' => 'transport_error',
                'data' => null,
            ];
        }
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
