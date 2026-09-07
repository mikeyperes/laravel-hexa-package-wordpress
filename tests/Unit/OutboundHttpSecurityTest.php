<?php

namespace Tests\Unit;

use GuzzleHttp\Psr7\Uri;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\UnsafeOutboundUrl;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Http\Controllers\WordPressController;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

final class OutboundHttpSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
            static fn (string $host): array => match ($host) {
                'public.example.org', 'wordpress.example.org', 'images.example.org', 'en.wikipedia.org' => ['93.184.216.34'],
                default => [],
            },
        ));
        Http::preventStrayRequests();
    }

    public function test_article_metadata_blocks_a_private_target_before_any_request(): void
    {
        Http::fake();

        $response = (new WordPressController)->articleMetadata(Request::create('/', 'POST', [
            'url' => 'http://127.0.0.1/private',
        ]));
        $payload = $response->getData(true);

        $this->assertFalse($payload['items'][0]['success']);
        Http::assertNothingSent();
    }

    public function test_article_metadata_verifies_tls_and_validates_redirect_destinations(): void
    {
        $options = null;
        Http::fake(function (ClientRequest $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Factory::response('<html><meta property="og:title" content="Guarded article"></html>');
        });

        $response = (new WordPressController)->articleMetadata(Request::create('/', 'POST', [
            'url' => 'https://public.example.org/article',
        ]));

        $this->assertTrue($response->getData(true)['items'][0]['success']);
        $this->assertSecureOptions($options);
        $this->assertRedirectToPrivateTargetIsRejected($options);
    }

    public function test_acf_education_lookup_uses_verified_guarded_wikipedia_requests(): void
    {
        $options = null;
        Http::fake(function (ClientRequest $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Factory::response([
                'query' => [
                    'pages' => [[
                        'pageid' => 123,
                        'title' => 'Example University',
                    ]],
                ],
            ]);
        });

        $result = app(AcfEducationMetadataService::class)->lookupMany(['Example University']);

        $this->assertTrue($result['items'][0]['success']);
        $this->assertSecureOptions($options);
    }

    public function test_remote_media_download_and_upload_are_both_verified_and_guarded(): void
    {
        $options = [];
        Http::fake(function (ClientRequest $request, array $requestOptions) use (&$options) {
            $options[$request->url()] = $requestOptions;

            if ($request->url() === 'https://images.example.org/photo.png') {
                return Factory::response('image-bytes', 200, ['Content-Type' => 'image/png']);
            }

            return Factory::response([
                'id' => 77,
                'source_url' => 'https://wordpress.example.org/uploads/photo.png',
                'title' => ['rendered' => 'Photo'],
            ], 201);
        });

        $result = app(WordPressService::class)->uploadMedia(
            'https://wordpress.example.org',
            'editor',
            'app-password',
            'https://images.example.org/photo.png',
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $options);
        foreach ($options as $requestOptions) {
            $this->assertSecureOptions($requestOptions);
        }
    }

    public function test_media_manager_rejects_a_private_remote_source_before_dispatch(): void
    {
        Http::fake();

        $result = app(WordPressManagerService::class)->uploadMedia(
            [],
            'http://127.0.0.1/private.png',
        );

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_favicon_fallback_uses_verified_guarded_requests(): void
    {
        $options = null;
        Http::fake(function (ClientRequest $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Factory::response('<html><link rel="icon" href="/icon.png"></html>');
        });

        $method = new ReflectionMethod(WordPressManagerService::class, 'discoverSiteIconFallback');
        $result = $method->invoke(app(WordPressManagerService::class), 'https://wordpress.example.org');

        $this->assertSame('https://wordpress.example.org/icon.png', $result['url']);
        $this->assertSecureOptions($options);
    }

    private function assertSecureOptions(?array $options): void
    {
        $this->assertIsArray($options);
        $this->assertTrue($options['verify'] ?? null);
        $this->assertIsArray($options['allow_redirects'] ?? null);
        $this->assertTrue($options['allow_redirects']['strict'] ?? false);
        $this->assertFalse($options['allow_redirects']['referer'] ?? true);
        $this->assertIsCallable($options['allow_redirects']['on_redirect'] ?? null);
    }

    private function assertRedirectToPrivateTargetIsRejected(array $options): void
    {
        try {
            $options['allow_redirects']['on_redirect'](
                null,
                null,
                new Uri('http://127.0.0.1/private'),
            );
            $this->fail('A redirect to a private target was not rejected.');
        } catch (UnsafeOutboundUrl) {
            $this->addToAssertionCount(1);
        }
    }
}
