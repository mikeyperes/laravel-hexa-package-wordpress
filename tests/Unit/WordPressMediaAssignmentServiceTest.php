<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageWordpress;

use hexa_package_media\Data\MediaInput;
use hexa_package_media\Inspection\ImageInspector;
use hexa_package_media\MediaAcquisitionPipeline;
use hexa_package_media\Transfer\RemoteMediaDownloader;
use hexa_package_media\Transfer\TemporaryMediaResourceManager;
use hexa_package_wordpress\Http\Controllers\MediaOperationController;
use hexa_package_wordpress\Media\Contracts\WordPressMediaDestination;
use hexa_package_wordpress\Media\Destinations\PostFeaturedImageDestination;
use hexa_package_wordpress\Media\Destinations\UserAvatarDestination;
use hexa_package_wordpress\Media\WordPressMediaAssignmentService;
use hexa_package_wordpress\Media\WordPressMediaGateway;
use hexa_package_wordpress\Media\WordPressMediaOperationStore;
use hexa_package_wordpress\Services\WordPressManagerService;
use Tests\TestCase;

class WordPressMediaAssignmentServiceTest extends TestCase
{
    private const PNG_1X1 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=";

    public function test_full_pipeline_assigns_and_verifies_simple_local_avatar_state(): void
    {
        $manager = new FakeWordPressMediaManager();
        $store = new WordPressMediaOperationStore();
        $service = $this->assignmentService($manager, $store);
        $path = $this->imagePath();
        $operationId = "avatar:test:assignment:12345678";

        try {
            $result = $service->assign(
                [],
                MediaInput::local($path, "Staff Portrait.png"),
                new UserAvatarDestination(46),
                $this->assignmentOptions($operationId),
            );

            $this->assertTrue($result["success"]);
            $this->assertSame(77, $result["media_id"]);
            $this->assertSame(77, $manager->avatarId);
            $this->assertSame("simple_local_avatars", $result["destination"]["provider"]);
            $this->assertSame("complete", $store->snapshot($operationId)["state"] ?? null);
            $this->assertContains("verify", array_column($result["events"], "stage"));
            $this->assertSame([], $manager->deletedMediaIds);
        } finally {
            @unlink($path);
        }
    }

    public function test_same_attachment_skips_destructive_provider_rewrite_and_reverifies_file(): void
    {
        $manager = new FakeWordPressMediaManager();
        $manager->avatarId = 77;
        $store = new WordPressMediaOperationStore();
        $service = $this->assignmentService($manager, $store);
        $path = $this->imagePath();

        try {
            $result = $service->assign(
                [],
                MediaInput::local($path, "Existing Portrait.png"),
                new UserAvatarDestination(46),
                $this->assignmentOptions("avatar:test:idempotent:12345678"),
            );

            $this->assertTrue($result["success"]);
            $this->assertSame(77, $result["media_id"]);
            $this->assertSame(0, $manager->avatarAssignmentCalls);
            $this->assertGreaterThanOrEqual(2, $manager->inspectionCalls);
            $assignEvent = collect($result["events"])->firstWhere("stage", "assign");
            $this->assertTrue((bool) ($assignEvent["context"]["already_assigned"] ?? false));
        } finally {
            @unlink($path);
        }
    }

    public function test_file_removed_during_provider_assignment_fails_rolls_back_and_cleans_attachment(): void
    {
        $manager = new FakeWordPressMediaManager();
        $manager->removeFileOnAvatarAssignment = true;
        $store = new WordPressMediaOperationStore();
        $service = $this->assignmentService($manager, $store);
        $path = $this->imagePath();

        try {
            $result = $service->assign(
                [],
                MediaInput::local($path, "Removed Portrait.png"),
                new UserAvatarDestination(46),
                $this->assignmentOptions("avatar:test:file-removed:12345678"),
            );

            $this->assertFalse($result["success"]);
            $this->assertStringContainsString("media file is missing", $result["message"]);
            $this->assertSame([77], $manager->deletedMediaIds);
            $this->assertGreaterThanOrEqual(2, $manager->avatarAssignmentCalls);
            $this->assertContains("rollback", array_column($result["events"], "stage"));
            $this->assertContains("cleanup", array_column($result["events"], "stage"));
        } finally {
            @unlink($path);
        }
    }

