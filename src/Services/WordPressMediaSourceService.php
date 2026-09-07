<?php

namespace hexa_package_wordpress\Services;

use hexa_package_media\Data\MediaArtifact;
use hexa_package_media\Exceptions\MediaPipelineException;
use hexa_package_media\Inspection\ImageInspector;
use hexa_package_media\Support\MediaFilename;
use hexa_package_media\Transfer\TemporaryMediaResourceManager;

final class WordPressMediaSourceService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    private const MAX_PIXELS = 64_000_000;

    public function __construct(
        private readonly WordPressHttpTransport $http,
        private readonly ImageInspector $inspector,
        private readonly TemporaryMediaResourceManager $temporary,
    ) {}

    public function acquire(string $pathOrUrl, string $requestedFilename = ''): MediaArtifact
    {
        if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {
            return $this->acquireRemote($pathOrUrl, $requestedFilename);
        }

        return $this->acquireLocal($pathOrUrl, $requestedFilename);
    }

    private function acquireRemote(string $url, string $requestedFilename): MediaArtifact
    {
        $response = $this->http->publicGet(
            $url,
            headers: [
                'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9',
                'User-Agent' => 'Hexa WordPress Media Fetcher/2.0',
            ],
            timeoutSeconds: 30,
            maxResponseBytes: WordPressHttpTransport::MAX_IMAGE_BYTES,
            maxRedirects: 4,
        );

        if (! $response->successful()) {
            throw new MediaPipelineException(
                'The remote image server rejected the download.',
                'remote_media_http_error',
                true,
                ['status' => $response->status],
            );
        }

        $path = $this->temporary->write($response->body, [
            'max_bytes' => WordPressHttpTransport::MAX_IMAGE_BYTES,
        ]);

        try {
            return $this->artifact($path, $requestedFilename ?: $this->filenameFromUrl($url), $url, true);
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }

    private function acquireLocal(string $path, string $requestedFilename): MediaArtifact
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new MediaPipelineException('The local media file is missing or unreadable.', 'media_file_unreadable');
        }

        return $this->artifact($path, $requestedFilename ?: basename($path), '', false);
    }

    private function artifact(string $path, string $filename, string $sourceUrl, bool $temporary): MediaArtifact
    {
        $inspection = $this->inspector->inspect($path, [
            'max_bytes' => WordPressHttpTransport::MAX_IMAGE_BYTES,
            'max_pixels' => self::MAX_PIXELS,
            'allowed_mime_types' => self::ALLOWED_MIME_TYPES,
            'require_visible_pixels' => true,
        ]);

        return new MediaArtifact(
            path: $path,
            filename: MediaFilename::sanitize($filename, (string) $inspection['mime_type']),
            mimeType: (string) $inspection['mime_type'],
            bytes: (int) $inspection['bytes'],
            width: (int) $inspection['width'],
            height: (int) $inspection['height'],
            sha256: (string) $inspection['sha256'],
            sourceUrl: $sourceUrl,
            temporary: $temporary,
            warnings: (array) ($inspection['warnings'] ?? []),
            metadata: ['transport' => 'safe_outbound_http'],
        );
    }

    private function filenameFromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        return basename($path) ?: 'wordpress-media';
    }
}
