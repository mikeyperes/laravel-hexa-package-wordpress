<?php

namespace hexa_package_wordpress\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
