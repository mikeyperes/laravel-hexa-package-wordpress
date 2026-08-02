@php
    $workspaceId ??= 'wordpress-post-workspace-'.$postId;
    $siteName ??= 'WordPress';
    $postUrl ??= null;
    $dashboardLoginUrl ??= null;
    $editorLoginUrl ??= null;
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
    data-post-id="{{ $postId }}">
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
            <span class="hwp-workspace__status hwp-workspace__status--loading" data-wordpress-post-status>
                Loading
            </span>
            <span data-wordpress-post-updated>Waiting for the first refresh</span>
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
        Connecting to WordPress and loading the latest post state...
    </p>

    <div class="hwp-workspace__content" data-wordpress-post-content>
        <div class="hwp-workspace__loading">
            <span class="hwp-workspace__spinner" aria-hidden="true"></span>
            <strong>Fetching post, metadata, and deliverables</strong>
            <small>This reads the current state directly from WordPress.</small>
        </div>
    </div>
</section>
