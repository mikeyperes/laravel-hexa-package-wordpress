<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWordpress;

use hexa_core\Support\PackageAssetRegistry;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAvatars;
use hexa_package_wordpress\Services\WordPressUserDeletionService;
use Tests\TestCase;

class FrontendArchitectureTest extends TestCase
{
    public function test_raw_tool_asset_is_static_and_registered(): void
    {
        $assets = app(PackageAssetRegistry::class)->assetsFor('wordpress');

        $this->assertArrayHasKey('raw.js', $assets);
        $this->assertFileExists($assets['raw.js']);
        $this->assertDoesNotMatchRegularExpression(
            '/@json|\{\{|\}\}|@(?:if|foreach|php|route)\b/',
            (string) file_get_contents($assets['raw.js'])
        );
    }

    public function test_user_deletion_service_and_frontend_are_owned_by_wordpress_package(): void
    {
        $assets = app(PackageAssetRegistry::class)->assetsFor('wordpress');
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/resources/views/user-deletion/reassignment-selector.blade.php');
        $javascript = (string) file_get_contents($assets['user-deletion.js']);

        $this->assertInstanceOf(WordPressUserDeletionService::class, app(WordPressUserDeletionService::class));
        $this->assertArrayHasKey('user-deletion.js', $assets);
        $this->assertStringContainsString('Assign all existing content to', $view);
        $this->assertStringContainsString('x-hexa-smart-search', $view);
        $this->assertStringContainsString('HexaWordPressUserDeletion', $javascript);
        $this->assertStringContainsString('alpineMethods', $javascript);
        $this->assertStringNotContainsString('journalist', strtolower($javascript));
    }

    public function test_raw_view_delegates_workflow_to_registered_asset(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/resources/views/raw/index.blade.php');

        $this->assertStringContainsString("wordpress::raw.scripts", $view);
        $this->assertStringNotContainsString('function wpTestConnection()', $view);
    }

    public function test_user_field_bridge_exposes_optional_company_photo_picker(): void
    {
        $root = dirname(__DIR__, 2);
        $panel = (string) file_get_contents($root . '/resources/views/user-field-bridge/panel.blade.php');

        $this->assertStringContainsString('showCompanyPhotoPicker', $panel);
        $this->assertStringContainsString('data-journalist-action="pick-company-photos"', $panel);
        $this->assertStringContainsString('scanCompanyPhotos', $panel);
        $this->assertStringContainsString('Current WordPress profile photo', $panel);
        $this->assertStringContainsString('data-profile-photo-thumbnail', $panel);
        $this->assertStringContainsString('profilePhotoFullUrl', $panel);
        $this->assertStringContainsString('mergeMediaCandidates:', $panel);
        $this->assertStringContainsString('applyProfilePhoto:(payload = {}) => bridgeMediaApplyPhoto', $panel);
        $this->assertStringNotContainsString('applyNotionPhotoToProfile(photo, item = null){ return bridgeMediaApplyPhoto', $panel);
    }

    public function test_avatar_payload_resolver_selects_the_smallest_sufficient_thumbnail(): void
    {
        $resolver = new class {
            use ManagesWordPressAvatars;
        };
        $payload = serialize([
            'media_id' => 55761,
            96 => 'https://example.test/photo-150x150.png',
            250 => 'https://example.test/photo-300x300.png',
            500 => 'https://example.test/photo-768x768.png',
            'full' => 'https://example.test/photo.png',
        ]);

        $resolved = $resolver->resolveUserAvatarPayload($payload, 224);

        $this->assertSame('https://example.test/photo-300x300.png', $resolved['thumbnail_url']);
        $this->assertSame('https://example.test/photo.png', $resolved['full_url']);
        $this->assertSame(250, $resolved['selected_size']);
        $this->assertSame(55761, $resolved['media_id']);
    }
    public function test_provider_aware_avatar_reads_do_not_fall_back_to_stale_legacy_metadata(): void
    {
        $resolver = new class {
            use ManagesWordPressAvatars;
        };
        $simple = serialize([
            "media_id" => 120,
            250 => "https://example.test/simple-300x300.png",
            "full" => "https://example.test/simple.png",
        ]);
        $legacy = serialize([
            "media_id" => 999,
            250 => "https://example.test/legacy-300x300.png",
            "full" => "https://example.test/legacy.png",
        ]);

        $resolved = $resolver->normalizeUserAvatarForProvider([
            "simple_local_avatar" => $simple,
            "wp_user_avatars" => $legacy,
        ], "simple_local_avatars");

        $this->assertSame("simple_local_avatars", $resolved["avatar_provider"]);
        $this->assertSame("120", $resolved["avatar_media_id"]);
        $this->assertSame("https://example.test/simple-300x300.png", $resolved["avatar_url"]);

        $missing = $resolver->normalizeUserAvatarForProvider([
            "simple_local_avatar" => "",
            "wp_user_avatars" => $legacy,
        ], "simple_local_avatars");

        $this->assertSame("0", $missing["avatar_media_id"]);
        $this->assertSame("", $missing["avatar_url"]);
    }