    public function test_failed_verification_rolls_back_destination_and_deletes_new_attachment(): void
    {
        $manager = new FakeWordPressMediaManager();
        $store = new WordPressMediaOperationStore();
        $service = $this->assignmentService($manager, $store);
        $destination = new RejectingMediaDestination();
        $path = $this->imagePath();
        $operationId = "media:test:rollback:12345678";

        try {
            $result = $service->assign(
                [],
                MediaInput::local($path, "Rollback Portrait.png"),
                $destination,
                $this->assignmentOptions($operationId),
            );

            $this->assertFalse($result["success"]);
            $this->assertTrue($destination->rollbackCalled);
            $this->assertSame([77], $manager->deletedMediaIds);
            $this->assertTrue($result["rollback"]["success"] ?? false);
            $this->assertTrue($result["cleanup"]["success"] ?? false);
            $this->assertSame("error", $store->snapshot($operationId)["state"] ?? null);
            $this->assertContains("rollback", array_column($result["events"], "stage"));
            $this->assertContains("cleanup", array_column($result["events"], "stage"));
        } finally {
            @unlink($path);
        }
    }

    public function test_featured_image_destination_captures_assigns_verifies_and_rolls_back(): void
    {
        $manager = new FakeWordPressMediaManager();
        $gateway = new WordPressMediaGateway($manager);
        $destination = new PostFeaturedImageDestination(501);

        $captured = $destination->capture($gateway, []);
        $this->assertSame(12, $captured["media_id"]);
        $this->assertTrue($destination->assign($gateway, [], 77)["success"]);
        $this->assertSame(77, $manager->featuredImageId);
        $this->assertTrue($destination->verify($gateway, [], 77)["success"]);
        $this->assertTrue($destination->rollback($gateway, [], $captured)["success"]);
        $this->assertSame(12, $manager->featuredImageId);
    }

    public function test_featured_image_gateway_accepts_an_already_verified_assignment(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Media/WordPressMediaGateway.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            '$alreadyAssigned=$mediaId>0?$current===$mediaId:$current===0;',
            $source,
        );
        $this->assertStringContainsString(
            '$ok=$alreadyAssigned?true:',
            $source,
        );
        $this->assertStringContainsString(
            '"already_assigned"=>$alreadyAssigned',
            $source,
        );
    }

    public function test_gateway_remote_checks_require_a_real_nonempty_file(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . "/src/Media/WordPressMediaGateway.php");

        $this->assertIsString($source);
        $this->assertStringContainsString(
            '$ok=$attachmentOk&&$fileExists&&$bytes>0&&$urlOk;',
            $source,
        );
        $this->assertStringContainsString(
            '$valid=$id>0&&wp_attachment_is_image($id)&&$bytes>0&&filter_var($candidateUrl,FILTER_VALIDATE_URL);',
            $source,
        );
        $this->assertStringContainsString('"invalid_media_ids"=>$invalid', $source);
    }

    public function test_polling_returns_pending_before_the_operation_starts(): void
    {
        $response = (new MediaOperationController())->show(
            'media:test:pending:12345678',
            new WordPressMediaOperationStore(),
        );
        $payload = $response->getData(true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('pending', $payload['state']);
        $this->assertSame([], $payload['events']);
    }

    public function test_operation_store_rejects_unsafe_ids_and_retains_ordered_events(): void
    {
        $store = new WordPressMediaOperationStore();

        $this->assertSame("", $store->normalizeId("../../unsafe"));
        $operationId = "media:test:polling:12345678";
        $store->start($operationId, ["profile_id" => 1, "secret" => "not-retained"]);
        $store->record($operationId, [
            "sequence" => 1,
            "stage" => "upload",
            "state" => "working",
            "message" => "Uploading.",
            "context" => ["media_id" => 77, "secret" => "not-retained"],
        ]);
        $store->finish($operationId, ["success" => true, "message" => "Done.", "media_id" => 77]);

        $snapshot = $store->snapshot($operationId);
        $this->assertSame("complete", $snapshot["state"] ?? null);
        $this->assertSame("upload", $snapshot["events"][0]["stage"] ?? null);
        $this->assertSame(["media_id" => 77], $snapshot["events"][0]["context"] ?? null);
        $this->assertArrayNotHasKey("secret", $snapshot["context"] ?? []);
    }

    private function assignmentService(FakeWordPressMediaManager $manager, WordPressMediaOperationStore $store): WordPressMediaAssignmentService
    {
        return new WordPressMediaAssignmentService(
            new MediaAcquisitionPipeline(
                new RemoteMediaDownloader(),
                new ImageInspector(),
                new TemporaryMediaResourceManager(),
            ),
            new WordPressMediaGateway($manager),
            $store,
        );
    }

    private function assignmentOptions(string $operationId): array
    {
        return [
            "operation_id" => $operationId,
            "min_width" => 1,
            "min_height" => 1,
            "recommended_width" => 1,
            "recommended_height" => 1,
            "deduplicate" => false,
        ];
    }

    private function imagePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), "hexa-wordpress-media-test-");
        $this->assertNotFalse($path);
        file_put_contents($path, base64_decode(self::PNG_1X1, true));

        return $path;
    }
}

