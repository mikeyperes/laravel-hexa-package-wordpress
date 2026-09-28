<?php

namespace hexa_package_wordpress\Media;

use hexa_core\Operations\Contracts\OperationEventSource;
use hexa_core\Operations\OperationLog;

/**
 * Shows WordPress media uploads that an operation started as steps of that
 * operation. An operation names its uploads in step context:
 * `operation_child_media_id` (+ `operation_child_media_label`) or
 * `operation_child_media_ids` ([{id, label}]); the run id itself is also
 * checked, for operations that upload under their own id.
 */
final class WordPressMediaOperationEvents implements OperationEventSource
{
    /** Progress reached when an upload is at each stage. */
    private const PROGRESS_BY_STAGE = [
        'start' => 12, 'connect' => 18, 'destination_snapshot' => 24, 'download' => 32, 'acquire' => 36,
        'normalize' => 44, 'inspect' => 48, 'validate' => 48, 'deduplicate_plan' => 52, 'deduplicate_current' => 56,
        'deduplicate_sha256' => 58, 'deduplicate_source_url' => 60, 'deduplicate_filename' => 62,
        'deduplicate_fingerprint' => 64, 'deduplicate' => 58, 'upload' => 68, 'assign' => 78,
        'avatar_integrity' => 84, 'cache_purge' => 88, 'verify' => 96, 'failure' => 100, 'rollback' => 100, 'cleanup' => 100,
    ];

    public function __construct(private readonly WordPressMediaOperationStore $store) {}

    public function operationEvents(string $runId, array $contexts): array
    {
        $events = [];
        $progress = [];
        $failed = false;
        foreach ($this->uploads($runId, $contexts) as $uploadId => $label) {
            try {
                $snapshot = $this->store->snapshot($uploadId);
            } catch (\Throwable) {
                $snapshot = null;
            }
            if (! is_array($snapshot) || (array) ($snapshot['events'] ?? []) === []) {
                continue;
            }

            $token = substr(sha1($uploadId), 0, 10);
            $prefix = $label !== '' ? $label.': ' : '';
            $uploadProgress = 0;
            foreach ((array) $snapshot['events'] as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $stage = trim((string) ($event['stage'] ?? 'media')) ?: 'media';
                $state = strtolower((string) ($event['state'] ?? 'working'));
                $events[] = [
                    'key' => 'media:'.$token.':'.$stage,
                    'id' => 'media-'.$token.'-'.(int) ($event['sequence'] ?? 0),
                    'title' => $prefix.ucwords(str_replace('_', ' ', $stage)),
                    'state' => $state === 'error' ? 'error' : (in_array($state, ['ok', 'warn'], true) ? 'success' : 'running'),
                    'message' => $prefix.(string) ($event['message'] ?? ''),
                    'at' => (string) ($event['at'] ?? ''),
                ];
                $uploadProgress = max($uploadProgress, self::PROGRESS_BY_STAGE[$stage] ?? 30);
            }

            $uploadState = strtolower((string) ($snapshot['state'] ?? ''));
            if (in_array($uploadState, ['success', 'complete', 'completed', 'ok', 'error'], true)) {
                $uploadProgress = 100;
            }
            $failed = $failed || $uploadState === 'error';
            $progress[] = $uploadProgress;
        }

        return [
            'events' => $events,
            'progress' => $progress === [] ? null : (int) floor(array_sum($progress) / count($progress)),
            'failed' => $failed,
        ];
    }

    /** @return array<string, string> upload id => label */
    private function uploads(string $runId, array $contexts): array
    {
        $uploads = [$runId => ''];
        foreach ($contexts as $context) {
            $id = OperationLog::cleanRunId((string) ($context['operation_child_media_id'] ?? ''));
            if ($id !== null) {
                $uploads[$id] = trim((string) ($context['operation_child_media_label'] ?? ''));
            }
            foreach ((array) ($context['operation_child_media_ids'] ?? []) as $item) {
                $itemId = is_array($item) ? OperationLog::cleanRunId((string) ($item['id'] ?? '')) : null;
                if ($itemId !== null) {
                    $uploads[$itemId] = trim((string) ($item['label'] ?? ''));
                }
            }
        }

        return $uploads;
    }
}
