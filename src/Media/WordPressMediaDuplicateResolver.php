<?php

namespace hexa_package_wordpress\Media;

use hexa_package_media\Data\MediaArtifact;

final class WordPressMediaDuplicateResolver
{
    private const STRATEGIES = [
        "current" => "currently assigned destination image",
        "sha256" => "indexed SHA-256 metadata",
        "source_url" => "canonical source URL",
        "filename" => "original and attached filename",
        "fingerprint" => "media-library byte and dimension fingerprint",
    ];

    public function __construct(private readonly WordPressMediaGateway $gateway)
    {
    }

    public function resolve(
        array $target,
        MediaArtifact $artifact,
        array $options = [],
        ?callable $progress = null,
    ): array {
        $currentMediaId = max(0, (int) ($options["current_media_id"] ?? 0));
        $total = count(self::STRATEGIES);
        $attempts = [];

        $this->emit($progress, "deduplicate_plan", "working", "Preparing {$total} exact-match checks before any WordPress upload.", [
            "strategy_total" => $total,
            "current_media_id" => $currentMediaId,
            "filename" => $artifact->filename,
            "bytes" => $artifact->bytes,
            "width" => $artifact->width,
            "height" => $artifact->height,
            "sha256" => $artifact->sha256,
        ]);

        foreach (array_keys(self::STRATEGIES) as $index => $strategy) {
            $number = $index + 1;
            $stage = "deduplicate_" . $strategy;
            $unavailable = $this->unavailableReason($strategy, $artifact, $currentMediaId);
            if ($unavailable !== "") {
                $attempts[] = ["strategy" => $strategy, "skipped" => true, "reason" => $unavailable];
                $this->emit($progress, $stage, "ok", "Method {$number}/{$total} skipped: {$unavailable}", [
                    "strategy" => $strategy,
                    "strategy_number" => $number,
                    "strategy_total" => $total,
                    "skipped" => true,
                ]);
                continue;
            }

            $this->emit($progress, $stage, "working", $this->startMessage($strategy, $number, $total, $currentMediaId), [
                "strategy" => $strategy,
                "strategy_number" => $number,
                "strategy_total" => $total,
                "current_media_id" => $currentMediaId,
            ]);

            $result = $this->gateway->findExactMatch($target, $artifact, $strategy, [
                "current_media_id" => $currentMediaId,
            ]);
            $attempts[] = array_replace(["strategy" => $strategy], $result);

            if (!($result["success"] ?? false)) {
                $this->emit($progress, $stage, "warn", "Method {$number}/{$total} could not complete: " . (string) ($result["message"] ?? "WordPress returned no usable result."), [
                    "strategy" => $strategy,
                    "strategy_number" => $number,
                    "strategy_total" => $total,
                ]);
                continue;
            }

            if ($result["found"] ?? false) {
                $mediaId = (int) ($result["media_id"] ?? 0);
                $verification = str_replace("_", " ", (string) ($result["verification"] ?? "SHA-256"));
                $this->emit($progress, $stage, "ok", "Method {$number}/{$total} matched WordPress attachment #{$mediaId}: exact {$verification} confirmed. The existing media file will be reused and no upload will occur.", [
                    "strategy" => $strategy,
                    "strategy_number" => $number,
                    "strategy_total" => $total,
                    "media_id" => $mediaId,
                    "url" => (string) ($result["url"] ?? ""),
                    "candidate_count" => (int) ($result["candidate_count"] ?? 0),
                    "scanned_count" => (int) ($result["scanned_count"] ?? 0),
                    "hashed_count" => (int) ($result["hashed_count"] ?? 0),
                    "match_method" => $strategy,
                    "matched" => true,
                    "reused" => true,
                ]);
                $this->emitSkippedAfterMatch($progress, $strategy, $index + 1, $total, $mediaId);

                return [
                    "success" => true,
                    "found" => $mediaId > 0,
                    "media_id" => $mediaId,
                    "url" => (string) ($result["url"] ?? ""),
                    "match_method" => $strategy,
                    "verification" => (string) ($result["verification"] ?? ""),
                    "attempts" => $attempts,
                ];
            }

            $this->emit($progress, $stage, "ok", $this->noMatchMessage($strategy, $number, $total, $result), [
                "strategy" => $strategy,
                "strategy_number" => $number,
                "strategy_total" => $total,
                "candidate_count" => (int) ($result["candidate_count"] ?? 0),
                "scanned_count" => (int) ($result["scanned_count"] ?? 0),
                "hashed_count" => (int) ($result["hashed_count"] ?? 0),
                "matched" => false,
            ]);
        }

        $this->emit($progress, "deduplicate_complete", "ok", "All {$total} reuse methods completed without an exact match. A new WordPress media upload is required.", [
            "strategy_total" => $total,
            "matched" => false,
            "reused" => false,
        ]);

        return [
            "success" => true,
            "found" => false,
            "media_id" => 0,
            "url" => "",
            "match_method" => "",
            "verification" => "",
            "attempts" => $attempts,
        ];
    }

    private function emitSkippedAfterMatch(?callable $progress, string $matchedStrategy, int $completed, int $total, int $mediaId): void
    {
        $strategies = array_keys(self::STRATEGIES);
        for ($index = $completed; $index < count($strategies); $index++) {
            $strategy = $strategies[$index];
            $number = $index + 1;
            $this->emit($progress, "deduplicate_" . $strategy, "ok", "Method {$number}/{$total} skipped: attachment #{$mediaId} was already confirmed by the {$matchedStrategy} method.", [
                "strategy" => $strategy,
                "strategy_number" => $number,
                "strategy_total" => $total,
                "media_id" => $mediaId,
                "match_method" => $matchedStrategy,
                "skipped" => true,
                "reused" => true,
            ]);
        }
    }

    private function unavailableReason(string $strategy, MediaArtifact $artifact, int $currentMediaId): string
    {
        return match ($strategy) {
            "current" => $currentMediaId > 0 ? "" : "the destination has no currently assigned WordPress image",
            "source_url" => $artifact->sourceUrl !== "" ? "" : "the selected image has no stable source URL",
            "filename" => $artifact->filename !== "" ? "" : "the selected image has no usable filename",
            default => "",
        };
    }

    private function startMessage(string $strategy, int $number, int $total, int $currentMediaId): string
    {
        $detail = match ($strategy) {
            "current" => "checking currently assigned attachment #{$currentMediaId} and its stored or computed SHA-256 digest",
            "sha256" => "searching Hexa-indexed SHA-256 attachment metadata",
            "source_url" => "checking stored source URLs and matching WordPress attachment URLs",
            "filename" => "checking original filenames, attached filenames, titles, and slugs",
            "fingerprint" => "scanning image attachments by MIME type, byte size, dimensions, and final SHA-256 digest",
            default => "checking the WordPress media library",
        };

        return "Method {$number}/{$total}: {$detail}.";
    }

    private function noMatchMessage(string $strategy, int $number, int $total, array $result): string
    {
        $candidates = (int) ($result["candidate_count"] ?? 0);
        $scanned = (int) ($result["scanned_count"] ?? 0);
        $hashed = (int) ($result["hashed_count"] ?? 0);

        return "Method {$number}/{$total} complete: {$candidates} candidate(s) found, {$scanned} inspected, {$hashed} byte digest(s) computed; no exact match via " . self::STRATEGIES[$strategy] . ".";
    }

    private function emit(?callable $progress, string $stage, string $state, string $message, array $context = []): void
    {
        if ($progress) {
            $progress($stage, $state, $message, $context);
        }
    }
}
