<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class WordPressRestPostIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance(Factory::class, new Factory());
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
        Http::fakeSequence()
            ->push(['id' => 501], 201)
            ->push($stage, 200)
            ->push(['id' => 501], 200)
            ->push($final, 200);

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
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['status'] === 'draft');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['status'] === 'publish');
    }

    public function test_rest_create_accepts_wordpress_default_category_for_an_explicit_empty_selection(): void
    {
        $stage = $this->post(504, 'draft');
        $stage['categories'] = [1];
        $final = $this->post(504, 'publish');
        $final['categories'] = [1];
        Http::fakeSequence()
            ->push(['id' => 504], 201)
            ->push($stage, 200)
            ->push(['id' => 504], 200)
            ->push($final, 200);

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
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['status'] === 'draft'
            && $request['categories'] === []);
    }

    public function test_rest_create_mismatch_fails_closed_and_confirms_draft(): void
    {
        $mutated = $this->post(502, 'draft');
        $mutated['content']['raw'] = '<p>WordPress changed this body.</p>';
        Http::fakeSequence()
            ->push(['id' => 502], 201)
            ->push($mutated, 200)
            ->push(['id' => 502], 200)
            ->push($mutated, 200);

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
        Http::assertSentCount(4);
    }

    public function test_rest_update_mismatch_restores_original_published_snapshot(): void
    {
        $before = $this->post(503, 'publish');
        $stage = $this->post(503, 'publish');
        $stage['excerpt']['raw'] = 'A plugin changed the excerpt.';
        Http::fakeSequence()
            ->push($before, 200)
            ->push(['id' => 503], 200)
            ->push($stage, 200)
            ->push(['id' => 503], 200)
            ->push($before, 200);

        $result = $this->manager()->updatePost($this->target(), 503, [
            'excerpt' => 'Exact excerpt for the integrity test.',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['data']['post_id']);
        $this->assertSame('publish', $result['data']['post_status']);
        $this->assertSame('stage_readback', $result['data']['verification']['phase']);
        $this->assertTrue($result['data']['verification']['rollback']['success']);
        $this->assertSame('Exact excerpt for the integrity test.', $result['data']['verification']['mismatches']['post_excerpt']['expected']);
        Http::assertSentCount(5);
    }

    private function manager(): WordPressManagerService
    {
        return new WordPressManagerService(
            $this->createStub(WpToolkitService::class),
            $this->createStub(WordPressService::class),
        );
    }

    private function target(): array
    {
        return [
            'mode' => 'rest',
            'url' => 'https://wordpress-integrity.test',
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
            'link' => 'https://wordpress-integrity.test/?p=' . $id,
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
}