final class RejectingMediaDestination implements WordPressMediaDestination
{
    public bool $rollbackCalled = false;

    public function key(): string
    {
        return "test_rejecting_destination";
    }

    public function label(): string
    {
        return "test media";
    }

    public function capture(WordPressMediaGateway $gateway, array $target): array
    {
        return ["success" => true, "media_id" => 12];
    }

    public function assign(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        return ["success" => true, "media_id" => $mediaId];
    }

    public function verify(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        return ["success" => false, "message" => "Deliberate verification failure."];
    }

    public function rollback(WordPressMediaGateway $gateway, array $target, array $previous): array
    {
        $this->rollbackCalled = true;

        return ["success" => true, "media_id" => (int) ($previous["media_id"] ?? 0)];
    }
}

final class FakeWordPressMediaManager extends WordPressManagerService
{
    public int $avatarId = 12;

    public int $avatarAssignmentCalls = 0;

    public int $inspectionCalls = 0;

    public bool $mediaFileAvailable = true;

    public bool $removeFileOnAvatarAssignment = false;

    public int $featuredImageId = 12;

    public array $deletedMediaIds = [];

    public function __construct()
    {
    }

    public function warmConnection(array $target): array
    {
        return ["success" => true, "label" => "Fake WordPress"];
    }

    public function uploadMedia(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        return ["success" => true, "media_id" => 77, "url" => "https://example.test/uploads/portrait.png"];
    }

    public function updateMedia(array $target, int $mediaId, array $attributes): array
    {
        return ["success" => true, "media_id" => $mediaId];
    }

    public function deleteMedia(array $target, int $mediaId, bool $force = true): array
    {
        $this->deletedMediaIds[] = $mediaId;

        return ["success" => true, "media_id" => $mediaId];
    }

    public function getUserProfile(array $target, int $userId, bool $forceRefresh = false): array
    {
        return [
            "success" => true,
            "data" => [
                "avatar_media_id" => $this->avatarId,
                "avatar_full_url" => "https://example.test/uploads/avatar-{$this->avatarId}.png",
                "avatar_url" => "https://example.test/uploads/avatar-{$this->avatarId}.png",
                "avatar_provider" => "simple_local_avatars",
                "author_url" => "https://example.test/author/staff/",
            ],
        ];
    }

    public function setUserAvatar(array $target, int $userId, ?int $mediaId, bool $deletePreviousMedia = false): array
    {
        $this->avatarAssignmentCalls++;
        $this->avatarId = (int) ($mediaId ?? 0);
        if ($this->removeFileOnAvatarAssignment) {
            $this->mediaFileAvailable = false;
        }

        return ["success" => true, "provider" => "simple_local_avatars", "stored_media_id" => $this->avatarId];
    }

    public function evaluatePhp(array $target, string $php): array
    {
        if (str_contains($php, "HEXA_MEDIA_INSPECT:")) {
            $this->inspectionCalls++;

            return [
                "success" => true,
                "stdout" => "HEXA_MEDIA_INSPECT:" . json_encode([
                    "success" => $this->mediaFileAvailable,
                    "message" => $this->mediaFileAvailable
                        ? "WordPress image attachment and file verified."
                        : "WordPress attachment record exists, but its media file is missing.",
                    "media_id" => 77,
                    "url" => "https://example.test/uploads/portrait.png",
                    "mime_type" => "image/png",
                    "file_exists" => $this->mediaFileAvailable,
                    "bytes" => $this->mediaFileAvailable ? 68 : 0,
                    "width" => 1,
                    "height" => 1,
                    "sha256" => "",
                ]),
            ];
        }

        if (str_contains($php, "HEXA_FEATURED_SET:")) {
            preg_match('/\\$mediaId=(\\d+);/', $php, $match);
            $this->featuredImageId = (int) ($match[1] ?? 0);

            return [
                "success" => true,
                "stdout" => "HEXA_FEATURED_SET:" . json_encode([
                    "success" => true,
                    "post_id" => 501,
                    "media_id" => $this->featuredImageId,
                    "url" => "https://example.test/uploads/featured-{$this->featuredImageId}.png",
                    "permalink" => "https://example.test/profile/",
                ]),
            ];
        }

        if (str_contains($php, "HEXA_FEATURED_STATE:")) {
            return [
                "success" => true,
                "stdout" => "HEXA_FEATURED_STATE:" . json_encode([
                    "success" => true,
                    "post_id" => 501,
                    "media_id" => $this->featuredImageId,
                    "url" => "https://example.test/uploads/featured-{$this->featuredImageId}.png",
                    "permalink" => "https://example.test/profile/",
                ]),
            ];
        }

        return ["success" => false, "message" => "Unexpected fake PHP evaluation."];
    }
}
