<?php

namespace hexa_package_wordpress\Media;

use hexa_package_media\Data\MediaArtifact;
use hexa_package_media\Data\MediaInput;
use hexa_package_media\Exceptions\MediaPipelineException;
use hexa_package_media\MediaAcquisitionPipeline;
use hexa_package_wordpress\Media\Contracts\WordPressMediaDestination;
use RuntimeException;
use Throwable;

final class WordPressMediaAssignmentService
{
    private array $events = [];

    private int $sequence = 0;

    private string $operationId = "";

    public function __construct(
        private readonly MediaAcquisitionPipeline $media,
        private readonly WordPressMediaGateway $gateway,
        private readonly WordPressMediaOperationStore $operations,
    ) {
    }

    public function assign(
        array $target,
        MediaInput $input,
        WordPressMediaDestination $destination,
        array $options = [],
    ): array {
        $this->begin($destination, $options);
        $artifact = null;
        $createdMediaId = 0;
        $assignmentAttempted = false;
        $previous = [];

        try {
            $this->emit("connect", "working", "Checking the WordPress connection.", [
                "destination" => $destination->key(),
                "destination_label" => $destination->label(),
            ]);
            $connection = $this->gateway->manager()->warmConnection($target);
            if (!($connection["success"] ?? false)) {
                throw new RuntimeException((string) ($connection["message"] ?? "WordPress connection failed."));
            }
            $this->emit("connect", "ok", "WordPress connection ready through " . (string) ($connection["label"] ?? $connection["mode"] ?? "configured connection") . ".");

            $this->emit("destination_snapshot", "working", "Reading the current " . $destination->label() . " state for rollback.");
            $previous = $destination->capture($this->gateway, $target);
            if (!($previous["success"] ?? false)) {
                throw new RuntimeException((string) ($previous["message"] ?? "Current WordPress media destination could not be read."));
            }
            $this->emit("destination_snapshot", "ok", "Current destination state captured.", [
                "media_id" => (int) ($previous["media_id"] ?? 0),
                "provider" => (string) ($previous["provider"] ?? ""),
            ]);

            $artifact = $this->media->acquire(
                $input,
                $this->acquisitionOptions($options),
                fn (array $event) => $this->emit(
                    (string) ($event["stage"] ?? "media"),
                    (string) ($event["state"] ?? "working"),
                    (string) ($event["message"] ?? ""),
                    (array) ($event["context"] ?? []),
                ),
            );

            $mediaId = 0;
            $reused = false;
            if (($options["deduplicate"] ?? true) && $artifact->sha256 !== "") {
                $this->emit("deduplicate", "working", "Checking WordPress for an existing identical image attachment.", ["sha256" => $artifact->sha256]);
                $existing = $this->gateway->findBySha256($target, $artifact->sha256);
                if (($existing["success"] ?? false) && ($existing["found"] ?? false)) {
                    $mediaId = (int) ($existing["media_id"] ?? 0);
                    $reused = $mediaId > 0;
                } elseif (!($existing["success"] ?? true)) {
                    $this->emit("deduplicate", "warn", (string) ($existing["message"] ?? "Duplicate lookup failed; continuing with a verified upload."));
                }
                $this->emit("deduplicate", $reused ? "ok" : "ok", $reused
                    ? "Reusing identical WordPress image attachment #{$mediaId}."
                    : "No identical WordPress attachment found; a new upload is required.", [
                    "media_id" => $mediaId,
                    "reused" => $reused,
                ]);
            }

            if (!$reused) {
                $this->emit("upload", "working", "Uploading the validated image to the WordPress media library.", [
                    "filename" => $artifact->filename,
                    "mime_type" => $artifact->mimeType,
                    "bytes" => $artifact->bytes,
                    "width" => $artifact->width,
                    "height" => $artifact->height,
                ]);
                $uploaded = $this->gateway->upload($target, $artifact, [
                    "alt_text" => (string) ($options["alt_text"] ?? ""),
                    "caption" => (string) ($options["caption"] ?? ""),
                    "description" => (string) ($options["description"] ?? ""),
                ]);
                $createdMediaId = (int) ($uploaded["media_id"] ?? 0);
                if (!($uploaded["success"] ?? false)) {
                    throw new RuntimeException((string) ($uploaded["message"] ?? "WordPress media upload failed."));
                }
                $mediaId = $createdMediaId;
                $this->emit("upload", "ok", "WordPress image attachment #{$mediaId} uploaded and inspected.", [
                    "media_id" => $mediaId,
                    "url" => (string) ($uploaded["url"] ?? ""),
                    "mime_type" => (string) ($uploaded["mime_type"] ?? ""),
                ]);
            } else {
                $inspection = $this->gateway->inspect($target, $mediaId);
                if (!($inspection["success"] ?? false)) {
                    throw new RuntimeException((string) ($inspection["message"] ?? "Existing WordPress media verification failed."));
                }
            }

            $alreadyAssigned = (int) ($previous["media_id"] ?? 0) === $mediaId;
            $this->emit("assign", "working", $alreadyAssigned
                ? "The destination already references attachment #{$mediaId}; checking it without rewriting the provider."
                : "Assigning attachment #{$mediaId} as the " . $destination->label() . ".", [
                "media_id" => $mediaId,
                "destination" => $destination->key(),
                "already_assigned" => $alreadyAssigned,
            ]);
            $assignmentAttempted = !$alreadyAssigned;
            $assigned = $this->assignDestination($target, $destination, $mediaId, $previous);
            if (!($assigned["success"] ?? false)) {
                throw new RuntimeException((string) ($assigned["message"] ?? "WordPress media destination assignment failed."));
            }
            $this->emit("assign", "ok", $alreadyAssigned
                ? "Destination already used attachment #{$mediaId}; provider rewrite skipped."
                : "WordPress accepted the destination assignment.", [
                "media_id" => $mediaId,
                "provider" => (string) ($assigned["provider"] ?? $assigned["media"]["provider"] ?? ""),
                "already_assigned" => $alreadyAssigned,
            ]);

            $this->emit("verify", "working", "Reloading WordPress to verify the saved destination state and media file.", ["media_id" => $mediaId]);
            $finalInspection = $this->gateway->inspect($target, $mediaId);
            if (!($finalInspection["success"] ?? false)) {
                throw new RuntimeException("WordPress attachment verification failed after destination assignment: "
                    . (string) ($finalInspection["message"] ?? "the assigned media file is unavailable."));
            }
            $verified = $destination->verify($this->gateway, $target, $mediaId);
            if (!($verified["success"] ?? false)) {
                throw new RuntimeException((string) ($verified["message"] ?? "WordPress destination verification failed."));
            }
            $this->emit("verify", "ok", (string) ($verified["message"] ?? "WordPress destination verified."), [
                "media_id" => $mediaId,
                "url" => (string) ($verified["url"] ?? ""),
                "provider" => (string) ($verified["provider"] ?? ""),
            ]);

            $result = [
                "success" => true,
                "message" => $destination->label() . " updated and verified.",
                "media_id" => $mediaId,
                "wp_media_id" => $mediaId,
                "url" => (string) ($verified["url"] ?? ""),
                "mime_type" => $artifact->mimeType,
                "reused" => $reused,
                "artifact" => $artifact->toArray(),
                "destination" => $verified,
                "previous" => $previous,
                "operation_id" => $this->operationId,
                "events" => $this->events,
            ];

            return $this->finish($result);
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof MediaPipelineException ? $exception->errorCode : "wordpress_media_assignment_failed";
            $retryable = $exception instanceof MediaPipelineException ? $exception->retryable : false;
            $this->emit("failure", "error", $exception->getMessage(), [
                "error_code" => $errorCode,
                "retryable" => $retryable,
                "media_id" => $createdMediaId,
            ]);

            $rollback = null;
            if ($assignmentAttempted && ($previous["success"] ?? false)) {
                $this->emit("rollback", "working", "Restoring the previous WordPress destination state.");
                $rollback = $destination->rollback($this->gateway, $target, $previous);
                $this->emit("rollback", ($rollback["success"] ?? false) ? "ok" : "error", ($rollback["success"] ?? false)
                    ? "Previous WordPress destination state restored."
                    : "Previous WordPress destination state could not be restored.", [
                    "rollback" => (bool) ($rollback["success"] ?? false),
                ]);
            }

            $deleted = null;
            if ($createdMediaId > 0) {
                $this->emit("cleanup", "working", "Removing orphaned WordPress attachment #{$createdMediaId}.");
                $deleted = $this->gateway->delete($target, $createdMediaId);
                $this->emit("cleanup", ($deleted["success"] ?? false) ? "ok" : "error", ($deleted["success"] ?? false)
                    ? "Orphaned WordPress attachment removed."
                    : "Orphaned WordPress attachment cleanup failed.", [
                    "media_id" => $createdMediaId,
                    "deleted_attachment" => (bool) ($deleted["success"] ?? false),
                ]);
            }

            return $this->finish([
                "success" => false,
                "message" => $exception->getMessage(),
                "error_code" => $errorCode,
                "retryable" => $retryable,
                "media_id" => 0,
                "wp_media_id" => 0,
                "operation_id" => $this->operationId,
                "events" => $this->events,
                "rollback" => $rollback,
                "cleanup" => $deleted,
            ]);
        } finally {
            if ($artifact instanceof MediaArtifact) {
                $artifact->release();
            }
        }
    }

