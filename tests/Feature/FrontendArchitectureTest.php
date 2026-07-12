<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWordpress;

use hexa_core\Support\PackageAssetRegistry;
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
    }
}
