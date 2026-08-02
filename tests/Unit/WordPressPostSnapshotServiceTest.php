<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressPostSnapshotExtensionRegistry;
use hexa_package_wordpress\Services\WordPressPostSnapshotService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WordPressPostSnapshotServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_decodes_html_entities_in_post_titles(): void
    {
        $wordpress = $this->createMock(WordPressManagerService::class);
        $wordpress->expects($this->once())
            ->method('getPostSnapshot')
            ->with(['url' => 'https://example.test'], 42, 'post')
            ->willReturn([
                'success' => true,
                'message' => 'Loaded.',
                'post' => [
                    'id' => 42,
                    'title' => 'Nader Nadernejad &#8211; Grit Daily',
                    'content_html' => '<p>Sample content.</p>',
                    'meta' => [],
                ],
            ]);

        $service = new WordPressPostSnapshotService(
            $wordpress,
            new WordPressPostSnapshotExtensionRegistry()
        );

        $snapshot = $service->fetch(['url' => 'https://example.test'], 42);

        $this->assertTrue($snapshot['success']);
        $this->assertSame("Nader Nadernejad \u{2013} Grit Daily", $snapshot['post']['title']);
        $this->assertSame(2, $snapshot['post']['word_count']);
        $this->assertTrue($snapshot['refresh_succeeded']);
        $this->assertSame(
            "Nader Nadernejad \u{2013} Grit Daily",
            $service->cached(['url' => 'https://example.test'], 42)['post']['title']
        );
    }

    public function test_cached_does_not_contact_wordpress_and_failed_refresh_retains_cache(): void
    {
        $wordpress = $this->createMock(WordPressManagerService::class);
        $wordpress->expects($this->exactly(2))
            ->method('getPostSnapshot')
            ->willReturnOnConsecutiveCalls(
                [
                    'success' => true,
                    'post' => [
                        'id' => 77,
                        'title' => 'Cached title',
                        'content_html' => '<p>Cached body.</p>',
                        'meta' => [],
                    ],
                ],
                ['success' => false, 'message' => 'Remote unavailable.']
            );
        $service = new WordPressPostSnapshotService(
            $wordpress,
            new WordPressPostSnapshotExtensionRegistry()
        );
        $target = ['url' => 'https://cache.example.test'];

        $this->assertFalse($service->cached($target, 77)['success']);
        $this->assertTrue($service->refresh($target, 77)['success']);
        $cached = $service->cached($target, 77);
        $this->assertSame('Cached title', $cached['post']['title']);

        $stale = $service->refresh($target, 77);
        $this->assertTrue($stale['success']);
        $this->assertFalse($stale['refresh_succeeded']);
        $this->assertTrue($stale['stale']);
        $this->assertSame('Cached title', $stale['post']['title']);
        $this->assertSame('Remote unavailable.', $stale['refresh_error']);
    }
}
