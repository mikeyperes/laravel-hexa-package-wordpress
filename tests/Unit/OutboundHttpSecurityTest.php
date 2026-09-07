<?php

namespace Tests\Unit;

use Closure;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_media\Inspection\ImageInspector;
use hexa_package_media\Transfer\TemporaryMediaResourceManager;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Http\Controllers\WordPressController;
use hexa_package_wordpress\Services\WordPressHttpTransport;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressMediaSourceService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class OutboundHttpSecurityTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Application(dirname(__DIR__, 5));
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_article_metadata_blocks_private_targets_before_transport(): void
    {
        $calls = 0;
        $service = $this->service($this->transport(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '<html><title>Unexpected</title></html>');
        }));

        $result = (new AuditableWordPressController($service))->fetchMetadata('http://127.0.0.1/private');

        $this->assertFalse($result['success']);
        $this->assertSame('Fetch failed securely.', $result['message']);
        $this->assertSame(0, $calls);
    }

    public function test_article_metadata_uses_pinned_tls_and_revalidates_redirects(): void
    {
        $requests = [];
        $service = $this->service($this->transport(function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            $requests[] = $request;

            return new OutboundHttpResponse(200, ['Content-Type' => 'text/html'], '<meta property="og:title" content="Guarded article">');
        }));

        $result = (new AuditableWordPressController($service))->fetchMetadata('https://public.example.org/article');

        $this->assertTrue($result['success']);
        $this->assertSame('Guarded article', $result['title']);
        $this->assertCount(1, $requests);
        $this->assertPinnedRequest($requests[0]);

        $redirectRequests = [];
        $redirected = $this->service($this->transport(function (OutboundHttpRequest $request) use (&$redirectRequests): OutboundHttpResponse {
            $redirectRequests[] = $request;

            return new OutboundHttpResponse(302, ['Location' => 'http://127.0.0.1/private'], '');
        }));

        $rejected = (new AuditableWordPressController($redirected))->fetchMetadata('https://public.example.org/article');

        $this->assertFalse($rejected['success']);
        $this->assertSame('Fetch failed securely.', $rejected['message']);
        $this->assertCount(1, $redirectRequests);
    }

    public function test_acf_education_lookup_uses_one_verified_bounded_wikipedia_request(): void
    {
        $requests = [];
        $service = $this->service($this->transport(function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            $requests[] = $request;

            return new OutboundHttpResponse(200, ['Content-Type' => 'application/json'], json_encode([
                'query' => [
                    'pages' => [[
                        'pageid' => 123,
                        'title' => 'Example University',
                    ]],
                ],
            ], JSON_THROW_ON_ERROR));
        }));

        $result = (new AcfEducationMetadataService($service))->lookupMany(['Example University']);

        $this->assertTrue($result['items'][0]['success']);
        $this->assertCount(1, $requests);
        $this->assertSame('en.wikipedia.org', $requests[0]->target->host);
        $this->assertSame(512 * 1024, $requests[0]->maxResponseBytes);
        $this->assertPinnedRequest($requests[0]);
    }

    public function test_remote_media_download_and_upload_use_verified_pinned_transports(): void
    {
        $downloadRequests = [];
        $uploadRequests = [];
        $guard = $this->guard();
        $transport = new WordPressHttpTransport(
            new SafeOutboundHttpClient(
                $guard,
                function (OutboundHttpRequest $request) use (&$downloadRequests): OutboundHttpResponse {
                    $downloadRequests[] = $request;

                    return new OutboundHttpResponse(
                        200,
                        ['Content-Type' => 'image/png'],
                        base64_decode(self::PNG_1X1, true),
                    );
                },
            ),
            $guard,
            function (OutboundHttpRequest $request) use (&$uploadRequests): OutboundHttpResponse {
                $uploadRequests[] = $request;

                return new OutboundHttpResponse(
                    201,
                    ['Content-Type' => 'application/json'],
                    '{"id":77,"source_url":"https://wordpress.example.org/uploads/photo.png","title":{"rendered":"Photo"}}',
                );
            },
        );

        $result = $this->service($transport)->uploadMedia(
            'https://wordpress.example.org',
            'editor',
            'app-password',
            'https://images.example.org/photo.png',
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $downloadRequests);
        $this->assertCount(1, $uploadRequests);
        $this->assertSame(WordPressHttpTransport::MAX_IMAGE_BYTES, $downloadRequests[0]->maxResponseBytes);
        $this->assertPinnedRequest($downloadRequests[0]);
        $this->assertPinnedRequest($uploadRequests[0]);
        $this->assertNull($uploadRequests[0]->body);
    }

    public function test_media_manager_rejects_private_remote_sources_before_dispatch(): void
    {
        $calls = 0;
        $service = $this->service($this->transport(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, ['Content-Type' => 'image/png'], base64_decode(self::PNG_1X1, true));
        }));
        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $service);

        $result = $manager->uploadMedia([
            'mode' => 'rest',
            'url' => 'https://wordpress.example.org',
            'username' => 'editor',
            'application_password' => 'app-password',
        ], 'http://127.0.0.1/private.png');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $calls);
    }

    public function test_favicon_fallback_uses_verified_guarded_requests(): void
    {
        $requests = [];
        $service = $this->service($this->transport(function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            $requests[] = $request;

            return new OutboundHttpResponse(
                200,
                ['Content-Type' => 'text/html'],
                '<html><link rel="icon" href="/icon.png"></html>',
            );
        }));
        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $service);

        $method = new ReflectionMethod(WordPressManagerService::class, 'discoverSiteIconFallback');
        $result = $method->invoke($manager, 'https://wordpress.example.org');

        $this->assertSame('https://wordpress.example.org/icon.png', $result['url']);
        $this->assertSame('html_icon_link', $result['source']);
        $this->assertCount(1, $requests);
        $this->assertPinnedRequest($requests[0]);
    }

    private function service(WordPressHttpTransport $transport): WordPressService
    {
        return new WordPressService(
            $transport,
            new WordPressMediaSourceService(
                $transport,
                new ImageInspector,
                new TemporaryMediaResourceManager,
            ),
        );
    }

    private function transport(Closure $transport): WordPressHttpTransport
    {
        $guard = $this->guard();

        return new WordPressHttpTransport(
            new SafeOutboundHttpClient($guard, $transport),
            $guard,
        );
    }

    private function guard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard(static fn (string $host): array => match ($host) {
            'public.example.org', 'wordpress.example.org', 'images.example.org', 'en.wikipedia.org' => ['93.184.216.34'],
            default => [],
        });
    }

    private function assertPinnedRequest(OutboundHttpRequest $request): void
    {
        $options = $request->curlOptions();

        $this->assertSame(['93.184.216.34'], $request->target->addresses);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame('', $options[CURLOPT_PROXY]);
        $this->assertNotEmpty($options[CURLOPT_RESOLVE]);
    }
}

final class AuditableWordPressController extends WordPressController
{
    public function fetchMetadata(string $url): array
    {
        return $this->fetchArticleMetadataForUrl($url);
    }
}
