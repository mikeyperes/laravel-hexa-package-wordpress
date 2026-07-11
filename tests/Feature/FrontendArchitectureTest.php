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
}
