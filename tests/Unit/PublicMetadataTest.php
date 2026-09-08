<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWordpress;

use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_wordpress\Acf\AcfEducationMetadataService;
use hexa_package_wordpress\Services\ArticleMetadataService;
use hexa_package_wordpress\Services\WordPressManagerService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicMetadataTest extends TestCase
{
    private function client(\Closure $transport, ?\Closure $resolver = null): SafeOutboundHttpClient
    {
        return new SafeOutboundHttpClient(new OutboundUrlGuard($resolver ?? static fn (): array => ['93.184.216.34']), $transport);
    }

    public function test_dns_result_is_the_exact_pinned_transport_target(): void
    {
        $resolutions = 0;
        $client = $this->client(function (OutboundHttpRequest $request): OutboundHttpResponse {
            $this->assertSame(['public.example.org:443:93.184.216.34'], $request->curlOptions()[CURLOPT_RESOLVE]);

            return new OutboundHttpResponse(200, [], '<title>Public article</title>');
        }, static function () use (&$resolutions): array {
            return ++$resolutions === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });

        $result = (new ArticleMetadataService($client))->lookup('https://public.example.org/article');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $resolutions);
    }

    #[DataProvider('blockedUrls')]
    public function test_private_ambiguous_and_credentialed_urls_never_reach_transport(string $url): void
    {
        $calls = 0;
        $client = $this->client(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '<title>Private</title>');
        });
        $this->assertFalse((new ArticleMetadataService($client))->lookup($url)['success']);
        $this->assertSame(0, $calls);
    }

    public static function blockedUrls(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'http://127.0.0.1/private', 'http://169.254.169.254/latest',
            'http://[::1]/', 'file:///etc/passwd', 'http://2130706433/',
            'https://user:secret@public.example.org/',
        ]);
    }

    public function test_private_redirect_is_rejected_before_a_second_request(): void
    {
        $calls = 0;
        $client = $this->client(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(302, ['Location' => 'http://127.0.0.1/internal'], '');
        });
        $result = (new ArticleMetadataService($client))->lookup('https://public.example.org/');
        $this->assertFalse($result['success']);
        $this->assertSame(1, $calls);
        $this->assertStringNotContainsString('127.0.0.1', $result['message']);
    }

    public function test_relative_public_redirect_preserves_title_and_original_url(): void
    {
        $urls = [];
        $client = $this->client(static function (OutboundHttpRequest $request) use (&$urls): OutboundHttpResponse {
            $urls[] = $request->target->url;

            return count($urls) === 1
                ? new OutboundHttpResponse(302, ['Location' => '/article'], '')
                : new OutboundHttpResponse(200, [], '<title>Resolved</title>');
        });
        $result = (new ArticleMetadataService($client))->lookup('https://public.example.org/start');
        $this->assertSame('Resolved', $result['title']);
        $this->assertSame('https://public.example.org/start', $result['url']);
        $this->assertSame(['https://public.example.org/start', 'https://public.example.org/article'], $urls);
    }

    public function test_oversized_html_and_provider_errors_do_not_produce_metadata_or_leak_details(): void
    {
        foreach ([
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, [], str_repeat('x', 1048577)),
            static fn (): OutboundHttpResponse => throw new \RuntimeException('internal password=fixture-secret'),
        ] as $transport) {
            $result = (new ArticleMetadataService($this->client($transport)))->lookup('https://public.example.org/');
            $this->assertFalse($result['success']);
            $this->assertSame('', $result['title']);
            $this->assertStringNotContainsString('fixture-secret', $result['message']);
        }
    }

    #[DataProvider('titleCases')]
    public function test_title_extraction_preserves_supported_formats(string $html, string $expected): void
    {
        $service = new ArticleMetadataService($this->client(static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, [], '')));
        $this->assertSame($expected, $service->extractTitle($html));
    }

    public static function titleCases(): array
    {
        return [
            ['<meta content="A &amp; B" property="og:title"><title>Fallback</title>', 'A & B'],
            ['<meta name=twitter:title content=Headline>', 'Headline'],
            ['<meta data-name="og:title" data-content="Wrong"><title>Right</title>', 'Right'],
            ['<script type="application/ld+json">{"headline":"Caf\u00e9 \\"quoted\\""}</script>', 'Café "quoted"'],
            ['<h1>First <em>headline</em></h1><title>Fallback</title>', 'First headline'],
            ['<title> Last   resort </title>', 'Last resort'],
            ['<p>No title</p>', ''],
        ];
    }

    public function test_batch_is_deduplicated_and_over_limit_inputs_are_rejected_without_dispatch(): void
    {
        $calls = 0;
        $service = new ArticleMetadataService($this->client(static function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(200, [], '<title>One</title>');
        }));
        $result = $service->lookupMany([' https://public.example.org/ ', 'https://public.example.org/', null, '']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $calls);
        foreach ([array_fill(0, 21, 'https://public.example.org/'), [['bad']], [str_repeat('x', 8193)]] as $urls) {
            try {
                $service->lookupMany($urls);
                $this->fail('Invalid metadata input was accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, $calls);
            }
        }
    }

    public function test_wikipedia_live_redirect_and_search_preserve_exact_matching(): void
    {
        $requests = [];
        $service = new AcfEducationMetadataService($this->client(static function (OutboundHttpRequest $request) use (&$requests): OutboundHttpResponse {
            parse_str((string) parse_url($request->target->url, PHP_URL_QUERY), $query);
            $requests[] = $query;
            $data = isset($query['list'])
                ? ['query' => ['search' => [['title' => 'Example University']]]]
                : ['query' => ['pages' => [['title' => 'Example University', 'missing' => '']]]];

            return new OutboundHttpResponse(200, [], json_encode($data, JSON_THROW_ON_ERROR));
        }));
        $result = $service->lookupMany(['Example University']);
        $this->assertTrue($result['items'][0]['success']);
        $this->assertSame('https://en.wikipedia.org/wiki/Example_University', $result['items'][0]['wiki_url']);
        $this->assertCount(2, $requests);

        $service = new AcfEducationMetadataService($this->client(static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, [], json_encode([
            'query' => [
                'redirects' => [['from' => 'Example University', 'to' => 'New University']],
                'pages' => [['pageid' => 123, 'title' => 'New University']],
            ],
        ], JSON_THROW_ON_ERROR))));
        $this->assertSame('https://en.wikipedia.org/wiki/New_University', $service->lookupMany(['Example University'])['items'][0]['wiki_url']);
    }

    public function test_wikipedia_invalid_oversized_and_redirect_responses_fail_without_fabricating_a_url(): void
    {
        foreach ([
            new OutboundHttpResponse(200, [], 'not-json'),
            new OutboundHttpResponse(200, [], str_repeat('x', 262145)),
            new OutboundHttpResponse(302, ['Location' => 'http://127.0.0.1/'], ''),
        ] as $response) {
            $result = (new AcfEducationMetadataService($this->client(static fn (): OutboundHttpResponse => $response)))->lookupMany(['Example University']);
            $this->assertFalse($result['items'][0]['success']);
            $this->assertSame('', $result['items'][0]['wiki_url']);
        }
    }

    public function test_favicon_relative_link_uses_the_guarded_redirect_destination(): void
    {
        $urls = [];
        $guard = new OutboundUrlGuard(static fn (): array => ['93.184.216.34']);
        $this->app->instance(OutboundUrlGuard::class, $guard);
        $this->app->instance(SafeOutboundHttpClient::class, new SafeOutboundHttpClient($guard, static function (OutboundHttpRequest $request) use (&$urls): OutboundHttpResponse {
            $urls[] = $request->target->url;

            return count($urls) === 1
                ? new OutboundHttpResponse(302, ['Location' => 'https://redirect.example.org/blog/'], '')
                : new OutboundHttpResponse(200, ['Content-Type' => 'text/html'], '<link rel="icon" href="assets/icon.png">');
        }));
        $method = new \ReflectionMethod(WordPressManagerService::class, 'discoverSiteIconFallback');
        $result = $method->invoke(app(WordPressManagerService::class), 'https://public.example.org');

        $this->assertSame(['url' => 'https://redirect.example.org/blog/assets/icon.png', 'source' => 'html_icon_link'], $result);
        $this->assertSame(['https://public.example.org/', 'https://redirect.example.org/blog/'], $urls);
    }

    public function test_favicon_ignores_private_link_and_non_image_fallback(): void
    {
        $urls = [];
        $guard = new OutboundUrlGuard(static fn (): array => ['93.184.216.34']);
        $this->app->instance(OutboundUrlGuard::class, $guard);
        $this->app->instance(SafeOutboundHttpClient::class, new SafeOutboundHttpClient($guard, static function (OutboundHttpRequest $request) use (&$urls): OutboundHttpResponse {
            $urls[] = $request->target->url;

            return new OutboundHttpResponse(200, ['Content-Type' => 'text/html'], '<link rel="icon" href="http://127.0.0.1/private">');
        }));
        $method = new \ReflectionMethod(WordPressManagerService::class, 'discoverSiteIconFallback');
        $result = $method->invoke(app(WordPressManagerService::class), 'https://public.example.org');
        $this->assertSame(['url' => '', 'source' => 'none'], $result);
        $this->assertSame(['https://public.example.org/', 'https://public.example.org/favicon.ico'], $urls);
    }
}
