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
        $this->mergeConfigFrom(__DIR__ . '/../../config/wordpress.php', 'wordpress');
        $this->app->singleton(WordPressService::class);
    }

    /**
     * Bootstrap package resources.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/wordpress.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'wordpress');
        $this->registerSidebarItems();
    }

    /**
     * Push sidebar menu items into core layout stacks.
     *
     * @return void
     */
    private function registerSidebarItems(): void
    {
        view()->composer('layouts.app', function ($view) {
            if (config('hexa.app_controls_sidebar', false)) return;
            $view->getFactory()->startPush('sidebar-sandbox', view('wordpress::partials.sidebar-menu')->render());
        });
    }
}
