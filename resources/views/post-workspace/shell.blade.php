@php
    $workspaceId ??= 'wordpress-post-workspace-'.$postId;
    $siteName ??= 'WordPress';
    $postUrl ??= null;
    $dashboardLoginUrl ??= null;
    $editorLoginUrl ??= null;
    $initialWorkspace = is_array($initialWorkspace ?? null) ? $initialWorkspace : [];
    $initialPost = is_array($initialWorkspace['post'] ?? null) ? $initialWorkspace['post'] : [];
    $hasCachedSnapshot = (bool) ($initialWorkspace['success'] ?? false) && $initialPost !== [];
    $initialStatus = $hasCachedSnapshot
        ? (string) ($initialPost['status_label'] ?? $initialPost['status'] ?? 'Cached')
        : 'Not cached';
    $initialStatusTone = $hasCachedSnapshot
        ? (($initialPost['status'] ?? null) === 'publish' ? 'published' : ($initialPost['status'] ?? 'pending'))
        : 'pending';
    $cacheRebuiltAt = $initialWorkspace['cache_rebuilt_at'] ?? null;
@endphp

@once
    <link rel="stylesheet" href="{{ route('hexa-package.asset', [
        'package' => 'wordpress',
        'version' => config('wordpress.version'),
        'asset' => 'post-workspace.css',
    ]) }}">
    <script src="{{ route('hexa-package.asset', [
        'package' => 'wordpress',
        'version' => config('wordpress.version'),
        'asset' => 'post-workspace.js',
    ]) }}" defer></script>
@endonce

<section id="{{ $workspaceId }}" class="hwp-workspace"
    data-wordpress-post-workspace
    data-refresh-url="{{ $refreshUrl }}"
    data-csrf-token="{{ csrf_token() }}"
    data-post-id="{{ $postId }}">
    <script type="application/json" data-wordpress-initial-workspace>{!! json_encode(
        $initialWorkspace,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ) !!}</script>
    <header class="hwp-workspace__header">
        <div class="hwp-workspace__identity">
            <span class="hwp-workspace__mark" aria-hidden="true">WP</span>
            <span>
                <small>WordPress fulfillment</small>
                <strong>{{ $siteName }}</strong>
                <em>Post #{{ $postId }}</em>
            </span>
        </div>
        <div class="hwp-workspace__state">
            <span class="hwp-workspace__status hwp-workspace__status--{{ $initialStatusTone }}" data-wordpress-post-status>
                {{ $initialStatus }}
            </span>
            <span data-wordpress-post-updated>
                {{ $cacheRebuiltAt ? 'Cache rebuilt '.\Illuminate\Support\Carbon::parse($cacheRebuiltAt)->diffForHumans() : 'Cache has not been built' }}
            </span>
        </div>
    </header>

    <div class="hwp-workspace__toolbar">
        <button type="button" class="hwp-workspace__button hwp-workspace__button--primary"
            data-wordpress-post-refresh>
            <span class="hwp-workspace__spinner" aria-hidden="true"></span>
            <span data-wordpress-post-refresh-label>Refresh post</span>
        </button>
        <a href="{{ $postUrl ?: '#' }}" target="_blank" rel="noopener noreferrer"
            class="hwp-workspace__button {{ $postUrl ? '' : 'hidden' }}" data-wordpress-post-link>
            Open post URL
        </a>
        @if($dashboardLoginUrl)
            <form method="POST" action="{{ $dashboardLoginUrl }}" target="_blank"
                data-wordpress-login-form>
                @csrf
                <button type="submit" class="hwp-workspace__button">
                    <span class="hwp-workspace__spinner" aria-hidden="true"></span>
                    <span>Open {{ $siteName }} dashboard</span>
                </button>
            </form>
        @endif
        @if($editorLoginUrl)
            <form method="POST" action="{{ $editorLoginUrl }}" target="_blank"
                data-wordpress-login-form>
                @csrf
                <button type="submit" class="hwp-workspace__button hwp-workspace__button--dark">
                    <span class="hwp-workspace__spinner" aria-hidden="true"></span>
                    <span>Edit post in {{ $siteName }}</span>
                </button>
            </form>
        @endif
    </div>
    <p class="hwp-workspace__activity" data-wordpress-post-activity role="status" aria-live="polite">
        {{ $hasCachedSnapshot
            ? 'Showing the latest saved snapshot. WordPress is contacted only when Refresh post is pressed.'
            : 'No cached snapshot exists. Press Refresh post to contact WordPress and build one.' }}
    </p>

    <div class="hwp-workspace__content" data-wordpress-post-content>
        @unless($hasCachedSnapshot)
            <p class="hwp-workspace__empty">The post preview will appear here after the first manual refresh.</p>
        @endunless
    </div>
</section>
