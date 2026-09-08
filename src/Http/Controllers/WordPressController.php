<?php

namespace hexa_package_wordpress\Http\Controllers;

use hexa_package_wordpress\Services\ArticleMetadataService;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Services\WordPressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * WordPressController — handles raw dev view and API test endpoints.
 */
class WordPressController extends Controller
{
    private readonly ArticleMetadataService $articleMetadata;

    public function __construct(
        private readonly WordPressService $wordpress,
        ?ArticleMetadataService $articleMetadata = null,
    ) {
        $this->articleMetadata = $articleMetadata ?? app(ArticleMetadataService::class);
    }

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

        $result = $this->wordpress->testConnection(
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

        $result = $this->wordpress->getCategories(
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

        $result = $this->wordpress->getTags(
            $request->input('site_url'),
            $request->input('username'),
            $request->input('app_password')
        );

        return response()->json($result);
    }

    public function educationMetadata(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'names' => ['required', 'array', 'max:20'],
            'names.*' => ['nullable', 'string', 'max:190'],
        ]);

        $result = app(AcfEducationMetadataService::class)->lookupMany((array) ($payload['names'] ?? []));

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function articleMetadata(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'url' => ['nullable', 'string', 'max:8192'],
            'urls' => ['sometimes', 'array', 'max:20'],
            'urls.*' => ['nullable', 'string', 'max:8192'],
        ]);
        $urls = $payload['urls'] ?? [];
        if (trim($payload['url'] ?? '') !== '') {
            array_unshift($urls, $payload['url']);
        }

        $result = $this->articleMetadata->lookupMany($urls);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /** Backward-compatible hook retained for package consumers and tests. */
    protected function fetchArticleMetadataForUrl(string $url): array
    {
        return $this->articleMetadata->lookup($url);
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

        $result = $this->wordpress->createPost(
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
