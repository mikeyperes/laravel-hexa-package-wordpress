<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWordpress;

use Tests\TestCase;

class PackageSmokeTest extends TestCase
{
    public function test_package_manifest_config_and_provider_are_loadable(): void
    {
        $root = dirname(__DIR__, 2);
        $composerPath = $root . '/composer.json';
        $this->assertFileExists($composerPath);

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $this->assertIsArray($composer);
        $this->assertSame('hexawebsystems/laravel-hexa-package-wordpress', $composer['name'] ?? null);
        $this->assertArrayHasKey('autoload', $composer);

        $providers = $composer['extra']['laravel']['providers'] ?? [];
        $this->assertIsArray($providers);
        $this->assertNotEmpty($providers, 'Package must declare at least one Laravel provider.');
        foreach ($providers as $provider) {
            $this->assertTrue(class_exists($provider), "Provider {$provider} is not autoloadable.");
        }

        $configFiles = glob($root . '/config/*.php') ?: [];
        $this->assertNotEmpty($configFiles, 'Package must ship a config file with a version.');

        $hasVersion = false;
        foreach ($configFiles as $configFile) {
            $config = require $configFile;
            $this->assertIsArray($config, basename($configFile) . ' must return an array.');
            if (isset($config['version'])) {
                $hasVersion = true;
                $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $config['version']);
            }
        }

        $this->assertTrue($hasVersion, 'At least one package config file must expose a semantic version.');
        $this->assertInstanceOf(
            \hexa_package_wordpress\Media\WordPressMediaAssignmentService::class,
            app(\hexa_package_wordpress\Media\WordPressMediaAssignmentService::class)
        );
        $this->assertInstanceOf(
            \hexa_package_wordpress\Media\WordPressMediaOperationStore::class,
            app(\hexa_package_wordpress\Media\WordPressMediaOperationStore::class)
        );
        $this->assertInstanceOf(
            \hexa_package_wordpress\Services\WordPressPostSnapshotService::class,
            app(\hexa_package_wordpress\Services\WordPressPostSnapshotService::class)
        );
        $this->assertInstanceOf(
            \hexa_package_wordpress\Services\WordPressLoginUrlService::class,
            app(\hexa_package_wordpress\Services\WordPressLoginUrlService::class)
        );
        $this->assertFileExists($root.'/resources/views/post-workspace/shell.blade.php');
        $this->assertFileExists($root.'/resources/js/post-workspace.js');
        $this->assertFileExists($root.'/resources/js/post-workspace.css');
        $this->assertFileExists($root.'/docs/post-workspaces.md');

        $workspace = (string) file_get_contents($root.'/resources/views/post-workspace/shell.blade.php');
        $this->assertStringContainsString("route('hexa-package.asset'", $workspace);
        $this->assertStringContainsString("'asset' => 'post-workspace.css'", $workspace);
        $this->assertStringContainsString("'asset' => 'post-workspace.js'", $workspace);
        $version = $composer['version'] ?? null;
        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version);
        $this->assertSame($version, (require $root.'/config/wordpress.php')['version']);
    }

    public function test_workspace_login_preserves_the_native_first_form_submission(): void
    {
        $asset = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/post-workspace.js'
        );
        $start = strpos($asset, "root.querySelectorAll('[data-wordpress-login-form]')");
        $end = strpos($asset, 'window.setInterval', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $handler = substr($asset, $start, $end - $start);

        $this->assertStringContainsString('let submitting = false', $handler);
        $this->assertStringContainsString('if (submitting)', $handler);
        $this->assertStringContainsString('event.preventDefault()', $handler);
        $this->assertStringContainsString("window.open('about:blank', targetName)", $handler);
        $this->assertStringContainsString('form.submit()', $handler);
        $this->assertStringContainsString('popup.opener = null', $handler);
        $this->assertStringContainsString("button.setAttribute('aria-busy', 'true')", $handler);
        $this->assertStringNotContainsString('button.disabled', $handler);
        $this->assertLessThan(
            strpos($handler, "button.classList.add('is-loading')"),
            strpos($handler, 'window.setTimeout')
        );
    }
}
