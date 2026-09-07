<?php

namespace Tests\Unit;

use hexa_core\Security\Archives\ArchiveSecurityPolicy;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressPluginIntegrityService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use ZipArchive;

final class WordPressPluginIntegritySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_invalid_coordinates_are_rejected_before_http_or_wp_toolkit_transport(): void
    {
        $httpRequests = [];
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->expects($this->never())->method('wpCliEval');
        $service = $this->service($toolkit, [], $httpRequests);
        $server = new WhmServer();

        $this->assertFalse($service->inspectInstalledPlugin($server, 1, '../plugin')['success']);
        $this->assertFalse($service->inspectInstalledPlugin($server, 1, 'plugin', ['../plugin.php'])['success']);
        $this->assertFalse($service->collectPluginUsageStats($server, 1, "plugin\0escape", 'settings')['success']);
        $this->assertFalse($service->githubManifest('owner/repo/extra')['success']);
        $this->assertFalse($service->githubManifest('owner/repo', '../main')['success']);
        $this->assertFalse($service->githubManifest('owner/repo', 'main', '../plugin.php')['success']);
        $this->assertFalse($service->comparePluginToGithub($server, 1, 'Plugin', 'owner/repo')['success']);
        $this->assertFalse($service->updatePluginFromGithub($server, 1, 'plugin', 'https://github.com/owner/repo')['success']);
        $this->assertSame([], $httpRequests);
    }

    public function test_shared_archive_policy_rejects_traversal_and_symlink_entries(): void
    {
        $sha = str_repeat('a', 40);
        $archive = $this->zip([
            ['plugin-'.$sha.'/plugin.php', "<?php\n/* Plugin Name: Plugin */"],
            ['../outside.php', 'unsafe'],
            ['plugin-'.$sha.'/linked.php', 'plugin.php', 0120777],
        ]);
        $httpRequests = [];
        $service = $this->service(
            $this->createStub(WpToolkitService::class),
            $this->githubResponses($sha, $archive),
            $httpRequests,
        );

        $result = $service->githubManifest('owner/security-plugin', 'main', 'plugin.php');

        $this->assertFalse($result['success']);
        $this->assertSame('GitHub archive failed the shared archive security policy.', $result['message']);
        $errors = implode(' ', $result['archive_errors']);
        $this->assertStringContainsString('path traversal', $errors);
        $this->assertStringContainsString('symbolic links', $errors);
        $this->assertCount(2, $httpRequests);
    }

    public function test_valid_archive_is_hashed_without_extraction_and_returns_immutable_evidence(): void
    {
        $sha = str_repeat('b', 40);
        $main = "<?php\n/*\nPlugin Name: Secure Plugin\nVersion: 2.4.1\n*/\n";
        $helper = "<?php\nreturn true;\n";
        $archive = $this->zip([
            ['secure-plugin-'.$sha.'/', ''],
            ['secure-plugin-'.$sha.'/secure-plugin.php', $main],
            ['secure-plugin-'.$sha.'/src/helper.php', $helper],
        ]);
        $httpRequests = [];
        $service = $this->service(
            $this->createStub(WpToolkitService::class),
            $this->githubResponses($sha, $archive),
            $httpRequests,
        );

        $result = $service->githubManifest('owner/secure-plugin', 'release/2.4', 'secure-plugin.php');

        $this->assertTrue($result['success']);
        $this->assertSame($sha, $result['head']);
        $this->assertSame(hash('sha256', $archive), $result['archive_sha256']);
        $this->assertSame(strlen($archive), $result['archive_bytes']);
        $this->assertSame('2.4.1', $result['version']);
        $this->assertSame([
            ['path' => 'secure-plugin.php', 'sha256' => hash('sha256', $main), 'bytes' => strlen($main)],
            ['path' => 'src/helper.php', 'sha256' => hash('sha256', $helper), 'bytes' => strlen($helper)],
        ], $result['manifest']);
        $this->assertSame('https://api.github.com/repos/owner/secure-plugin/commits/release%2F2.4', $httpRequests[0]->target->url);
        $this->assertSame('https://github.com/owner/secure-plugin/archive/'.$sha.'.zip', $httpRequests[1]->target->url);
        $this->assertSame(1048576, $httpRequests[0]->maxResponseBytes);
        $this->assertSame(16777216, $httpRequests[1]->maxResponseBytes);

        $workspace = storage_path('app/wp-plugin-integrity');
        $this->assertSame(0700, fileperms($workspace) & 0777);
        $this->assertSame([], array_values(array_diff(scandir($workspace) ?: [], ['.', '..'])));
    }

    public function test_archive_with_missing_main_file_or_multiple_roots_fails_closed(): void
    {
        $missingSha = str_repeat('c', 40);
        $missingArchive = $this->zip([
            ['plugin-'.$missingSha.'/other.php', '<?php'],
        ]);
        $requests = [];
        $missing = $this->service(
            $this->createStub(WpToolkitService::class),
            $this->githubResponses($missingSha, $missingArchive),
            $requests,
        )->githubManifest('owner/missing-plugin', 'main', 'plugin.php');

        $this->assertFalse($missing['success']);
        $this->assertStringContainsString('requested plugin main file', $missing['message']);

        Cache::flush();
        $multipleSha = str_repeat('d', 40);
        $multipleArchive = $this->zip([
            ['first-'.$multipleSha.'/plugin.php', '<?php'],
            ['second-'.$multipleSha.'/other.php', '<?php'],
        ]);
        $requests = [];
        $multiple = $this->service(
            $this->createStub(WpToolkitService::class),
            $this->githubResponses($multipleSha, $multipleArchive),
            $requests,
        )->githubManifest('owner/multiple-plugin', 'main', 'plugin.php');

        $this->assertFalse($multiple['success']);
        $this->assertStringContainsString('one root directory', $multiple['message']);
    }

    public function test_compressed_archive_download_is_bounded_before_zip_processing(): void
    {
        $sha = str_repeat('e', 40);
        $httpRequests = [];
        $service = $this->service(
            $this->createStub(WpToolkitService::class),
            [
                new OutboundHttpResponse(200, ['Content-Type' => 'application/json'], json_encode(['sha' => $sha], JSON_THROW_ON_ERROR)),
                new OutboundHttpResponse(200, ['Content-Type' => 'application/zip'], str_repeat('x', 16777217)),
            ],
            $httpRequests,
        );

        $result = $service->githubManifest('owner/oversized-plugin', 'main', 'plugin.php');

        $this->assertFalse($result['success']);
        $this->assertSame('GitHub archive download failed: response_too_large.', $result['message']);
        $this->assertCount(2, $httpRequests);
        $this->assertSame(16777216, $httpRequests[1]->maxResponseBytes);
    }

    public function test_plugin_archive_entry_count_is_bounded_before_manifest_hashing(): void
    {
        $sha = str_repeat('1', 40);
        $entries = [];
        for ($index = 0; $index < 10001; $index++) {
            $entries[] = ['plugin-'.$sha.'/file-'.$index.'.php', ''];
        }
        $archive = $this->zip($entries);
        $httpRequests = [];
        $service = $this->service(
            $this->createStub(WpToolkitService::class),
            $this->githubResponses($sha, $archive),
            $httpRequests,
        );

        $result = $service->githubManifest('owner/too-many-files', 'main');

        $this->assertFalse($result['success']);
        $this->assertSame('GitHub archive exceeded plugin manifest limits.', $result['message']);
    }

    public function test_remote_update_uses_verified_streaming_wordpress_install_and_rollback_path(): void
    {
        $sha = str_repeat('f', 40);
        $main = "<?php\n/* Plugin Name: Plugin\nVersion: 3.0.0 */\n";
        $archive = $this->zip([
            ['plugin-'.$sha.'/plugin.php', $main],
        ]);
        $archiveHash = hash('sha256', $archive);
        $capturedScript = '';
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->expects($this->once())
            ->method('wpCliEval')
            ->willReturnCallback(function (WhmServer $server, int $installId, string $script) use (&$capturedScript, $archiveHash): array {
                $capturedScript = $script;

                return [
                    'success' => true,
                    'stdout' => 'notice'.PHP_EOL.'HEXA_PLUGIN_UPDATE:'.json_encode([
                        'success' => true,
                        'message' => 'Plugin updated from the verified GitHub archive.',
                        'archive_sha256' => $archiveHash,
                    ], JSON_THROW_ON_ERROR),
                ];
            });
        $httpRequests = [];
        $service = $this->service($toolkit, $this->githubResponses($sha, $archive), $httpRequests);

        $result = $service->updatePluginFromGithub(new WhmServer(), 42, 'plugin', 'owner/plugin', 'main', 'plugin.php');

        $this->assertTrue($result['success']);
        $this->assertSame($archiveHash, $result['archive_sha256']);
        $this->assertStringContainsString('wp_safe_remote_get(', $capturedScript);
        $this->assertStringContainsString('"stream" => true', $capturedScript);
        $this->assertStringContainsString('"limit_response_size" => $maxArchiveBytes + 1', $capturedScript);
        $this->assertStringContainsString('hash_equals($archiveSha256, $downloadSha256)', $capturedScript);
        $this->assertStringContainsString('unzip_file($zipPath, $extractDir)', $capturedScript);
        $this->assertStringContainsString('WP_Filesystem()', $capturedScript);
        $this->assertStringContainsString('$rollback = function ()', $capturedScript);
        $this->assertStringContainsString('$wp_filesystem->delete($zipPath, false)', $capturedScript);
        $this->assertStringContainsString('https://github.com/owner/plugin/archive/'.$sha.'.zip', $capturedScript);
        $this->assertStringContainsString($archiveHash, $capturedScript);
        $this->assertStringNotContainsString('new ZipArchive', $capturedScript);
        $this->assertStringNotContainsString('extractTo(', $capturedScript);
        $this->assertStringNotContainsString('wp_remote_retrieve_body', $capturedScript);

        $archiveValidation = strpos($capturedScript, 'count($roots) !== 1');
        $targetMutation = strpos($capturedScript, '!@rename($target, $backup)');
        $this->assertIsInt($archiveValidation);
        $this->assertIsInt($targetMutation);
        $this->assertLessThan($targetMutation, $archiveValidation);
    }

    public function test_installed_manifest_payload_is_parsed_without_exposing_remote_transport(): void
    {
        $capturedScript = '';
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->expects($this->once())
            ->method('wpCliEval')
            ->willReturnCallback(function (WhmServer $server, int $installId, string $script) use (&$capturedScript): array {
                $capturedScript = $script;

                return [
                    'success' => true,
                    'stdout' => 'HEXA_PLUGIN_MANIFEST:'.json_encode([
                        'slug' => 'plugin',
                        'found' => true,
                        'active' => true,
                        'plugin_file' => 'plugin/plugin.php',
                        'bootstrap_file' => 'plugin.php',
                        'version' => '1.2.3',
                        'manifest_error' => '',
                        'manifest' => [
                            'plugin.php' => ['sha256' => str_repeat('a', 64), 'bytes' => 12],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            });
        $httpRequests = [];
        $service = $this->service($toolkit, [], $httpRequests);

        $result = $service->inspectInstalledPlugin(new WhmServer(), 7, 'plugin', ['plugin.php']);

        $this->assertTrue($result['success']);
        $this->assertSame('1.2.3', $result['plugin']['version']);
        $this->assertSame([
            ['path' => 'plugin.php', 'sha256' => str_repeat('a', 64), 'bytes' => 12],
        ], $result['manifest']);
        $this->assertStringContainsString('is_link($root)', $capturedScript);
        $this->assertStringContainsString('$entryCount > $maxManifestEntries', $capturedScript);
        $this->assertStringContainsString('$bytes > $maxPluginFileBytes', $capturedScript);
        $this->assertStringContainsString('$remaining = ($maxPluginFileBytes + 1) - $hashedBytes', $capturedScript);
        $this->assertStringContainsString('$hashedBytes !== $bytes', $capturedScript);
        $this->assertStringContainsString('!str_starts_with($path, trailingslashit($rootReal))', $capturedScript);
        $this->assertSame([], $httpRequests);
    }

    /**
     * @param list<OutboundHttpResponse> $responses
     * @param list<OutboundHttpRequest> $requests
     */
    private function service(WpToolkitService $toolkit, array $responses, array &$requests): WordPressPluginIntegrityService
    {
        $guard = new OutboundUrlGuard(static fn (string $host): array => match ($host) {
            'api.github.com', 'github.com' => ['93.184.216.34'],
            default => [],
        });
        $http = new SafeOutboundHttpClient(
            $guard,
            static function (OutboundHttpRequest $request) use (&$requests, &$responses): OutboundHttpResponse {
                $requests[] = $request;
                $response = array_shift($responses);
                if (!$response instanceof OutboundHttpResponse) {
                    throw new \RuntimeException('Unexpected outbound request in test.');
                }

                return $response;
            },
        );

        return new WordPressPluginIntegrityService(
            $toolkit,
            $http,
            new ArchiveSecurityPolicy(),
        );
    }

    /** @return list<OutboundHttpResponse> */
    private function githubResponses(string $sha, string $archive): array
    {
        return [
            new OutboundHttpResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['sha' => $sha], JSON_THROW_ON_ERROR),
            ),
            new OutboundHttpResponse(200, ['Content-Type' => 'application/zip'], $archive),
        ];
    }

    /**
     * @param list<array{0:string,1:string,2?:int}> $entries
     */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wordpress-plugin-security-');
        $this->assertNotFalse($path);

        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not create the ZIP test fixture.');
        }
        foreach ($entries as $entry) {
            [$name, $contents] = $entry;
            $mode = $entry[2] ?? null;
            if (str_ends_with($name, '/')) {
                $added = $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $added = $zip->addFromString($name, $contents);
            }
            if (!$added) {
                throw new \RuntimeException('Could not add an entry to the ZIP test fixture.');
            }
            if (isset($mode) && !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16)) {
                throw new \RuntimeException('Could not set ZIP test fixture attributes.');
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Could not finalize the ZIP test fixture.');
        }

        try {
            $contents = file_get_contents($path);
            $this->assertIsString($contents);

            return $contents;
        } finally {
            @unlink($path);
        }
    }
}
