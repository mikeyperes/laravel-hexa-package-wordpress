<?php

namespace hexa_package_wordpress\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Services\WordPressService;

/**
 * WordPressController — handles raw dev view and API test endpoints.
 */
class WordPressController extends Controller
{
    /**
     * Show the raw development/test page.
     *
     * @return \Illuminate\View\View
     */
    public function raw()
    {
        return view('wordpress::raw.index');
    }

    /**
     * Test connection to a WordPress site.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'site_url' => 'required|url',
            'username' => 'required|string',
            'app_password' => 'required|string',
        ]);

        $service = app(WordPressService::class);
        $result = $service->testConnection(
            $request->input('site_url'),
            $request->input('username'),
            $request->input('app_password')
        );

        return response()->json($result);
    }

    /**
     * Get categories from a WordPress site.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function categories(Request $request)
    {
        $request->validate([
            'site_url' => 'required|url',
            'username' => 'required|string',
            'app_password' => 'required|string',
        ]);

        $service = app(WordPressService::class);
        $result = $service->getCategories(
            $request->input('site_url'),
            $request->input('username'),
            $request->input('app_password')
        );

        return response()->json($result);
    }

    /**
     * Get tags from a WordPress site.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tags(Request $request)
    {
        $request->validate([
            'site_url' => 'required|url',
            'username' => 'required|string',
            'app_password' => 'required|string',
        ]);

        $service = app(WordPressService::class);
        $result = $service->getTags(
            $request->input('site_url'),
            $request->input('username'),
            $request->input('app_password')
        );

        return response()->json($result);
    }

    public function educationMetadata(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'names' => ['required', 'array'],
            'names.*' => ['nullable', 'string', 'max:190'],
        ]);

        $result = app(AcfEducationMetadataService::class)->lookupMany((array) ($payload['names'] ?? []));

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function articleMetadata(Request $request): JsonResponse
    {
        $urls = $request->input('urls', []);
        $singleUrl = trim((string) $request->input('url', ''));
        if (!is_array($urls)) {
            $urls = [];
        }
        if ($singleUrl !== '') {
            array_unshift($urls, $singleUrl);
        }

        $urls = collect($urls)
            ->map(static fn ($url) => trim((string) $url))
            ->filter(static fn ($url) => $url !== '')
            ->unique()
            ->values()
            ->take(20)
            ->all();

        if (!$urls) {
            return response()->json(['success' => false, 'message' => 'No article URLs provided.', 'items' => []], 422);
        }

        $items = [];
        foreach ($urls as $url) {
            $items[] = $this->fetchArticleMetadataForUrl($url);
        }

        return response()->json([
            'success' => true,
            'items' => $items,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    protected function fetchArticleMetadataForUrl(string $url): array
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['url' => $url, 'success' => false, 'title' => '', 'source' => $host, 'message' => 'Invalid article URL.'];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                ->timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36 HexaSMP/1.0',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'no-cache',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => false, 'referer' => true]])
                ->get($url);

            if (!$response->successful()) {
                return ['url' => $url, 'success' => false, 'title' => '', 'source' => preg_replace('/^www\./', '', $host), 'message' => 'Fetch failed: HTTP ' . $response->status()];
            }

            $title = $this->extractArticleTitleFromHtml((string) $response->body());
            if ($title === '') {
                return ['url' => $url, 'success' => false, 'title' => '', 'source' => preg_replace('/^www\./', '', $host), 'message' => 'No title metadata found.'];
            }

            return ['url' => $url, 'success' => true, 'title' => $title, 'source' => preg_replace('/^www\./', '', $host), 'message' => 'Title metadata fetched.'];
        } catch (\Throwable $e) {
            return ['url' => $url, 'success' => false, 'title' => '', 'source' => preg_replace('/^www\./', '', $host), 'message' => 'Fetch failed: ' . $e->getMessage()];
        }
    }

    protected function extractArticleTitleFromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $name = $this->htmlAttributeValue($tag, 'property') ?: $this->htmlAttributeValue($tag, 'name');
                $content = $this->htmlAttributeValue($tag, 'content');
                if ($content === '') {
                    continue;
                }
                $key = strtolower(trim($name));
                if (in_array($key, ['og:title', 'twitter:title', 'parsely-title', 'sailthru.title', 'dc.title', 'headline'], true)) {
                    return $this->cleanArticleTitle($content);
                }
            }
        }

        if (preg_match('/"headline"\s*:\s*"((?:[^"\\\\]|\\\\.)+)"/is', $html, $match)) {
            return $this->cleanArticleTitle(stripslashes((string) $match[1]));
        }

        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $match)) {
            return $this->cleanArticleTitle((string) $match[1]);
        }

        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return $this->cleanArticleTitle((string) $match[1]);
        }

        return '';
    }

    protected function htmlAttributeValue(string $tag, string $attribute): string
    {
        $attribute = preg_quote($attribute, '/');
        if (preg_match('/\b' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match)) {
            return html_entity_decode(trim((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\b' . $attribute . '\s*=\s*([^\s>]+)/is', $tag, $match)) {
            return html_entity_decode(trim((string) $match[1], "\"' \t\n\r\0\x0B"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    protected function cleanArticleTitle(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    /**
     * Create a post on a WordPress site.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPost(Request $request)
    {
        $request->validate([
            'site_url' => 'required|url',
            'username' => 'required|string',
            'app_password' => 'required|string',
            'title' => 'required|string',
            'content' => 'required|string',
            'status' => 'required|in:draft,publish',
        ]);

        $service = app(WordPressService::class);
        $result = $service->createPost(
            $request->input('site_url'),
            $request->input('username'),
            $request->input('app_password'),
            [
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'status' => $request->input('status'),
            ]
        );

        return response()->json($result);
    }
}