    public function assignExisting(
        array $target,
        int $mediaId,
        WordPressMediaDestination $destination,
        array $options = [],
    ): array {
        $this->begin($destination, $options);
        $previous = [];
        $assignmentAttempted = false;

        try {
            $this->emit("inspect", "working", "Inspecting existing WordPress attachment #{$mediaId}.", ["media_id" => $mediaId]);
            $inspection = $this->gateway->inspect($target, $mediaId);
            if (!($inspection["success"] ?? false)) {
                throw new RuntimeException((string) ($inspection["message"] ?? "Existing WordPress media verification failed."));
            }
            $this->emit("inspect", "ok", "Existing WordPress image attachment verified.", ["media_id" => $mediaId, "url" => (string) ($inspection["url"] ?? "")]);

            $previous = $destination->capture($this->gateway, $target);
            if (!($previous["success"] ?? false)) {
                throw new RuntimeException((string) ($previous["message"] ?? "Current destination state could not be captured."));
            }

            $alreadyAssigned = (int) ($previous["media_id"] ?? 0) === $mediaId;
            $assignmentAttempted = !$alreadyAssigned;
            $assigned = $this->assignDestination($target, $destination, $mediaId, $previous);
            if (!($assigned["success"] ?? false)) {
                throw new RuntimeException((string) ($assigned["message"] ?? "WordPress destination assignment failed."));
            }
            $this->emit("assign", "ok", $alreadyAssigned
                ? "Destination already used attachment #{$mediaId}; provider rewrite skipped."
                : "WordPress accepted the destination assignment.", [
                "media_id" => $mediaId,
                "provider" => (string) ($assigned["provider"] ?? $assigned["media"]["provider"] ?? ""),
                "already_assigned" => $alreadyAssigned,
            ]);

            $finalInspection = $this->gateway->inspect($target, $mediaId);
            if (!($finalInspection["success"] ?? false)) {
                throw new RuntimeException("WordPress attachment verification failed after destination assignment: "
                    . (string) ($finalInspection["message"] ?? "the assigned media file is unavailable."));
            }
            $verified = $destination->verify($this->gateway, $target, $mediaId);
            if (!($verified["success"] ?? false)) {
                throw new RuntimeException((string) ($verified["message"] ?? "WordPress destination verification failed."));
            }
            $this->emit("verify", "ok", (string) ($verified["message"] ?? "WordPress destination verified."), ["media_id" => $mediaId, "url" => (string) ($verified["url"] ?? "")]);

            return $this->finish([
                "success" => true,
                "message" => $destination->label() . " updated and verified.",
                "media_id" => $mediaId,
                "wp_media_id" => $mediaId,
                "url" => (string) ($verified["url"] ?? $inspection["url"] ?? ""),
                "mime_type" => (string) ($inspection["mime_type"] ?? ""),
                "reused" => true,
                "destination" => $verified,
                "previous" => $previous,
                "operation_id" => $this->operationId,
                "events" => $this->events,
            ]);
        } catch (Throwable $exception) {
            $this->emit("failure", "error", $exception->getMessage(), ["error_code" => "wordpress_existing_media_assignment_failed", "media_id" => $mediaId]);
            $rollback = null;
            if ($assignmentAttempted && ($previous["success"] ?? false)) {
                $rollback = $destination->rollback($this->gateway, $target, $previous);
                $this->emit("rollback", ($rollback["success"] ?? false) ? "ok" : "error", ($rollback["success"] ?? false)
                    ? "Previous WordPress destination state restored."
                    : "Previous WordPress destination state could not be restored.");
            }

            return $this->finish([
                "success" => false,
                "message" => $exception->getMessage(),
                "error_code" => "wordpress_existing_media_assignment_failed",
                "retryable" => false,
                "operation_id" => $this->operationId,
                "events" => $this->events,
                "rollback" => $rollback,
            ]);
        }
    }

