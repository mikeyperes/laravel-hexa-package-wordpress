<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressPostSnapshotExtensionRegistry;
use hexa_package_wordpress\Services\WordPressPostSnapshotService;
use PHPUnit\Framework\TestCase;

class WordPressPostSnapshotServiceTest extends TestCase
{
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
    }
}
