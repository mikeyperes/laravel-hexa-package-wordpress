<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** CAMPAIGN-BUG-136: one manifest reader; hosted sites answer in-process, never over Cloudflare. */
class WordPressPublicationManifestTest extends TestCase
{
    public function test_hosted_site_reads_the_manifest_in_process_without_http(): void
    {
        $manifest = ['schema_version' => 3, 'categories' => [['name' => 'Business']]];
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->never())->method('discoverPublicationManifest');

        $result = $this->hosted('noise before HEXA_MANIFEST_BEGIN'.json_encode(['status' => 200, 'data' => $manifest]).'HEXA_MANIFEST_END trailing', $rest)
            ->publicationManifest([]);

        $this->assertTrue($result['success']);
        $this->assertSame('available', $result['state']);
        $this->assertSame(200, $result['status']);
        $this->assertSame($manifest, $result['data']);
    }

    #[DataProvider('failures')]
    public function test_every_hosted_failure_maps_to_one_state(string $stdout, string $state, ?int $status): void
    {
        $result = $this->hosted($stdout)->publicationManifest([]);

        $this->assertFalse($result['success']);
        $this->assertSame($state, $result['state']);
        $this->assertSame($status, $result['status']);
        $this->assertNull($result['data']);
    }

    public static function failures(): array
    {
        $wrap = fn (array $payload): string => 'HEXA_MANIFEST_BEGIN'.json_encode($payload).'HEXA_MANIFEST_END';

        return [
            'plugin missing' => [$wrap(['status' => 404, 'data' => ['code' => 'rest_no_route']]), 'not_detected', 404],
            'server error' => [$wrap(['status' => 500, 'data' => ['code' => 'boom']]), 'http_error', 500],
            'ok but list body' => [$wrap(['status' => 200, 'data' => [1, 2]]), 'http_error', 200],
            'ok but empty body' => [$wrap(['status' => 200, 'data' => null]), 'http_error', 200],
            'no markers' => ['PHP Fatal error: Uncaught Error', 'transport_error', null],
            'broken json' => ['HEXA_MANIFEST_BEGIN{"status":HEXA_MANIFEST_END', 'transport_error', null],
            'empty output' => ['', 'transport_error', null],
        ];
    }

    public function test_external_site_uses_rest_and_a_missing_url_fails_without_a_request(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('discoverPublicationManifest')->with('https://example.test')
            ->willReturn(['success' => true, 'state' => 'available', 'status' => 200, 'data' => ['schema_version' => 3], 'message' => 'ok']);
        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);

        $this->assertTrue($manager->publicationManifest(['mode' => 'rest', 'url' => 'https://example.test'])['success']);

        $missing = (new WordPressManagerService($this->createMock(WpToolkitService::class), $this->createMock(WordPressService::class)))
            ->publicationManifest(['mode' => 'rest', 'url' => '']);
        $this->assertSame('invalid_url', $missing['state']);
    }

    private function hosted(string $stdout, ?WordPressService $rest = null): WordPressManagerService
    {
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->method('wpCliEvalWithPlugins')->willReturnCallback(function ($server, $installId, string $php) use ($stdout): array {
            $this->assertSame(17, $installId);
            $this->assertStringContainsString('rest_do_request', $php);
            $this->assertStringContainsString('/smpi/v1/publication-manifest', $php);

            return ['success' => true, 'stdout' => $stdout, 'message' => '', 'exit_code' => 0];
        });

        return new class($toolkit, $rest ?? $this->createMock(WordPressService::class), new WhmServer()) extends WordPressManagerService
        {
            public function __construct(WpToolkitService $toolkit, WordPressService $rest, private WhmServer $server)
            {
                parent::__construct($toolkit, $rest);
            }

            public function normalizeTarget(array $target): array
            {
                return ['mode' => 'wptoolkit', 'server' => $this->server, 'install_id' => 17, 'url' => 'https://hosted.test', 'default_author' => ''];
            }

            public function usesWpToolkit(array $target): bool
            {
                return true;
            }
        };
    }
}