    private function assignDestination(
        array $target,
        WordPressMediaDestination $destination,
        int $mediaId,
        array $previous,
    ): array {
        if ((int) ($previous["media_id"] ?? 0) === $mediaId) {
            return [
                "success" => true,
                "media_id" => $mediaId,
                "provider" => (string) ($previous["provider"] ?? ""),
                "url" => (string) ($previous["url"] ?? ""),
                "already_assigned" => true,
            ];
        }

        $assigned = $destination->assign($this->gateway, $target, $mediaId);
        $assigned["already_assigned"] = false;

        return $assigned;
    }

    private function begin(WordPressMediaDestination $destination, array $options): void
    {
        $this->events = [];
        $this->sequence = 0;
        $this->operationId = $this->operations->normalizeId((string) ($options["operation_id"] ?? ""));
        if ($this->operationId !== "") {
            $this->operations->start($this->operationId, array_merge((array) ($options["operation_context"] ?? []), [
                "destination" => $destination->key(),
                "destination_label" => $destination->label(),
            ]));
        }
        $this->emit("start", "working", "Starting verified media assignment to " . $destination->label() . ".", [
            "destination" => $destination->key(),
            "destination_label" => $destination->label(),
        ]);
    }

    private function emit(string $stage, string $state, string $message, array $context = []): void
    {
        $event = [
            "sequence" => ++$this->sequence,
            "stage" => $stage,
            "state" => $state,
            "message" => $message,
            "context" => $context,
            "at" => now()->toIso8601String(),
        ];
        $this->events[] = $event;
        if ($this->operationId !== "") {
            $this->operations->record($this->operationId, $event);
        }
    }

    private function finish(array $result): array
    {
        $result["events"] = $this->events;
        $result["operation_id"] = $this->operationId;
        if ($this->operationId !== "") {
            $this->operations->finish($this->operationId, $result);
        }

        return $result;
    }

    private function acquisitionOptions(array $options): array
    {
        return [
            "max_bytes" => (int) ($options["max_bytes"] ?? 20 * 1024 * 1024),
            "min_width" => (int) ($options["min_width"] ?? 256),
            "min_height" => (int) ($options["min_height"] ?? 256),
            "recommended_width" => (int) ($options["recommended_width"] ?? 800),
            "recommended_height" => (int) ($options["recommended_height"] ?? 800),
            "allowed_mime_types" => (array) ($options["allowed_mime_types"] ?? ["image/jpeg", "image/png", "image/gif", "image/webp", "image/avif"]),
            "timeout" => (int) ($options["timeout"] ?? 90),
            "max_redirects" => (int) ($options["max_redirects"] ?? 4),
        ];
    }
}
