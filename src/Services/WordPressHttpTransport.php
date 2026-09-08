<?php

namespace hexa_package_wordpress\Services;

use Closure;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundHttpTarget;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Security\Http\UnsafeOutboundUrl;
use Throwable;

final class WordPressHttpTransport
{
    public const MAX_REST_RESPONSE_BYTES = 8 * 1024 * 1024;

    public const MAX_PUBLIC_DOCUMENT_BYTES = 2 * 1024 * 1024;

    public const MAX_IMAGE_BYTES = 16 * 1024 * 1024;

    private const MAX_UPLOAD_RESPONSE_BYTES = 2 * 1024 * 1024;

    private const MAX_RESPONSE_HEADER_BYTES = 64 * 1024;

    private const IMAGE_MIME_TYPES = [
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @param  null|Closure(OutboundHttpRequest, string, int): OutboundHttpResponse  $streamTransport
     */
    public function __construct(
        private readonly SafeOutboundHttpClient $client,
        private readonly OutboundUrlGuard $guard,
        private readonly ?Closure $streamTransport = null,
    ) {}

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $body
     * @param  array<string, scalar|array<array-key, scalar>|null>  $query
     */
    public function authenticatedJson(
        string $method,
        string $url,
        string $username,
        string $applicationPassword,
        array $body = [],
        array $query = [],
        int $timeoutSeconds = 30,
    ): OutboundHttpResponse {
        $this->assertSecureAuthenticatedTarget($url, $username, $applicationPassword);

        $encodedBody = null;
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($username.':'.$applicationPassword),
        ];

        if ($body !== [] && strtoupper($method) !== 'GET') {
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }

        return $this->client->request($method, $this->withQuery($url, $query), [
            'headers' => $headers,
            'body' => $encodedBody,
            'timeout' => $timeoutSeconds,
            'long_running' => $timeoutSeconds > 60,
            'max_bytes' => self::MAX_REST_RESPONSE_BYTES,
            'max_redirects' => 0,
        ]);
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $query
     * @param  array<string, scalar>  $headers
     */
    public function publicGet(
        string $url,
        array $query = [],
        array $headers = [],
        int $timeoutSeconds = 15,
        int $maxResponseBytes = self::MAX_PUBLIC_DOCUMENT_BYTES,
        int $maxRedirects = 4,
    ): OutboundHttpResponse {
        return $this->client->request('GET', $this->withQuery($url, $query), [
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
            'max_bytes' => min($maxResponseBytes, self::MAX_IMAGE_BYTES),
            'max_redirects' => $maxRedirects,
        ]);
    }

    public function validatedPublicUrl(string $url): ?string
    {
        try {
            return $this->guard->resolveTarget(trim($url))->url;
        } catch (Throwable) {
            return null;
        }
    }

    public function uploadImage(
        string $url,
        string $username,
        string $applicationPassword,
        string $path,
        string $filename,
        string $mimeType,
        int $bytes,
    ): OutboundHttpResponse {
        $this->assertSecureAuthenticatedTarget($url, $username, $applicationPassword);
        if (
            $bytes <= 0
            || $bytes > self::MAX_IMAGE_BYTES
            || ! in_array(strtolower($mimeType), self::IMAGE_MIME_TYPES, true)
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            throw new OutboundHttpException('invalid_request');
        }

        $actualBytes = filesize($path);
        if (! is_int($actualBytes) || $actualBytes !== $bytes) {
            throw new OutboundHttpException('invalid_request');
        }

        $filename = $this->safeFilename($filename);
        $target = $this->resolveUploadTarget($url);
        $request = new OutboundHttpRequest(
            method: 'POST',
            target: $target,
            headers: [
                'Accept' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($username.':'.$applicationPassword),
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Type' => $mimeType,
            ],
            body: null,
            timeoutSeconds: 60,
            maxResponseBytes: self::MAX_UPLOAD_RESPONSE_BYTES,
        );

        if ($this->streamTransport !== null) {
            try {
                $response = ($this->streamTransport)($request, $path, $bytes);
            } catch (OutboundHttpException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new OutboundHttpException('transport_failed');
            }

            if (! $response instanceof OutboundHttpResponse) {
                throw new OutboundHttpException('invalid_response');
            }
            if (strlen($response->body) > $request->maxResponseBytes) {
                throw new OutboundHttpException('response_too_large');
            }

            return $response;
        }

        return $this->streamUpload($request, $path, $bytes);
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $query
     */
    private function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($encoded === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$encoded;
    }

    private function assertSecureAuthenticatedTarget(string $url, string $username, string $applicationPassword): void
    {
        if (
            strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || trim($username) === ''
            || $applicationPassword === ''
            || strlen($username) > 512
            || strlen($applicationPassword) > 1024
        ) {
            throw new OutboundHttpException('invalid_request');
        }
    }

    private function resolveUploadTarget(string $url): OutboundHttpTarget
    {
        try {
            return $this->guard->resolveTarget($url);
        } catch (UnsafeOutboundUrl) {
            throw new OutboundHttpException('target_rejected');
        }
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: '';
        $filename = trim($filename, '.-_');

        return substr($filename !== '' ? $filename : 'wordpress-media', 0, 190);
    }

    private function streamUpload(OutboundHttpRequest $request, string $path, int $bytes): OutboundHttpResponse
    {
        if (! function_exists('curl_init')) {
            throw new OutboundHttpException('transport_unavailable');
        }

        $source = @fopen($path, 'rb');
        if ($source === false) {
            throw new OutboundHttpException('invalid_request');
        }
        $sourceStat = fstat($source);
        if (! is_array($sourceStat) || (int) ($sourceStat['size'] ?? -1) !== $bytes) {
            fclose($source);

            throw new OutboundHttpException('invalid_request');
        }

        $handle = curl_init();
        if ($handle === false) {
            fclose($source);

            throw new OutboundHttpException('transport_unavailable');
        }

        $body = '';
        $headers = [];
        $bodyTooLarge = false;
        $headersTooLarge = false;
        $headerBytes = 0;
        $options = $request->curlOptions();
        $options[CURLOPT_UPLOAD] = true;
        $options[CURLOPT_INFILE] = $source;
        $options[CURLOPT_INFILESIZE] = $bytes;
        $options[CURLOPT_WRITEFUNCTION] = static function ($curl, string $chunk) use (&$body, &$bodyTooLarge, $request): int {
            $length = strlen($chunk);
            if (strlen($body) + $length > $request->maxResponseBytes) {
                $bodyTooLarge = true;

                return 0;
            }

            $body .= $chunk;

            return $length;
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (
            &$headers,
            &$bodyTooLarge,
            &$headersTooLarge,
            &$headerBytes,
            $request,
        ): int {
            $length = strlen($line);
            $headerBytes += $length;
            if ($headerBytes > self::MAX_RESPONSE_HEADER_BYTES) {
                $headersTooLarge = true;

                return 0;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with(strtoupper($trimmed), 'HTTP/')) {
                if (str_starts_with(strtoupper($trimmed), 'HTTP/')) {
                    $headers = [];
                }

                return $length;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                return $length;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name === '' || preg_match("/\A[!#$%&'*+.^_`|~0-9a-z-]+\z/D", $name) !== 1) {
                return $length;
            }

            $headers[$name][] = $value;
            if ($name === 'content-length' && ctype_digit($value) && (int) $value > $request->maxResponseBytes) {
                $bodyTooLarge = true;

                return 0;
            }

            return $length;
        };

        try {
            if (! curl_setopt_array($handle, $options)) {
                throw new OutboundHttpException('transport_failed');
            }

            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } catch (OutboundHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OutboundHttpException('transport_failed');
        } finally {
            curl_close($handle);
            fclose($source);
        }

        if ($headersTooLarge) {
            throw new OutboundHttpException('response_headers_too_large');
        }
        if ($bodyTooLarge) {
            throw new OutboundHttpException('response_too_large');
        }
        if ($result === false || $status < 100 || $status > 599) {
            throw new OutboundHttpException('transport_failed');
        }

        return new OutboundHttpResponse($status, $headers, $body, 0, $request->target->url);
    }
}
