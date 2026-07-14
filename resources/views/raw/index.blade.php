@extends('layouts.app')

@section('title', 'WordPress Raw — ' . config('hws.app_name'))
@section('header', 'WordPress — Raw Functions')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- Credentials Status --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center space-x-3">
        <span class="text-sm font-medium text-gray-700">Credentials:</span>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="5"/></svg>
            Per-site (entered below)
        </span>
    </div>

    {{-- Package Functions Index --}}
    <div class="bg-gray-900 rounded-xl p-6 text-sm font-mono">
        <h2 class="text-white font-semibold mb-3">WordPress Functions</h2>
        <table class="w-full text-left">
            <thead>
                <tr class="text-gray-400 border-b border-gray-700">
                    <th class="py-1.5 px-2">Function</th>
                    <th class="py-1.5 px-2">Method</th>
                    <th class="py-1.5 px-2">Route</th>
                    <th class="py-1.5 px-2">Status</th>
                </tr>
            </thead>
            <tbody class="text-gray-300">
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Test site connection</td>
                    <td class="py-1.5 px-2 text-blue-400">testConnection()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wordpress/test-connection</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Get categories</td>
                    <td class="py-1.5 px-2 text-blue-400">getCategories()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wordpress/categories</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Get tags</td>
                    <td class="py-1.5 px-2 text-blue-400">getTags()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wordpress/tags</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Create post</td>
                    <td class="py-1.5 px-2 text-blue-400">createPost()</td>
                    <td class="py-1.5 px-2 text-green-400">POST /wordpress/create-post</td>
                    <td class="py-1.5 px-2 text-green-400">LIVE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Update post</td>
                    <td class="py-1.5 px-2 text-blue-400">updatePost()</td>
                    <td class="py-1.5 px-2 text-yellow-400">service only</td>
                    <td class="py-1.5 px-2 text-yellow-400">NO ROUTE</td>
                </tr>
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 px-2">Upload media</td>
                    <td class="py-1.5 px-2 text-blue-400">uploadMedia()</td>
                    <td class="py-1.5 px-2 text-yellow-400">service only</td>
                    <td class="py-1.5 px-2 text-yellow-400">NO ROUTE</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Connection Credentials --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Connection Credentials</h2>
        <p class="text-sm text-gray-500 mb-4">These credentials are used for all actions below. They are not stored.</p>

        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Site URL</label>
                <input type="url" id="wp-site-url" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="https://example.com">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" id="wp-username" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="admin">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">App Password</label>
                    <input type="password" id="wp-app-password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="xxxx xxxx xxxx xxxx">
                </div>
            </div>
            <button id="btn-wp-test" onclick="wpTestConnection()" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                Test Connection
            </button>
        </div>

        <div id="wp-test-result" class="mt-4"></div>
    </div>

    {{-- Get Categories --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Get Categories</h2>

        <button id="btn-wp-categories" onclick="wpGetCategories()" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
            Get Categories
        </button>

        <div id="wp-categories-result" class="mt-4"></div>
    </div>

    {{-- Get Tags --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Get Tags</h2>

        <button id="btn-wp-tags" onclick="wpGetTags()" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
            Get Tags
        </button>

        <div id="wp-tags-result" class="mt-4"></div>
    </div>

    {{-- Create Post --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Create Post</h2>

        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                <input type="text" id="wp-post-title" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="My Test Post">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                <textarea id="wp-post-content" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Post content (HTML supported)..."></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="wp-post-status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="draft">Draft</option>
                    <option value="publish">Publish</option>
                </select>
            </div>
            <button id="btn-wp-create-post" onclick="wpCreatePost()" class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors">
                Create Post
            </button>
        </div>

        <div id="wp-create-post-result" class="mt-4"></div>
    </div>
</div>

@push('scripts')
<script>@include('wordpress::scripts.raw-index.block-1-part-1')</script>
@endpush
@endsection
