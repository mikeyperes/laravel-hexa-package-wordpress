<?php

namespace hexa_package_wordpress\Acf;

use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use Illuminate\Support\Facades\Validator;

class AcfEducationMetadataService
{
    public function __construct(private readonly SafeOutboundHttpClient $http) {}

    public function lookupMany(array $names): array
    {
        Validator::make(['names' => $names], [
            'names' => ['array', 'max:20'],
            'names.*' => ['nullable', 'string', 'max:190'],
        ])->validate();

        $names = collect($names)
            ->map(static fn ($name) => trim((string) $name))
            ->filter(static fn ($name) => $name !== '')
            ->unique()
            ->values()
            ->take(20)
            ->all();

        if ($names === []) {
            return ['success' => false, 'message' => 'No education names provided.', 'items' => []];
        }

        return [
            'success' => true,
            'items' => array_map(fn (string $name): array => $this->fetchWikipediaForName($name), $names),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    protected function fetchWikipediaForName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['name' => $name, 'success' => false, 'wiki_url' => '', 'title' => '', 'message' => 'Empty education name.'];
        }

        try {
            $variants = $this->nameVariants($name);
            $candidateTitles = [];

            // Query all safe variants in one request, then fall back to one
            // bounded search request. This avoids N×variant network fan-out.
            $direct = $this->wikipediaRequest([
                'action' => 'query',
                'titles' => implode('|', $variants),
                'redirects' => 1,
                'format' => 'json',
                'utf8' => 1,
            ]);
            if ($direct->successful()) {
                $directPayload = $direct->json();
                if (! is_array($directPayload) || ! is_array($directPayload['query'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid Wikipedia response.');
                }

                $redirects = $directPayload['query']['redirects'] ?? [];
                $acceptedRedirectTargets = [];
                foreach (is_array($redirects) ? $redirects : [] as $redirect) {
                    $from = trim((string) ($redirect['from'] ?? ''));
                    $to = trim((string) ($redirect['to'] ?? ''));
                    if ($from !== '' && $to !== '' && $this->titleMatchesAny($variants, $from)) {
                        $acceptedRedirectTargets[$this->lookupKey($to)] = true;
                    }
                }

                $pages = $directPayload['query']['pages'] ?? [];
                foreach (is_array($pages) ? $pages : [] as $page) {
                    $page = (array) $page;
                    $title = trim((string) ($page['title'] ?? ''));
                    $missing = array_key_exists('missing', $page) || (string) ($page['pageid'] ?? '') === '-1';
                    $titleKey = $this->lookupKey($title);
                    if ($title !== '') {
                        $candidateTitles[$title] = true;
                    }
                    if (! $missing && $title !== '' && ($this->titleMatchesAny($variants, $title) || isset($acceptedRedirectTargets[$titleKey]))) {
                        return [
                            'name' => $name,
                            'success' => true,
                            'wiki_url' => $this->wikipediaUrlForTitle($title),
                            'title' => $title,
                            'message' => isset($acceptedRedirectTargets[$titleKey])
                                ? 'Wikipedia page matched by live redirect.'
                                : 'Wikipedia page matched by one batched live lookup.',
                        ];
                    }
                }
            }

            $response = $this->wikipediaRequest([
                'action' => 'query',
                'list' => 'search',
                'srsearch' => implode(' OR ', array_map(static fn (string $variant): string => '"'.$variant.'"', $variants)),
                'srlimit' => 8,
                'format' => 'json',
                'utf8' => 1,
            ]);
            if (! $response->successful()) {
                return ['name' => $name, 'success' => false, 'wiki_url' => '', 'title' => '', 'message' => 'Wikipedia search failed: HTTP '.$response->status, 'searched' => $variants];
            }

            $responsePayload = $response->json();
            if (! is_array($responsePayload) || ! is_array($responsePayload['query']['search'] ?? null)) {
                throw new \UnexpectedValueException('Invalid Wikipedia response.');
            }
            foreach ($responsePayload['query']['search'] as $candidate) {
                $title = trim((string) ($candidate['title'] ?? ''));
                if ($title !== '') {
                    $candidateTitles[$title] = true;
                }
                if ($title === '' || ! $this->titleMatchesAny($variants, $title)) {
                    continue;
                }

                return [
                    'name' => $name,
                    'success' => true,
                    'wiki_url' => $this->wikipediaUrlForTitle($title),
                    'title' => $title,
                    'message' => 'Wikipedia page matched by one batched live search.',
                    'searched' => $variants,
                ];
            }

            return [
                'name' => $name,
                'success' => false,
                'wiki_url' => '',
                'title' => '',
                'message' => 'No exact Wikipedia page match found. Searched: '.implode(', ', $variants).'. Candidates: '.(count($candidateTitles) ? implode(', ', array_slice(array_keys($candidateTitles), 0, 6)) : 'none').'.',
                'searched' => $variants,
                'candidates' => array_slice(array_keys($candidateTitles), 0, 12),
            ];
        } catch (\Throwable) {
            return ['name' => $name, 'success' => false, 'wiki_url' => '', 'title' => '', 'message' => 'Wikipedia metadata could not be fetched safely.'];
        }
    }

    private function wikipediaRequest(array $query): OutboundHttpResponse
    {
        return $this->http->request('GET', 'https://en.wikipedia.org/w/api.php?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [
            'timeout' => 12,
            'max_bytes' => 262144,
            'max_redirects' => 0,
            'headers' => ['User-Agent' => 'Hexa WordPress ACF Education Metadata Fetcher/1.0', 'Accept' => 'application/json'],
        ]);
    }

    protected function titleMatches(string $name, string $title): bool
    {
        $nameKey = $this->lookupKey($name);
        $titleKey = $this->lookupKey($title);

        return $nameKey !== '' && $nameKey === $titleKey;
    }

    /** @param array<int, string> $names */
    protected function titleMatchesAny(array $names, string $title): bool
    {
        foreach ($names as $name) {
            if ($this->titleMatches((string) $name, $title)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    protected function nameVariants(string $name): array
    {
        $base = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $base = preg_replace('/[|"\x00-\x1F\x7F]+/u', ' ', $base);
        $base = trim((string) preg_replace('/\s+/u', ' ', (string) $base));
        $variants = [$base];
        $variants[] = (string) preg_replace('/\s*(?:\+|&)\s*/u', ' and ', $base);
        $variants[] = str_replace([' School + ', ' School & '], [' School and ', ' School and '], $base);
        $variants[] = str_replace([' + ', ' & '], [' and ', ' and '], $base);

        return collect($variants)
            ->map(static fn ($value) => trim((string) preg_replace('/\s+/u', ' ', (string) $value)))
            ->filter(static fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function wikipediaUrlForTitle(string $title): string
    {
        return 'https://en.wikipedia.org/wiki/'.str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', trim($title))));
    }

    protected function lookupKey(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/\s*(?:\+|&)\s*/', ' and ', $value);
        $value = preg_replace('/\b(the|school|of|and|at)\b/', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
