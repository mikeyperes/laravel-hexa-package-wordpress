<?php

namespace hexa_package_wordpress\Media;

use Illuminate\Support\Facades\Cache;

final class WordPressMediaOperationStore
{
    private const TTL_SECONDS = 1800;

    public function start(string $operationId, array $context = []): array
    {
        $operationId = $this->normalizeId($operationId);
        if ($operationId === "") {
            return [];
        }

        $snapshot = [
            "operation_id" => $operationId,
            "state" => "running",
            "context" => $this->safeContext($context),
            "events" => [],
            "result" => null,
            "started_at" => now()->toIso8601String(),
            "updated_at" => now()->toIso8601String(),
        ];
        Cache::put($this->key($operationId), $snapshot, self::TTL_SECONDS);

        return $snapshot;
    }

    public function record(string $operationId, array $event): void
    {
        $operationId = $this->normalizeId($operationId);
        if ($operationId === "") {
            return;
        }

        $snapshot = $this->snapshot($operationId) ?: $this->start($operationId);
        $events = array_values((array) ($snapshot["events"] ?? []));
        $events[] = $this->safeEvent($event);
        $snapshot["events"] = array_slice($events, -120);
        $snapshot["state"] = "running";
        $snapshot["updated_at"] = now()->toIso8601String();
        Cache::put($this->key($operationId), $snapshot, self::TTL_SECONDS);
    }

    public function finish(string $operationId, array $result): void
    {
        $operationId = $this->normalizeId($operationId);
        if ($operationId === "") {
            return;
        }

        $snapshot = $this->snapshot($operationId) ?: $this->start($operationId);
        $snapshot["state"] = ($result["success"] ?? false) ? "complete" : "error";
        $snapshot["result"] = [
            "success" => (bool) ($result["success"] ?? false),
            "message" => (string) ($result["message"] ?? ""),
            "error_code" => (string) ($result["error_code"] ?? ""),
            "retryable" => (bool) ($result["retryable"] ?? false),
            "media_id" => (int) ($result["media_id"] ?? $result["wp_media_id"] ?? 0),
            "url" => (string) ($result["url"] ?? ""),
            "destination" => (array) ($result["destination"] ?? []),
        ];
        $snapshot["updated_at"] = now()->toIso8601String();
        $snapshot["finished_at"] = now()->toIso8601String();
        Cache::put($this->key($operationId), $snapshot, self::TTL_SECONDS);
    }

    public function snapshot(string $operationId): ?array
    {
        $operationId = $this->normalizeId($operationId);
        if ($operationId === "") {
            return null;
        }

        $snapshot = Cache::get($this->key($operationId));

        return is_array($snapshot) ? $snapshot : null;
    }

    public function normalizeId(string $operationId): string
    {
        $operationId = trim($operationId);

        return preg_match("/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,119}$/", $operationId) ? $operationId : "";
    }

    private function key(string $operationId): string
    {
        return "wordpress-media-operation:" . hash("sha256", $operationId);
    }

    private function safeEvent(array $event): array
    {
        return [
            "sequence" => (int) ($event["sequence"] ?? 0),
            "stage" => substr((string) ($event["stage"] ?? "operation"), 0, 80),
            "state" => in_array(($event["state"] ?? ""), ["pending", "working", "ok", "warn", "error"], true) ? $event["state"] : "working",
            "message" => substr((string) ($event["message"] ?? ""), 0, 1200),
            "context" => $this->safeContext((array) ($event["context"] ?? [])),
            "at" => (string) ($event["at"] ?? now()->toIso8601String()),
        ];
    }

    private function safeContext(array $context): array
    {
        $allowed = [
            "destination", "destination_label", "media_id", "url", "filename", "mime_type",
            "bytes", "width", "height", "sha256", "reused", "rollback", "deleted_attachment",
            "provider", "user_id", "post_id", "site_id", "source_id", "profile_id", "error_code",
            "retryable", "warnings", "strategy", "strategy_number", "strategy_total", "candidate_count",
            "scanned_count", "hashed_count", "matched", "match_method", "current_media_id", "skipped",
            "actions", "permalink", "litespeed_detected", "fallback",
        ];

        return array_intersect_key($context, array_flip($allowed));
    }
}
