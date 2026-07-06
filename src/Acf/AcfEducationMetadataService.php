<?php

namespace hexa_package_wordpress\Acf;

use Illuminate\Support\Facades\Http;

class AcfEducationMetadataService
{
    public function lookupMany(array $names): array
    {
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

            foreach ($variants as $variant) {
                $direct = Http::withoutVerifying()
                    ->timeout(12)
                    ->withHeaders(['User-Agent' => 'Hexa WordPress ACF Education Metadata Fetcher/1.0'])
                    ->get('https://en.wikipedia.org/w/api.php', [
                        'action' => 'query',
                        'titles' => $variant,
                        'redirects' => 1,
                        'format' => 'json',
                        'utf8' => 1,
                    ]);

                if (!$direct->successful()) {
                    continue;
                }

                $redirects = $direct->json('query.redirects', []);
                $redirects = is_array($redirects) ? $redirects : [];
                $acceptedRedirectTargets = [];
                foreach ($redirects as $redirect) {
                    $from = trim((string) ($redirect['from'] ?? ''));
                    $to = trim((string) ($redirect['to'] ?? ''));
                    if ($from !== '' && $to !== '' && $this->titleMatchesAny($variants, $from)) {
                        $acceptedRedirectTargets[$this->lookupKey($to)] = true;
                    }
                }

                $pages = $direct->json('query.pages', []);
                if (!is_array($pages)) {
                    continue;
                }

                foreach ($pages as $page) {
                    $title = trim((string) ($page['title'] ?? ''));
                    $missing = array_key_exists('missing', (array) $page) || (string) ($page['pageid'] ?? '') === '-1';
                    $titleKey = $this->lookupKey($title);
                    if ($title !== '') {
                        $candidateTitles[$title] = true;
                    }
                    if (!$missing && $title !== '' && ($this->titleMatchesAny($variants, $title) || !empty($acceptedRedirectTargets[$titleKey]))) {
                        return [
                            'name' => $name,
                            'success' => true,
                            'wiki_url' => $this->wikipediaUrlForTitle($title),
                            'title' => $title,
                            'message' => !empty($acceptedRedirectTargets[$titleKey]) ? 'Wikipedia page matched by live redirect.' : 'Wikipedia page matched by live page lookup using "' . $variant . '".',
                        ];
                    }
                }
            }

            foreach ($variants as $variant) {
                $response = Http::withoutVerifying()
                    ->timeout(12)
                    ->withHeaders(['User-Agent' => 'Hexa WordPress ACF Education Metadata Fetcher/1.0'])
                    ->get('https://en.wikipedia.org/w/api.php', [
                        'action' => 'query',
                        'list' => 'search',
                        'srsearch' => '"' . $variant . '"',
                        'srlimit' => 8,
                        'format' => 'json',
                        'utf8' => 1,
                    ]);

                if (!$response->successful()) {
                    return ['name' => $name, 'success' => false, 'wiki_url' => '', 'title' => '', 'message' => 'Wikipedia search failed: HTTP ' . $response->status(), 'searched' => $variants];
                }

                $search = $response->json('query.search', []);
                $search = is_array($search) ? $search : [];
                foreach ($search as $candidate) {
                    $title = trim((string) ($candidate['title'] ?? ''));
                    if ($title !== '') {
                        $candidateTitles[$title] = true;
                    }
                    if ($title === '' || !$this->titleMatchesAny($variants, $title)) {
                        continue;
                    }

                    return [
                        'name' => $name,
                        'success' => true,
                        'wiki_url' => $this->wikipediaUrlForTitle($title),
                        'title' => $title,
                        'message' => 'Wikipedia page matched by live search using "' . $variant . '".',
                        'searched' => $variants,
                    ];
                }
            }

            return [
                'name' => $name,
                'success' => false,
                'wiki_url' => '',
                'title' => '',
                'message' => 'No exact Wikipedia page match found. Searched: ' . implode(', ', $variants) . '. Candidates: ' . (count($candidateTitles) ? implode(', ', array_slice(array_keys($candidateTitles), 0, 6)) : 'none') . '.',
                'searched' => $variants,
                'candidates' => array_slice(array_keys($candidateTitles), 0, 12),
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'success' => false, 'wiki_url' => '', 'title' => '', 'message' => 'Wikipedia search failed: ' . $e->getMessage()];
        }
    }

    protected function titleMatches(string $name, string $title): bool
    {
        $nameKey = $this->lookupKey($name);
        $titleKey = $this->lookupKey($title);
        return $nameKey !== '' && $nameKey === $titleKey;
    }

    protected function titleMatchesAny(array $names, string $title): bool
    {
        foreach ($names as $name) {
            if ($this->titleMatches((string) $name, $title)) {
                return true;
            }
        }
        return false;
    }

    protected function nameVariants(string $name): array
    {
        $base = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
        return 'https://en.wikipedia.org/wiki/' . str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', trim($title))));
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
