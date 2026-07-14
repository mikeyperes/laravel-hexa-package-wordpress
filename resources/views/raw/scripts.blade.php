@php
    $wordpressRawConfig = [
        'csrfToken' => csrf_token(),
        'routes' => [
            'testConnection' => route('wordpress.test-connection'),
            'categories' => route('wordpress.categories'),
            'tags' => route('wordpress.tags'),
            'createPost' => route('wordpress.create-post'),
        ],
    ];
@endphp
<script type="application/json" id="wordpress-raw-config">{!! Illuminate\Support\Js::encode($wordpressRawConfig) !!}</script>
<x-hexa-package-script package="wordpress" :version="config('wordpress.version')" asset="raw.js" />
