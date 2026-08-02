<?php

namespace hexa_package_wordpress\Providers;

use hexa_core\Services\PackageRegistryService;
use hexa_core\Services\DocumentationService;
use hexa_core\Support\PackageAssetRegistry;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Acf\AcfRepeaterNormalizer;
use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_wordpress\Acf\AcfStructureRegistry;
use hexa_package_wordpress\Media\WordPressMediaAssignmentService;
use hexa_package_wordpress\Media\WordPressMediaGateway;
use hexa_package_wordpress\Media\WordPressMediaOperationStore;
use hexa_package_wordpress\SearchConsole\SiteKitTargetAdapter;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressLoginUrlService;
use hexa_package_wordpress\Services\WordPressPostSnapshotExtensionRegistry;
use hexa_package_wordpress\Services\WordPressPostSnapshotService;
use hexa_package_wordpress\Services\WordPressPluginIntegrityService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wordpress\Services\WordPressUserDeletionService;
use hexa_package_wordpress\Services\WordPressUserFieldBridgeService;
use hexa_package_wordpress\Services\WordPressUserFieldMap;
use Illuminate\Support\ServiceProvider;

class WordPressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/wordpress.php", "wordpress");
        $this->app->singleton(AcfStructureRegistry::class);
        $this->app->singleton(AcfEducationMetadataService::class);
        $this->app->singleton(AcfRepeaterNormalizer::class);
        $this->app->singleton(AcfSmartTypeResolver::class);
        $this->app->singleton(WordPressService::class);
        $this->app->singleton(WordPressManagerService::class);
        $this->app->singleton(WordPressLoginUrlService::class);
        $this->app->singleton(WordPressPostSnapshotExtensionRegistry::class);
        $this->app->singleton(WordPressPostSnapshotService::class);
        $this->app->singleton(WordPressPluginIntegrityService::class);
        $this->app->singleton(WordPressUserFieldBridgeService::class);
        $this->app->singleton(WordPressUserDeletionService::class);
        $this->app->singleton(WordPressUserFieldMap::class);
        $this->app->singleton(WordPressMediaOperationStore::class);
        $this->app->singleton(WordPressMediaGateway::class);
        $this->app->bind(WordPressMediaAssignmentService::class);
        if (class_exists(\hexa_package_google_search_console\Domains\Targets\TargetAdapterRegistry::class)) {
            $this->app->singleton(SiteKitTargetAdapter::class);
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . "/../../routes/wordpress.php");
        $this->loadViewsFrom(__DIR__ . "/../../resources/views", "wordpress");

        app(PackageAssetRegistry::class)->register("wordpress", dirname(__DIR__, 2) . "/resources/js", [
            "media-operations.js",
            "post-workspace.js",
            "post-workspace.css",
            "raw.js",
            "user-deletion.js",
        ]);

        if (!config("hexa.app_controls_sidebar", false)) {
            $registry = app(PackageRegistryService::class);
            $registry->registerSidebarLink("wordpress.index", "WordPress", "M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253", "Labs", "wordpress", 87);
            $registry->registerPackage("wordpress", "hexawebsystems/laravel-hexa-package-wordpress", [
                "title" => "WordPress",
                "description" => "WordPress connection, publishing, and sync tooling for publish installs.",
                "icon" => "M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253",
                "color" => "blue",
                "settingsRoute" => "wordpress.index",
            ]);
        }

        $this->app->booted(function (): void {
            $registryClass = \hexa_package_google_search_console\Domains\Targets\TargetAdapterRegistry::class;
            if (class_exists($registryClass) && $this->app->bound($registryClass) && $this->app->bound(SiteKitTargetAdapter::class)) {
                $this->app->make($registryClass)->register($this->app->make(SiteKitTargetAdapter::class));
            }
        });

        $this->registerDocumentation();
    }

    private function registerDocumentation(): void
    {
        if (! class_exists(DocumentationService::class)) {
            return;
        }

        try {
            app(DocumentationService::class)->register(
                'wordpress-post-workspaces',
                'WordPress Post Workspaces',
                'hexawebsystems/laravel-hexa-package-wordpress',
                [
                    [
                        'title' => 'Reusable post snapshots',
                        'content' => '<p><code>WordPressPostSnapshotService</code> loads standard post fields, author activity, taxonomies, featured media, content, status, timestamps, and registered extension data through REST or WP Toolkit. Raw post metadata remains server-side.</p>',
                    ],
                    [
                        'title' => 'Provider extensions',
                        'content' => '<p>Provider packages implement <code>WordPressPostSnapshotExtension</code> and register with <code>WordPressPostSnapshotExtensionRegistry</code>. This exposes provider-owned deliverables without adding provider names or metadata rules to the generic WordPress reader.</p>',
                    ],
                    [
                        'title' => 'Preview and secure access',
                        'content' => '<p>The <code>wordpress::post-workspace.shell</code> view and package assets render live refresh, a sandboxed content preview, post metadata, login activity, and extension output. <code>WordPressLoginUrlService</code> validates the WP Toolkit install, cPanel root, HTTPS host, WordPress account, and returned one-time URL before a consumer redirects.</p>',
                    ],
                ],
                'package'
            );
        } catch (\Throwable) {
        }
    }
}
