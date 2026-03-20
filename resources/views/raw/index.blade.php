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
<script>
const CSRF_TOKEN = '{{ csrf_token() }}';
const SPINNER_SVG = '<svg class="animate-spin h-4 w-4 inline mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

function setButtonLoading(btn, loadingText) {
    btn.disabled = true;
    btn._originalHTML = btn.innerHTML;
    btn.innerHTML = SPINNER_SVG + ' ' + loadingText;
}

function resetButton(btn) {
    btn.disabled = false;
    btn.innerHTML = btn._originalHTML;
}

function resultBanner(success, message) {
    var cls = success
        ? 'bg-green-50 border-green-200 text-green-800'
        : 'bg-red-50 border-red-200 text-red-800';
    return '<div class="p-3 rounded-lg text-sm border ' + cls + ' break-words">' + message + '</div>';
}

function getCredentials() {
    return {
        site_url: document.getElementById('wp-site-url').value,
        username: document.getElementById('wp-username').value,
        app_password: document.getElementById('wp-app-password').value,
    };
}

function validateCredentials(resultEl) {
    var creds = getCredentials();
    if (!creds.site_url || !creds.username || !creds.app_password) {
        resultEl.innerHTML = resultBanner(false, 'Please fill in all connection fields above (Site URL, Username, App Password).');
        return null;
    }
    return creds;
}

function wpTestConnection() {
    var btn = document.getElementById('btn-wp-test');
    var resultEl = document.getElementById('wp-test-result');
    var creds = validateCredentials(resultEl);
    if (!creds) return;

    setButtonLoading(btn, 'Testing...');

    var body = new FormData();
    body.append('_token', CSRF_TOKEN);
    body.append('site_url', creds.site_url);
    body.append('username', creds.username);
    body.append('app_password', creds.app_password);

    fetch('{{ route("wordpress.test-connection") }}', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = resultBanner(data.success, data.message);
            if (data.success && data.data) {
                html += '<div class="mt-2 bg-gray-50 rounded-lg border border-gray-200 p-3 text-sm space-y-1">';
                html += '<div><span class="font-medium text-gray-700">User:</span> ' + (data.data.user_name || '') + ' (ID: ' + (data.data.user_id || '') + ')</div>';
                if (data.data.roles && data.data.roles.length) {
                    html += '<div><span class="font-medium text-gray-700">Roles:</span> ' + data.data.roles.join(', ') + '</div>';
                }
                html += '</div>';
            }
            resultEl.innerHTML = html;
        })
        .catch(function() {
            resultEl.innerHTML = resultBanner(false, 'Request failed.');
        })
        .finally(function() {
            resetButton(btn);
        });
}

function wpGetCategories() {
    var btn = document.getElementById('btn-wp-categories');
    var resultEl = document.getElementById('wp-categories-result');
    var creds = validateCredentials(resultEl);
    if (!creds) return;

    setButtonLoading(btn, 'Loading...');

    var body = new FormData();
    body.append('_token', CSRF_TOKEN);
    body.append('site_url', creds.site_url);
    body.append('username', creds.username);
    body.append('app_password', creds.app_password);

    fetch('{{ route("wordpress.categories") }}', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.length) {
                var html = resultBanner(true, data.message);
                html += '<table class="w-full mt-3 text-sm"><thead><tr class="border-b border-gray-200">';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">ID</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Name</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Slug</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Count</th>';
                html += '</tr></thead><tbody>';
                data.data.forEach(function(cat) {
                    html += '<tr class="border-b border-gray-100">';
                    html += '<td class="py-1.5 px-2 text-gray-900">' + cat.id + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-900 break-words">' + cat.name + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-500 break-words">' + cat.slug + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-500">' + cat.count + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                resultEl.innerHTML = html;
            } else {
                resultEl.innerHTML = resultBanner(data.success, data.message || 'No categories found.');
            }
        })
        .catch(function() {
            resultEl.innerHTML = resultBanner(false, 'Request failed.');
        })
        .finally(function() {
            resetButton(btn);
        });
}

function wpGetTags() {
    var btn = document.getElementById('btn-wp-tags');
    var resultEl = document.getElementById('wp-tags-result');
    var creds = validateCredentials(resultEl);
    if (!creds) return;

    setButtonLoading(btn, 'Loading...');

    var body = new FormData();
    body.append('_token', CSRF_TOKEN);
    body.append('site_url', creds.site_url);
    body.append('username', creds.username);
    body.append('app_password', creds.app_password);

    fetch('{{ route("wordpress.tags") }}', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.length) {
                var html = resultBanner(true, data.message);
                html += '<table class="w-full mt-3 text-sm"><thead><tr class="border-b border-gray-200">';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">ID</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Name</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Slug</th>';
                html += '<th class="py-1.5 px-2 text-left text-gray-600">Count</th>';
                html += '</tr></thead><tbody>';
                data.data.forEach(function(tag) {
                    html += '<tr class="border-b border-gray-100">';
                    html += '<td class="py-1.5 px-2 text-gray-900">' + tag.id + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-900 break-words">' + tag.name + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-500 break-words">' + tag.slug + '</td>';
                    html += '<td class="py-1.5 px-2 text-gray-500">' + tag.count + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                resultEl.innerHTML = html;
            } else {
                resultEl.innerHTML = resultBanner(data.success, data.message || 'No tags found.');
            }
        })
        .catch(function() {
            resultEl.innerHTML = resultBanner(false, 'Request failed.');
        })
        .finally(function() {
            resetButton(btn);
        });
}

function wpCreatePost() {
    var btn = document.getElementById('btn-wp-create-post');
    var resultEl = document.getElementById('wp-create-post-result');
    var creds = validateCredentials(resultEl);
    if (!creds) return;

    var title = document.getElementById('wp-post-title').value;
    var content = document.getElementById('wp-post-content').value;
    var status = document.getElementById('wp-post-status').value;

    if (!title.trim() || !content.trim()) {
        resultEl.innerHTML = resultBanner(false, 'Title and content are required.');
        return;
    }

    setButtonLoading(btn, 'Creating...');

    var body = new FormData();
    body.append('_token', CSRF_TOKEN);
    body.append('site_url', creds.site_url);
    body.append('username', creds.username);
    body.append('app_password', creds.app_password);
    body.append('title', title);
    body.append('content', content);
    body.append('status', status);

    fetch('{{ route("wordpress.create-post") }}', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = resultBanner(data.success, data.message);
            if (data.success && data.data) {
                html += '<div class="mt-2 bg-gray-50 rounded-lg border border-gray-200 p-3 text-sm space-y-1">';
                html += '<div><span class="font-medium text-gray-700">Post ID:</span> ' + (data.data.post_id || '') + '</div>';
                html += '<div><span class="font-medium text-gray-700">Status:</span> ' + (data.data.post_status || '') + '</div>';
                if (data.data.post_url) {
                    html += '<div><span class="font-medium text-gray-700">URL:</span> <a href="' + data.data.post_url + '" target="_blank" class="text-blue-500 hover:underline break-words">' + data.data.post_url + ' <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a></div>';
                }
                html += '</div>';
            }
            resultEl.innerHTML = html;
        })
        .catch(function() {
            resultEl.innerHTML = resultBanner(false, 'Request failed.');
        })
        .finally(function() {
            resetButton(btn);
        });
}
</script>
@endpush
@endsection
