<?php

namespace hexa_package_wordpress\Http\Controllers;

use hexa_package_wordpress\Media\WordPressMediaOperationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class MediaOperationController extends Controller
{
    public function show(string $operationId, WordPressMediaOperationStore $operations): JsonResponse
    {
        $operationId = $operations->normalizeId($operationId);
        $snapshot = $operations->snapshot($operationId);
        if (!$snapshot) {
            return response()->json([
                "success" => true,
                "operation_id" => $operationId,
                "state" => "pending",
                "context" => [],
                "events" => [],
                "result" => null,
                "message" => "Waiting for the media operation to start.",
            ], 202);
        }

        return response()->json(array_merge(["success" => true], $snapshot));
    }
}
