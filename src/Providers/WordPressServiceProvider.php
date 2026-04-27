<?php

namespace hexa_package_wordpress\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_wordpress\Services\WordPressService;
use hexa_core\Services\PackageRegistryService;

/**
 * WordPressServiceProvider — registers WordPress package services, routes, views.
 */
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

        // Sidebar links — registered via PackageRegistryService with auto permission checks
        if (!config('hexa.app_controls_sidebar', false)) {
            $registry = app(PackageRegistryService::class);
            // HWS-SIDEBAR-MENU-3L-BEGIN
            $registry->registerDomainGroup('Discovery', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 20);
            $registry->registerSectionGroup('Sandbox', 'Discovery', '', 20);
            // HWS-SIDEBAR-MENU-3L-END

            $registry->registerSidebarLink('wordpress.index', 'WordPress', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', 'Sandbox', 'wordpress', 87);
            $registry->registerPackage('wordpress', 'hexawebsystems/laravel-hexa-package-wordpress', [
                'title' => 'WordPress',
                'description' => 'WordPress connection, publishing, and sync tooling for publish installs.',
                'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
                'color' => 'blue',
                'settingsRoute' => 'wordpress.index',
            ]);
        }
    }
}
