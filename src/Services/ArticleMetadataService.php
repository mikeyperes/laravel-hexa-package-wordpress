<?php

namespace hexa_package_wordpress\Services;

use hexa_core\Security\Http\SafeOutboundHttpClient;
use Illuminate\Support\Facades\Validator;

/** Public article metadata shared by WordPress and its audit consumers. */
class ArticleMetadataService
{
    public function __construct(private readonly SafeOutboundHttpClient $http) {}

    public function lookupMany(array $urls): array
    {
        Validator::make(['urls' => $urls], [
            'urls' => ['array', 'max:20'],
            'urls.*' => ['nullable', 'string', 'max:8192'],
        ])->validate();
        $urls = array_values(array_unique(array_filter(array_map(
            static fn (?string $url): string => trim($url ?? ''), $urls,
        ), static fn (string $url): bool => $url !== '')));

        if ($urls === []) {
            return ['success' => false, 'message' => 'No article URLs provided.', 'items' => []];
        }

        return [
            'success' => true,
            'items' => array_map($this->lookup(...), $urls),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function lookup(string $url): array
    {
        $url = trim($url);
        $source = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $result = ['url' => $url, 'success' => false, 'title' => '', 'source' => $source];

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 15,
                'max_bytes' => 1048576,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 Hexa Article Metadata/1.0',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]);
            if (! $response->successful()) {
                return $result + ['message' => 'Fetch failed: HTTP '.$response->status];
            }

            $title = $this->extractTitle($response->body);
            if ($title === '') {
                return $result + ['message' => 'No title metadata found.'];
            }

            return array_replace($result, ['success' => true, 'title' => $title, 'message' => 'Title metadata fetched.']);
        } catch (\Throwable) {
            // Transport details can contain signed URLs, credentials or internal addresses.
            return $result + ['message' => 'Article metadata could not be fetched safely.'];
        }
    }

    public function extractTitle(string $html): string
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $name = $this->attribute($tag, 'property') ?: $this->attribute($tag, 'name');
                $content = $this->attribute($tag, 'content');
                if ($content !== '' && in_array(strtolower(trim($name)), [
                    'og:title', 'twitter:title', 'parsely-title', 'sailthru.title', 'dc.title', 'headline',
                ], true)) {
                    return $this->cleanTitle($content);
                }
            }
        }

        if (preg_match('/"headline"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/s', $html, $match)) {
            $headline = json_decode($match[1], true);
            if (is_string($headline) && $headline !== '') {
                return $this->cleanTitle($headline);
            }
        }
        foreach (['h1', 'title'] as $tag) {
            if (preg_match('/<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'>/is', $html, $match)) {
                return $this->cleanTitle($match[1]);
            }
        }

        return '';
    }

    private function attribute(string $tag, string $name): string
    {
        if (preg_match('/\s'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/is', $tag, $match)) {
            return html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\s'.preg_quote($name, '/').'\s*=\s*([^\s>]+)/is', $tag, $match)) {
            return html_entity_decode(trim($match[1], "\"' \t\n\r\0\x0B"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function cleanTitle(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 1000);
    }
}
