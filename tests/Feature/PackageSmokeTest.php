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
    }
}
