<?php

namespace hexa_package_wordpress\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_wordpress\Services\WordPressService;

class WordPressServiceProvider extends ServiceProvider
{
    /**
     * Register the WordPress service as a singleton.
     */
    public function register(): void
    {
        $this->app->singleton(WordPressService::class);
    }

    public function boot(): void {}
}
