<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

final class WordPressPostAuthorFilterTest extends TestCase
{
    public function test_toolkit_post_listing_filters_by_author_and_returns_edit_metadata(): void
    {
        $toolkit = $this->createMock(WpToolkitService::class);
        $rest = $this->createMock(WordPressService::class);
        $manager = $this->getMockBuilder(WordPressManagerService::class)
            ->setConstructorArgs([$toolkit, $rest])
            ->onlyMethods(['normalizeTarget', 'usesWpToolkit', 'evaluatePhp'])
            ->getMock();
        $manager->method('normalizeTarget')->willReturn(['mode' => 'wptoolkit']);
        $manager->method('usesWpToolkit')->willReturn(true);
        $manager->expects($this->once())
            ->method('evaluatePhp')
            ->with(
                ['mode' => 'wptoolkit'],
                $this->callback(fn (string $php): bool => str_contains($php, '$args["author"]=264;')
                    && str_contains($php, 'get_edit_post_link')
                    && str_contains($php, 'get_post_field("post_author"'))
            )
            ->willReturn([
                'success' => true,
                'stdout' => 'HEXA_POST_LIST:'.json_encode([[
                    'id' => 991,
                    'title' => ['rendered' => 'Nader post'],
                    'status' => 'draft',
                    'date' => '2026-08-05 12:00:00',
                    'link' => 'https://hexaprwire.com/?p=991',
                    'edit_url' => 'https://hexaprwire.com/wp-admin/post.php?post=991&action=edit',
                    'author' => 264,
                ]]),
            ]);

        $result = $manager->listPosts([], [
            'author' => 264,
            'per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(991, $result['data'][0]['id']);
        $this->assertSame(264, $result['data'][0]['author']);
        $this->assertStringContainsString('post=991', $result['data'][0]['edit_url']);
    }

    public function test_toolkit_post_listing_caps_page_size(): void
    {
        $toolkit = $this->createMock(WpToolkitService::class);
        $rest = $this->createMock(WordPressService::class);
        $manager = $this->getMockBuilder(WordPressManagerService::class)
            ->setConstructorArgs([$toolkit, $rest])
            ->onlyMethods(['normalizeTarget', 'usesWpToolkit', 'evaluatePhp'])
            ->getMock();
        $manager->method('normalizeTarget')->willReturn(['mode' => 'wptoolkit']);
        $manager->method('usesWpToolkit')->willReturn(true);
        $manager->expects($this->once())
            ->method('evaluatePhp')
            ->with(
                ['mode' => 'wptoolkit'],
                $this->callback(fn (string $php): bool => str_contains($php, '"posts_per_page"=>100,'))
            )
            ->willReturn(['success' => true, 'stdout' => 'HEXA_POST_LIST:[]']);

        $result = $manager->listPosts([], ['per_page' => 1000]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']);
    }
}
