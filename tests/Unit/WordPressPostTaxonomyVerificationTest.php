<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

class WordPressPostTaxonomyVerificationTest extends TestCase
{
    public function test_create_requires_confirmed_taxonomy_ids(): void
    {
        $manager = $this->managerForCreate([
            ["success" => true, "message" => "Assigned.", "term_ids" => [2891]],
        ]);

        $result = $manager->createPost([], "Verified post", "Body", "draft", [
            "taxonomies" => ["publication" => [2891]],
        ]);

        $this->assertTrue($result["success"]);
        $this->assertTrue($result["data"]["taxonomy_verification"]["success"]);
        $this->assertSame(
            [2891],
            $result["data"]["taxonomy_verification"]["taxonomies"]["publication"]["actual"],
        );
    }

    public function test_create_retries_a_transient_taxonomy_mismatch(): void
    {
        $manager = $this->managerForCreate([
            ["success" => true, "message" => "Cache lag.", "term_ids" => []],
            ["success" => true, "message" => "Assigned.", "term_ids" => [2891]],
        ]);

        $result = $manager->createPost([], "Retried post", "Body", "draft", [
            "taxonomies" => ["publication" => [2891]],
        ]);

        $this->assertTrue($result["success"]);
        $this->assertSame(
            2,
            $result["data"]["taxonomy_verification"]["taxonomies"]["publication"]["attempts"],
        );
    }

    public function test_create_rolls_back_when_taxonomy_cannot_be_verified(): void
    {
        $manager = $this->managerForCreate([
            ["success" => false, "message" => "Taxonomy unavailable.", "term_ids" => []],
            ["success" => true, "message" => "No relationship saved.", "term_ids" => []],
        ], expectRollback: true);

        $result = $manager->createPost([], "Rejected post", "Body", "draft", [
            "taxonomies" => ["publication" => [2891]],
        ]);

        $this->assertFalse($result["success"]);
        $this->assertSame(991, $result["data"]["post_id"]);
        $this->assertTrue($result["data"]["rollback"]["success"]);
        $this->assertStringContainsString("expected [2891]", $result["message"]);
    }

    public function test_update_propagates_taxonomy_failure(): void
    {
        $server = new WhmServer();
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->method("wpCliUpdatePost")->willReturn([
            "success" => true,
            "message" => "Post updated.",
            "data" => ["post_id" => 991],
        ]);
        $manager = $this->manager(
            $toolkit,
            $server,
            [
                ["success" => false, "message" => "Assignment failed.", "term_ids" => []],
                ["success" => false, "message" => "Assignment failed again.", "term_ids" => []],
            ],
        );

        $result = $manager->updatePost([], 991, [
            "taxonomies" => ["publication" => [2891]],
        ]);

        $this->assertFalse($result["success"]);
        $this->assertStringContainsString("fields were updated", $result["message"]);
        $this->assertFalse($result["data"]["taxonomy_verification"]["success"]);
    }

    /** @param array<int, array<string, mixed>> $assignments */
    private function managerForCreate(array $assignments, bool $expectRollback = false): WordPressManagerService
    {
        $server = new WhmServer();
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->method("wpCliCreatePost")->willReturn([
            "success" => true,
            "message" => "Post created.",
            "data" => ["post_id" => 991, "post_url" => "https://example.com/?p=991"],
        ]);

        return $this->manager($toolkit, $server, $assignments, $expectRollback);
    }

    /** @param array<int, array<string, mixed>> $assignments */
    private function manager(
        WpToolkitService $toolkit,
        WhmServer $server,
        array $assignments,
        bool $expectRollback = false,
    ): WordPressManagerService {
        $rest = $this->createMock(WordPressService::class);
        $manager = $this->getMockBuilder(WordPressManagerService::class)
            ->setConstructorArgs([$toolkit, $rest])
            ->onlyMethods(["normalizeTarget", "usesWpToolkit", "setPostTerms", "deletePost"])
            ->getMock();
        $manager->method("normalizeTarget")->willReturn([
            "mode" => "wptoolkit",
            "server" => $server,
            "install_id" => 17,
            "default_author" => "",
        ]);
        $manager->method("usesWpToolkit")->willReturn(true);
        $manager->expects($this->exactly(count($assignments)))
            ->method("setPostTerms")
            ->willReturnOnConsecutiveCalls(...$assignments);
        $manager->expects($expectRollback ? $this->once() : $this->never())
            ->method("deletePost")
            ->willReturn(["success" => true, "message" => "Post deleted."]);

        return $manager;
    }
}
