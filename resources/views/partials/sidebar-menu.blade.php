@if(\hexa_core\Models\Setting::isPackageEnabled('hexawebsystems/laravel-hexa-package-wordpress'))
@if(auth()->check())

<a href="{{ route('wordpress.index') }}"
   class="flex items-center px-3 py-2 rounded-lg text-sm {{ request()->is('raw-wordpress*') || request()->is('wordpress*') ? 'sidebar-active' : 'sidebar-hover' }}">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
    </svg>
    WordPress
</a>

@endif
@endif
