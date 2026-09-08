<?php

namespace Tests\Unit;

use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_media\Exceptions\MediaPipelineException;
use hexa_package_media\Inspection\ImageInspector;
use hexa_package_media\Transfer\TemporaryMediaResourceManager;
use hexa_package_whm\Models\WhmServer;
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
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WordPressSecureTransportTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Application(dirname(__DIR__, 5));
        $container->instance('log', new NullLogger);
        $translator = new Translator(new ArrayLoader, 'en');
        $container->instance('translator', $translator);
        $container->instance('validator', new ValidatorFactory($translator, $container));
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

    public function test_authenticated_transport_requires_https_before_network_execution(): void
    {
        $calls = 0;
        $transport = $this->transport(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '{}');
        });

        try {
            $transport->authenticatedJson('GET', 'http://public.example.com/wp-json/wp/v2/users/me', 'editor', 'secret');
            $this->fail('An authenticated HTTP target must be rejected.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('invalid_request', $exception->failureCode());
        }

        $this->assertSame(0, $calls);
    }

    public function test_private_targets_are_rejected_before_transport_execution(): void
    {
        $calls = 0;
        $transport = $this->transport(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '{}');
        });

        try {
            $transport->authenticatedJson('GET', 'https://127.0.0.1/wp-json/wp/v2/users/me', 'editor', 'secret');
            $this->fail('A loopback target must be rejected.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('target_rejected', $exception->failureCode());
        }

        $this->assertSame(0, $calls);
    }

    public function test_authenticated_redirects_are_not_followed_or_replayed(): void
    {
        $requests = [];
        $transport = $this->transport(static function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            $requests[] = $request;

            return new OutboundHttpResponse(302, ['location' => 'https://other.example.com/wp-json/wp/v2/users/me'], '');
        });

        try {
            $transport->authenticatedJson('GET', 'https://wordpress.example.com/wp-json/wp/v2/users/me', 'editor', 'secret');
            $this->fail('Authenticated redirects must not be followed.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('redirect_limit', $exception->failureCode());
        }

        $this->assertCount(1, $requests);
        $this->assertArrayHasKey('Authorization', $requests[0]->headers);
        $this->assertNull($requests[0]->body);
    }

    public function test_public_redirects_are_revalidated_and_private_destinations_are_blocked(): void
    {
        $requests = [];
        $transport = $this->transport(static function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            $requests[] = $request;

            return new OutboundHttpResponse(302, ['location' => 'http://127.0.0.1/private'], '');
        });

        try {
            $transport->publicGet('https://news.example.com/article', maxRedirects: 2);
            $this->fail('A private redirect destination must be rejected.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('redirect_invalid', $exception->failureCode());
        }

        $this->assertCount(1, $requests);
    }

    public function test_media_upload_streams_a_validated_file_without_putting_bytes_or_credentials_in_the_url(): void
    {
        $path = $this->imagePath();
        $captured = [];
        $guard = $this->guard();
        $client = new SafeOutboundHttpClient($guard, static fn (): OutboundHttpResponse => new OutboundHttpResponse(500, [], '{}'));
        $transport = new WordPressHttpTransport(
            $client,
            $guard,
            static function (OutboundHttpRequest $request, string $streamPath, int $bytes) use (&$captured): OutboundHttpResponse {
                $captured = compact('request', 'streamPath', 'bytes');

                return new OutboundHttpResponse(201, ['content-type' => 'application/json'], '{"id":44}');
            },
        );

        try {
            $response = $transport->uploadImage(
                'https://wordpress.example.com/wp-json/wp/v2/media',
                'editor',
                'sensitive-password',
                $path,
                " unsafe\r\nname.png ",
                'image/png',
                (int) filesize($path),
            );

            $request = $captured['request'];
            $this->assertTrue($response->successful());
            $this->assertSame($path, $captured['streamPath']);
            $this->assertSame(filesize($path), $captured['bytes']);
            $this->assertNull($request->body);
            $this->assertSame('POST', $request->method);
            $this->assertStringNotContainsString('sensitive-password', $request->target->url);
            $this->assertStringNotContainsString("\r", $request->headers['Content-Disposition']);
            $this->assertStringNotContainsString("\n", $request->headers['Content-Disposition']);
            $this->assertSame('attachment; filename="unsafe-name.png"', $request->headers['Content-Disposition']);
            $this->assertSame(['93.184.216.34'], $request->target->addresses);
            $curlOptions = $request->curlOptions();
            $this->assertFalse($curlOptions[CURLOPT_FOLLOWLOCATION]);
            $this->assertTrue($curlOptions[CURLOPT_SSL_VERIFYPEER]);
            $this->assertSame(2, $curlOptions[CURLOPT_SSL_VERIFYHOST]);
            $this->assertSame('', $curlOptions[CURLOPT_PROXY]);
            $this->assertNotEmpty($curlOptions[CURLOPT_RESOLVE]);
        } finally {
            @unlink($path);
        }
    }

    public function test_stream_upload_preserves_media_larger_than_the_core_json_body_limit(): void
    {
        $path = $this->imagePath();
        file_put_contents($path, str_repeat("\0", 1024 * 1024), FILE_APPEND);
        $bytes = (int) filesize($path);
        $captured = [];
        $guard = $this->guard();
        $client = new SafeOutboundHttpClient($guard, static fn (): OutboundHttpResponse => new OutboundHttpResponse(500, [], '{}'));
        $transport = new WordPressHttpTransport(
            $client,
            $guard,
            static function (OutboundHttpRequest $request, string $streamPath, int $streamBytes) use (&$captured): OutboundHttpResponse {
                $captured = compact('request', 'streamPath', 'streamBytes');

                return new OutboundHttpResponse(201, ['content-type' => 'application/json'], '{"id":45}');
            },
        );

        try {
            $response = $transport->uploadImage(
                'https://wordpress.example.com/wp-json/wp/v2/media',
                'editor',
                'sensitive-password',
                $path,
                'large-image.png',
                'image/png',
                $bytes,
            );

            $this->assertTrue($response->successful());
            $this->assertGreaterThan(1024 * 1024, $captured['streamBytes']);
            $this->assertSame($path, $captured['streamPath']);
            $this->assertNull($captured['request']->body);
        } finally {
            @unlink($path);
        }
    }

    public function test_remote_media_must_be_a_supported_bounded_image(): void
    {
        $transport = $this->transport(static fn (): OutboundHttpResponse => new OutboundHttpResponse(
            200,
            ['content-type' => 'text/html'],
            '<html>not an image</html>',
        ));
        $source = new WordPressMediaSourceService(
            $transport,
            new ImageInspector,
            new TemporaryMediaResourceManager,
        );

        $this->expectException(MediaPipelineException::class);
        $this->expectExceptionMessage('supported raster image');
        $source->acquire('https://images.example.com/not-an-image');
    }

    public function test_article_metadata_rejects_loopback_without_leaking_transport_details(): void
    {
        $calls = 0;
        $service = $this->service($this->transport(static function () use (&$calls): OutboundHttpResponse {
            $calls++;
            throw new \RuntimeException('internal secret');
        }));
        $controller = new ExposedWordPressController($service);

        $result = $controller->fetchMetadata('http://127.0.0.1/private');

        $this->assertFalse($result['success']);
        $this->assertSame('Article metadata could not be fetched safely.', $result['message']);
        $this->assertStringNotContainsString('secret', $result['message']);
        $this->assertSame(0, $calls);
    }

    public function test_education_lookup_batches_all_name_variants_into_two_bounded_requests(): void
    {
        $requests = [];
        $responses = [
            new OutboundHttpResponse(200, ['content-type' => 'application/json'], json_encode([
                'query' => ['pages' => [['pageid' => -1, 'title' => 'Missing', 'missing' => true]]],
            ], JSON_THROW_ON_ERROR)),
            new OutboundHttpResponse(200, ['content-type' => 'application/json'], json_encode([
                'query' => ['search' => [['title' => 'Alpha and Beta Academy']]],
            ], JSON_THROW_ON_ERROR)),
        ];
        $client = new SafeOutboundHttpClient($this->guard(), static function (OutboundHttpRequest $request) use (&$requests, &$responses): OutboundHttpResponse {
            $requests[] = $request;

            return array_shift($responses);
        });
        $lookup = new AcfEducationMetadataService($client);

        $result = $lookup->lookupMany(['Alpha + Beta Academy']);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['items'][0]['success']);
        $this->assertSame('Alpha and Beta Academy', $result['items'][0]['title']);
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('titles=', $requests[0]->target->url);
        $this->assertStringContainsString('%7C', $requests[0]->target->url);
        $this->assertStringContainsString('srsearch=', $requests[1]->target->url);
        $this->assertSame(256 * 1024, $requests[0]->maxResponseBytes);
    }

    public function test_site_icon_discovery_ignores_private_html_targets_and_validates_the_root_icon_bytes(): void
    {
        $requests = [];
        $responses = [
            new OutboundHttpResponse(
                200,
                ['content-type' => 'text/html'],
                '<html><head><link rel="icon" href="http://127.0.0.1/private.png"></head></html>',
            ),
            new OutboundHttpResponse(
                200,
                ['content-type' => 'image/png'],
                base64_decode(self::PNG_1X1, true),
            ),
        ];
        $transport = $this->transport(static function (OutboundHttpRequest $request) use (&$requests, &$responses): OutboundHttpResponse {
            $requests[] = $request;

            return array_shift($responses);
        });
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->expects($this->once())
            ->method('wpCliEvalWithPlugins')
            ->willReturn([
                'success' => true,
                'stdout' => 'HEXA_SITE_ICON:{"success":true,"site_icon_id":0,"site_icon_url":"","source":"none"}',
            ]);
        $manager = new WordPressManagerService($toolkit, $this->service($transport));
        $server = new WhmServer;
        $server->id = 9;

        $result = $manager->getSiteIcon([
            'mode' => 'wptoolkit',
            'url' => 'https://wordpress.example.com',
            'server' => $server,
            'install_id' => 7,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://wordpress.example.com/favicon.ico', $result['site_icon_url']);
        $this->assertSame('root_favicon_ico', $result['source']);
        $this->assertCount(2, $requests);
        $this->assertSame(512 * 1024, $requests[0]->maxResponseBytes);
        $this->assertSame(64 * 1024, $requests[1]->maxResponseBytes);
    }

    public function test_json_request_bodies_over_one_megabyte_fail_before_network_execution(): void
    {
        $calls = 0;
        $transport = $this->transport(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '{}');
        });

        try {
            $transport->authenticatedJson(
                'POST',
                'https://wordpress.example.com/wp-json/wp/v2/posts',
                'editor',
                'password',
                ['content' => str_repeat('x', 1024 * 1024)],
            );
            $this->fail('An oversized JSON request must be rejected.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('request_body_too_large', $exception->failureCode());
        }

        $this->assertSame(0, $calls);
    }

    public function test_public_response_limits_are_enforced_before_callers_receive_content(): void
    {
        $transport = $this->transport(static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, [], '12345'));

        try {
            $transport->publicGet('https://news.example.com/article', maxResponseBytes: 4);
            $this->fail('An oversized public response must be rejected.');
        } catch (OutboundHttpException $exception) {
            $this->assertSame('response_too_large', $exception->failureCode());
        }
    }

    public function test_transport_failures_return_a_generic_message_without_exception_secrets(): void
    {
        $service = $this->service($this->transport(static function (): OutboundHttpResponse {
            throw new \RuntimeException('token=super-secret');
        }));

        $result = $service->request(
            'https://wordpress.example.com',
            'editor',
            'password',
            'get',
            'users/me',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('The WordPress site could not be reached securely.', $result['message']);
        $this->assertStringNotContainsString('super-secret', $result['message']);
    }

    public function test_service_maps_connection_categories_and_tags_through_the_shared_boundary(): void
    {
        $responses = [
            new OutboundHttpResponse(200, [], '{"id":7,"name":"Editor","slug":"editor","roles":["editor"]}'),
            new OutboundHttpResponse(200, [], '[{"id":2,"name":"News","slug":"news","count":4}]'),
            new OutboundHttpResponse(200, [], '[{"id":3,"name":"Local","slug":"local","count":5}]'),
        ];
        $requests = [];
        $service = $this->service($this->transport(static function (OutboundHttpRequest $request) use (&$requests, &$responses): OutboundHttpResponse {
            $requests[] = $request;

            return array_shift($responses);
        }));

        $connection = $service->testConnection('https://wordpress.example.com', 'editor', 'password');
        $categories = $service->getCategories('https://wordpress.example.com', 'editor', 'password');
        $tags = $service->getTags('https://wordpress.example.com', 'editor', 'password');

        $this->assertTrue($connection['success']);
        $this->assertSame(7, $connection['data']['user_id']);
        $this->assertSame(['editor'], $connection['data']['roles']);
        $this->assertSame([['id' => 2, 'name' => 'News', 'slug' => 'news', 'count' => 4]], $categories['data']);
        $this->assertSame([['id' => 3, 'name' => 'Local', 'slug' => 'local', 'count' => 5]], $tags['data']);
        $this->assertCount(3, $requests);
        $this->assertStringEndsWith('/users/me', $requests[0]->target->url);
        $this->assertStringContainsString('per_page=100', $requests[1]->target->url);
    }

    public function test_service_preserves_create_update_and_get_post_response_contracts(): void
    {
        $post = [
            'id' => 91,
            'link' => 'https://wordpress.example.com/?p=91',
            'status' => 'draft',
            'title' => ['rendered' => 'Transport post'],
            'date' => '2026-09-07T12:00:00',
        ];
        $responses = array_fill(0, 3, new OutboundHttpResponse(200, [], json_encode($post, JSON_THROW_ON_ERROR)));
        $requests = [];
        $service = $this->service($this->transport(static function (OutboundHttpRequest $request) use (&$requests, &$responses): OutboundHttpResponse {
            $requests[] = $request;

            return array_shift($responses);
        }));

        $created = $service->createPost('https://wordpress.example.com', 'editor', 'password', [
            'title' => 'Transport post',
            'content' => '<p>Body</p>',
            'status' => 'draft',
        ]);
        $updated = $service->updatePost('https://wordpress.example.com', 'editor', 'password', 91, ['status' => 'draft']);
        $fetched = $service->getPost('https://wordpress.example.com', 'editor', 'password', 91);

        $this->assertTrue($created['success']);
        $this->assertTrue($updated['success']);
        $this->assertTrue($fetched['success']);
        $this->assertSame(91, $created['data']['post_id']);
        $this->assertSame('Transport post', $updated['data']['post_title']);
        $this->assertSame('2026-09-07T12:00:00', $fetched['data']['post_date']);
        $this->assertStringEndsWith('/posts', $requests[0]->target->url);
        $this->assertStringEndsWith('/posts/91', $requests[1]->target->url);
        $this->assertStringContainsString('context=edit', $requests[2]->target->url);
    }

    public function test_service_sanitizes_remote_errors_and_rejects_malformed_success_json(): void
    {
        $responses = [
            new OutboundHttpResponse(400, [], '{"message":"<b>Rejected</b>\\r\\nunsafe"}'),
            new OutboundHttpResponse(200, [], '<html>not json</html>'),
        ];
        $service = $this->service($this->transport(static function () use (&$responses): OutboundHttpResponse {
            return array_shift($responses);
        }));

        $error = $service->request('https://wordpress.example.com', 'editor', 'password', 'get', 'users/me');
        $malformed = $service->request('https://wordpress.example.com', 'editor', 'password', 'get', 'users/me');

        $this->assertFalse($error['success']);
        $this->assertSame('Rejected unsafe', $error['message']);
        $this->assertStringNotContainsString('<b>', $error['message']);
        $this->assertFalse($malformed['success']);
        $this->assertSame('WordPress returned malformed JSON.', $malformed['message']);
    }

    public function test_service_uploads_validated_media_as_a_stream_and_updates_alt_text(): void
    {
        $path = $this->imagePath();
        $guard = $this->guard();
        $altRequests = [];
        $uploadRequests = [];
        $client = new SafeOutboundHttpClient($guard, static function (OutboundHttpRequest $request) use (&$altRequests): OutboundHttpResponse {
            $altRequests[] = $request;

            return new OutboundHttpResponse(200, [], '{"id":77}');
        });
        $transport = new WordPressHttpTransport(
            $client,
            $guard,
            static function (OutboundHttpRequest $request) use (&$uploadRequests): OutboundHttpResponse {
                $uploadRequests[] = $request;

                return new OutboundHttpResponse(201, [], '{"id":77,"source_url":"https://wordpress.example.com/image.png","title":{"rendered":"Image"}}');
            },
        );

        try {
            $result = $this->service($transport)->uploadMedia(
                'https://wordpress.example.com',
                'editor',
                'password',
                $path,
                'image.png',
                'Accessible image',
            );

            $this->assertTrue($result['success']);
            $this->assertSame(77, $result['data']['media_id']);
            $this->assertFileExists($path, 'A caller-owned local source file must never be removed.');
            $this->assertCount(1, $uploadRequests);
            $this->assertNull($uploadRequests[0]->body);
            $this->assertCount(1, $altRequests);
            $this->assertStringEndsWith('/media/77', $altRequests[0]->target->url);
            $this->assertSame(['alt_text' => 'Accessible image'], json_decode($altRequests[0]->body, true));
        } finally {
            @unlink($path);
        }
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

    private function transport(callable $transport): WordPressHttpTransport
    {
        $guard = $this->guard();

        return new WordPressHttpTransport(
            new SafeOutboundHttpClient($guard, $transport(...)),
            $guard,
        );
    }

    private function guard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard(static fn (string $host): array => ['93.184.216.34']);
    }

    private function imagePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hexa-wordpress-transport-');
        $this->assertNotFalse($path);
        file_put_contents($path, base64_decode(self::PNG_1X1, true));

        return $path;
    }
}

final class ExposedWordPressController extends WordPressController
{
    public function fetchMetadata(string $url): array
    {
        return $this->fetchArticleMetadataForUrl($url);
    }
}