    public function test_simple_local_avatar_runtime_is_resolved_once_for_reads_and_writes(): void
    {
        $root = dirname(__DIR__, 2);
        $avatars = (string) file_get_contents(
            $root . '/src/Services/Concerns/WordPressManager/ManagesWordPressAvatars.php',
        );
        $users = (string) file_get_contents(
            $root . '/src/Services/Concerns/WordPressManager/ManagesWordPressUsersAndMeta.php',
        );

        $this->assertStringContainsString('simpleLocalAvatarRuntimePhp()', $avatars);
        $this->assertStringContainsString('class_exists("Simple_Local_Avatars")', $avatars);
        $this->assertStringContainsString('new Simple_Local_Avatars()', $avatars);
        $this->assertStringContainsString('plugin_api_available', $avatars);
        $this->assertStringContainsString(
            'activeUserAvatarProvider($target, $forceRefresh)',
            $users,
        );
        $this->assertStringContainsString(
            'activeUserAvatarProvider($target, true)',
            $users,
        );
    }

    public function test_avatar_assignment_validates_pixels_and_repairs_corrupt_dynamic_sizes(): void
    {
        $root = dirname(__DIR__, 2);
        $avatars = (string) file_get_contents(
            $root . '/src/Services/Concerns/WordPressManager/ManagesWordPressAvatars.php',
        );

        $this->assertStringContainsString('hexa_avatar_image_integrity', $avatars);
        $this->assertStringContainsString('non_transparent_pixels', $avatars);
        $this->assertStringContainsString('hexa_rebuild_simple_avatar_sizes', $avatars);
        $this->assertStringContainsString('fully_transparent', $avatars);
        $this->assertStringContainsString('corrupt_sizes_regenerated_from_verified_source', $avatars);
        $this->assertStringContainsString('verified_source_fallback_after_derivative_failure', $avatars);
        $this->assertStringContainsString('avatar_runtime_warning', $avatars);
        $this->assertStringContainsString('$avatarIntegrity["cached_sizes"]', $avatars);
        $this->assertStringContainsString('$previousSimpleMediaId === $mediaId', $avatars);
        $this->assertStringNotContainsString('$simple->assign_new_user_avatar(', $avatars);
        $this->assertStringNotContainsString('$simple->avatar_delete(', $avatars);
        $this->assertStringContainsString('"assignment_reused" => $assignmentReused', $avatars);
        $this->assertStringContainsString('"avatar_integrity" => $avatarIntegrity', $avatars);
        $this->assertStringContainsString('"avatar_repair" => $avatarRepair', $avatars);
    }

    public function test_user_avatar_destination_uses_the_shared_site_cache_purge_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $destination = (string) file_get_contents(
            $root . '/src/Media/Destinations/UserAvatarDestination.php',
        );
        $gateway = (string) file_get_contents($root . '/src/Media/WordPressMediaGateway.php');

        $this->assertStringContainsString('CacheAwareWordPressMediaDestination', $destination);
        $this->assertStringContainsString('return $gateway->purgeSiteCache($target);', $destination);
        $this->assertStringContainsString('public function purgeSiteCache(array $target): array', $gateway);
        $this->assertStringContainsString('return $this->wordpress->purgeSiteCache($target);', $gateway);
    }

    public function test_media_operation_polling_asset_and_route_are_owned_by_wordpress_package(): void
    {
        $assets = app(PackageAssetRegistry::class)->assetsFor("wordpress");
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . "/routes/wordpress.php");
        $javascript = (string) file_get_contents($assets["media-operations.js"]);

        $this->assertArrayHasKey("media-operations.js", $assets);
        $this->assertStringContainsString("HexaWordPressMediaOperations", $javascript);
        $this->assertStringContainsString("/wordpress/media-operations/", $javascript);
        $this->assertStringContainsString("MediaOperationController", $routes);
        $this->assertStringContainsString("wordpress.media-operations.show", $routes);
    }

}
