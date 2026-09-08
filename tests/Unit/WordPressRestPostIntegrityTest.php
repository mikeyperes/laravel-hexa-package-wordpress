<?php

namespace Tests\Unit;

use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_media\Inspection\ImageInspector;
use hexa_package_media\Transfer\TemporaryMediaResourceManager;
use hexa_package_wordpress\Services\WordPressHttpTransport;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressMediaSourceService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class WordPressRestPostIntegrityTest extends TestCase
{
    /** @var list<array{0: array<string, mixed>, 1: int}> */
    private array $responses = [];

    /** @var list<OutboundHttpRequest> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
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

    public function test_rest_create_stages_then_verifies_every_field_after_finalization(): void
    {
        $stage = $this->post(501, 'draft');
        $final = $this->post(501, 'publish');
        $this->queueResponses([
            [['id' => 501], 201],
            [$stage, 200],
            [['id' => 501], 200],
            [$final, 200],
        ]);

        $result = $this->manager()->createPost(
            $this->target(),
            'Integrity title',
            '<p>Integrity body \\ with Unicode cafe.</p>',
            'publish',
            $this->options(),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(501, $result['data']['post_id']);
        $this->assertSame('publish', $result['data']['post_status']);
        $this->assertTrue($result['data']['verification']['verified']);
        $this->assertContains('post_content', $result['data']['verification']['checked_fields']);
        $this->assertContains('taxonomy:publication', $result['data']['verification']['checked_fields']);
        $this->assertSame(hash('sha256', '<p>Integrity body \\ with Unicode cafe.</p>'), $result['data']['post_content_sha256']);
        $this->assertCount(4, $this->requests);
        $this->assertTrue($this->requestWasSent('POST', static fn (array $payload): bool => ($payload['status'] ?? null) === 'draft'));
        $this->assertTrue($this->requestWasSent('POST', static fn (array $payload): bool => ($payload['status'] ?? null) === 'publish'));
    }

    public function test_rest_create_accepts_wordpress_default_category_for_an_explicit_empty_selection(): void
    {
        $stage = $this->post(504, 'draft');
        $stage['categories'] = [1];
        $final = $this->post(504, 'publish');
        $final['categories'] = [1];
        $this->queueResponses([
            [['id' => 504], 201],
            [$stage, 200],
            [['id' => 504], 200],
            [$final, 200],
        ]);

        $options = $this->options();
        $options['categories'] = [];
        $result = $this->manager()->createPost(
            $this->target(),
            'Integrity title',
            '<p>Integrity body \\ with Unicode cafe.</p>',
            'publish',
            $options,
        );

        $this->assertTrue($result['success']);
        $this->assertSame([1], $result['data']['categories']);
        $this->assertTrue($result['data']['verification']['verified']);
        $this->assertCount(4, $this->requests);
        $this->assertTrue($this->requestWasSent('POST', static fn (array $payload): bool => ($payload['status'] ?? null) === 'draft'
            && ($payload['categories'] ?? null) === []));
    }

    public function test_rest_create_mismatch_fails_closed_and_confirms_draft(): void
    {
        $mutated = $this->post(502, 'draft');
        $mutated['content']['raw'] = '<p>WordPress changed this body.</p>';
        $this->queueResponses([
            [['id' => 502], 201],
            [$mutated, 200],
            [['id' => 502], 200],
            [$mutated, 200],
        ]);

        $result = $this->manager()->createPost(
            $this->target(),
            'Integrity title',
            '<p>Integrity body \\ with Unicode cafe.</p>',
            'publish',
            $this->options(),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['data']['post_id']);
        $this->assertSame('draft', $result['data']['post_status']);
        $this->assertSame('stage_readback', $result['data']['verification']['phase']);
        $this->assertArrayHasKey('post_content', $result['data']['verification']['mismatches']);
        $this->assertTrue($result['data']['verification']['rollback']['success']);
        $this->assertCount(4, $this->requests);
    }

    public function test_rest_update_mismatch_restores_original_published_snapshot(): void
    {
        $before = $this->post(503, 'publish');
        $stage = $this->post(503, 'publish');
        $stage['excerpt']['raw'] = 'A plugin changed the excerpt.';
        $this->queueResponses([
            [$before, 200],
            [['id' => 503], 200],
            [$stage, 200],
            [['id' => 503], 200],
            [$before, 200],
        ]);

        $result = $this->manager()->updatePost($this->target(), 503, [
            'excerpt' => 'Exact excerpt for the integrity test.',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['data']['post_id']);
        $this->assertSame('publish', $result['data']['post_status']);
        $this->assertSame('stage_readback', $result['data']['verification']['phase']);
        $this->assertTrue($result['data']['verification']['rollback']['success']);
        $this->assertSame('Exact excerpt for the integrity test.', $result['data']['verification']['mismatches']['post_excerpt']['expected']);
        $this->assertCount(5, $this->requests);
    }

    private function manager(): WordPressManagerService
    {
        $guard = new OutboundUrlGuard(static fn (string $host): array => ['93.184.216.34']);
        $client = new SafeOutboundHttpClient($guard, function (OutboundHttpRequest $request): OutboundHttpResponse {
            $this->requests[] = $request;
            $next = array_shift($this->responses);
            if (! is_array($next)) {
                $this->fail('The fake WordPress response sequence was exhausted.');
            }

            return new OutboundHttpResponse(
                $next[1],
                ['content-type' => 'application/json'],
                json_encode($next[0], JSON_THROW_ON_ERROR),
            );
        });
        $transport = new WordPressHttpTransport($client, $guard);
        $rest = new WordPressService(
            $transport,
            new WordPressMediaSourceService(
                $transport,
                new ImageInspector,
                new TemporaryMediaResourceManager,
            ),
        );

        return new WordPressManagerService(
            $this->createStub(WpToolkitService::class),
            $rest,
        );
    }

    private function target(): array
    {
        return [
            'mode' => 'rest',
            'url' => 'https://wordpress-integrity.example.com',
            'username' => 'editor',
            'application_password' => 'test-password',
        ];
    }

    private function options(): array
    {
        return [
            'excerpt' => 'Exact excerpt for the integrity test.',
            'date' => '2026-07-24 09:30:00',
            'slug' => 'integrity-title',
            'author' => 9,
            'featured_media' => 41,
            'categories' => [12, 11],
            'tags' => [21],
            'taxonomies' => ['publication' => [31]],
        ];
    }

    private function post(int $id, string $status): array
    {
        return [
            'id' => $id,
            'link' => 'https://wordpress-integrity.example.com/?p='.$id,
            'status' => $status,
            'title' => ['raw' => 'Integrity title', 'rendered' => 'Integrity title'],
            'content' => ['raw' => '<p>Integrity body \\ with Unicode cafe.</p>', 'rendered' => '<p>Integrity body \\ with Unicode cafe.</p>'],
            'excerpt' => ['raw' => 'Exact excerpt for the integrity test.', 'rendered' => '<p>Exact excerpt for the integrity test.</p>'],
            'date' => '2026-07-24T09:30:00',
            'slug' => 'integrity-title',
            'type' => 'post',
            'author' => 9,
            'featured_media' => 41,
            'categories' => [11, 12],
            'tags' => [21],
            'publication' => [31],
        ];
    }

    /** @param list<array{0: array<string, mixed>, 1: int}> $responses */
    private function queueResponses(array $responses): void
    {
        $this->responses = $responses;
    }

    /** @param callable(array<string, mixed>): bool $predicate */
    private function requestWasSent(string $method, callable $predicate): bool
    {
        foreach ($this->requests as $request) {
            $payload = json_decode((string) $request->body, true);
            if ($request->method === $method && is_array($payload) && $predicate($payload)) {
                return true;
            }
        }

        return false;
    }
}
