<?php

namespace hexa_package_wordpress\Services;

class WordPressUserDeletionService
{
    private const DEFAULT_SUGGESTION_LIMIT = 4;

    public function __construct(
        protected WordPressManagerService $wordpress,
    ) {
    }

    public function context(array $target, int $sourceUserId, array $options = []): array
    {
        if ($sourceUserId <= 0) {
            return $this->failure("WordPress user ID is required.", $sourceUserId);
        }

        $target = $this->wordpress->normalizeTarget($target);
        $limit = max(1, min(12, (int) ($options["suggestion_limit"] ?? self::DEFAULT_SUGGESTION_LIMIT)));
        $excludedUserIds = $this->userIds($options["exclude_user_ids"] ?? []);
        $excludedUserIds[] = $sourceUserId;
        $excludedUserIds = array_values(array_unique($excludedUserIds));
        $raw = $this->wordpress->usesWpToolkit($target)
            ? $this->toolkitContext($target, $sourceUserId, $limit, $excludedUserIds)
            : $this->restContext($target, $sourceUserId, $limit, $excludedUserIds);

        if (!($raw["success"] ?? false)) {
            return $this->failure((string) ($raw["message"] ?? "WordPress deletion context could not be loaded."), $sourceUserId, [
                "connection_mode" => $this->wordpress->usesWpToolkit($target) ? "wptoolkit" : "rest",
            ]);
        }

        $sourceUser = $this->normalizeCandidate((array) ($raw["source_user"] ?? []), "source");
        if (($sourceUser["id"] ?? 0) <= 0) {
            return $this->failure("The WordPress user no longer exists.", $sourceUserId, [
                "connection_mode" => (string) ($raw["connection_mode"] ?? ""),
            ]);
        }

        $contentCountKnown = isset($raw["content_count"]) && is_numeric($raw["content_count"]);
        $contentCount = $contentCountKnown ? max(0, (int) $raw["content_count"]) : null;
        $requiresReassignment = !$contentCountKnown || $contentCount > 0;
        $excluded = array_fill_keys($excludedUserIds, true);
        $admins = $this->normalizeCandidates((array) ($raw["administrators"] ?? []), "administrator", $excluded, $limit);
        $topAuthors = $this->normalizeCandidates((array) ($raw["top_authors"] ?? []), "top_content", $excluded, $limit);
        $otherAuthors = $this->normalizeCandidates((array) ($raw["other_authors"] ?? []), "author", $excluded, $limit);

        $groups = [];
        if ($admins !== []) {
            $groups[] = ["key" => "administrators", "label" => "Administrators", "items" => $admins];
        }
        if ($topAuthors !== []) {
            $groups[] = ["key" => "top_authors", "label" => "Authors with the most content", "items" => $topAuthors];
        }
        if ($otherAuthors !== []) {
            $groups[] = ["key" => "other_authors", "label" => "Other authors", "items" => $otherAuthors];
        }

        $message = !$contentCountKnown
            ? "WordPress could not provide a complete content count. Select another user so any existing content is preserved."
            : ($contentCount > 0
                ? $contentCount . " content item" . ($contentCount === 1 ? "" : "s") . " must be assigned to another user before deletion."
                : "This WordPress user owns no content and can be deleted without reassignment.");

        return [
            "success" => true,
            "message" => $message,
            "connection_mode" => (string) ($raw["connection_mode"] ?? ($this->wordpress->usesWpToolkit($target) ? "wptoolkit" : "rest")),
            "source_user" => $sourceUser,
            "source_user_id" => $sourceUserId,
            "excluded_user_ids" => $excludedUserIds,
            "content_count" => $contentCount,
            "content_count_known" => $contentCountKnown,
            "requires_reassignment" => $requiresReassignment,
            "candidate_groups" => $groups,
            "suggestions" => [
                "admins" => $admins,
                "top_posters" => array_values(array_merge($topAuthors, $otherAuthors)),
            ],
            "post_count" => $contentCount,
            "requires_reassign" => $requiresReassignment,
        ];
    }

    public function delete(array $target, int $sourceUserId, ?int $reassignUserId = null, array $options = []): array
    {
        $target = $this->wordpress->normalizeTarget($target);
        $context = $this->context($target, $sourceUserId, $options);
        if (!($context["success"] ?? false)) {
            return $context;
        }

        $requiresReassignment = (bool) ($context["requires_reassignment"] ?? true);
        $destination = null;

        if ($requiresReassignment) {
            if ($reassignUserId === null || $reassignUserId <= 0) {
                return $this->deleteFailure("Select another WordPress user to receive all existing content before deleting.", $context);
            }
            if ($reassignUserId === $sourceUserId) {
                return $this->deleteFailure("The deleted user cannot receive its own reassigned content.", $context);
            }
            if (in_array($reassignUserId, (array) ($context["excluded_user_ids"] ?? []), true)) {
                return $this->deleteFailure("The selected reassignment user is also scheduled for deletion.", $context);
            }

            $destination = $this->destinationUser($target, $reassignUserId);
            if ($destination === null) {
                return $this->deleteFailure("The selected reassignment user was not found on this WordPress site.", $context);
            }
        } else {
            $reassignUserId = null;
        }

        $result = $this->wordpress->deleteUser($target, $sourceUserId, $reassignUserId);
        if (!($result["success"] ?? false)) {
            return $this->deleteFailure((string) ($result["message"] ?? "WordPress user deletion failed."), $context, $destination);
        }

        return array_merge($context, [
            "success" => true,
            "message" => (string) ($result["message"] ?? "WordPress user deleted."),
            "deleted_user_id" => $sourceUserId,
            "reassigned_to_user_id" => $reassignUserId,
            "destination_user" => $destination,
            "provider_result" => $result,
        ]);
    }

    private function toolkitContext(array $target, int $sourceUserId, int $limit, array $excludedUserIds): array
    {
        $php = <<<'PHP'
global $wpdb;
$sourceUserId = __SOURCE_USER_ID__;
$limit = __SUGGESTION_LIMIT__;
$excludedUserIds = __EXCLUDED_USER_IDS__;
$source = get_user_by("id", $sourceUserId);
if (!$source) {
    echo "HEXA_USER_DELETION_CONTEXT:" . wp_json_encode(["success" => false, "message" => "The WordPress user was not found."]);
    return;
}
$rowForUser = static function ($user, string $reason = "") use ($wpdb, $excludedUserIds) {
    if (!$user || in_array((int) $user->ID, $excludedUserIds, true)) {
        return null;
    }
    $id = (int) $user->ID;
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type NOT IN ('revision','nav_menu_item')", $id));
    return [
        "id" => $id,
        "ID" => $id,
        "user_login" => (string) $user->user_login,
        "display_name" => (string) $user->display_name,
        "user_email" => (string) $user->user_email,
        "roles" => array_values(array_map("strval", (array) $user->roles)),
        "post_count" => $count,
        "reason" => $reason,
    ];
};
$sourceCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type NOT IN ('revision','nav_menu_item')", $sourceUserId));
$sourceRow = [
    "id" => (int) $source->ID,
    "ID" => (int) $source->ID,
    "user_login" => (string) $source->user_login,
    "display_name" => (string) $source->display_name,
    "user_email" => (string) $source->user_email,
    "roles" => array_values(array_map("strval", (array) $source->roles)),
    "post_count" => $sourceCount,
    "reason" => "source",
];
$administrators = [];
$administratorIds = [];
foreach ((array) get_users(["role" => "administrator", "number" => $limit * 6, "orderby" => "display_name", "order" => "ASC", "fields" => "all"]) as $user) {
    $row = $rowForUser($user, "administrator");
    if (!$row) {
        continue;
    }
    $administrators[] = $row;
    $administratorIds[(int) $row["id"]] = true;
    if (count($administrators) >= $limit) {
        break;
    }
}
$topAuthors = [];
$topRows = $wpdb->get_results($wpdb->prepare("SELECT u.ID, COUNT(p.ID) AS content_count FROM {$wpdb->users} u INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID WHERE u.ID <> %d AND p.post_type NOT IN ('revision','nav_menu_item') GROUP BY u.ID ORDER BY COUNT(p.ID) DESC, u.display_name ASC LIMIT %d", $sourceUserId, $limit * 8));
foreach ((array) $topRows as $topRow) {
    $id = (int) ($topRow->ID ?? 0);
    if ($id <= 0 || isset($administratorIds[$id])) {
        continue;
    }
    $row = $rowForUser(get_user_by("id", $id), "top_content");
    if (!$row) {
        continue;
    }
    $topAuthors[] = $row;
    if (count($topAuthors) >= $limit) {
        break;
    }
}
echo "HEXA_USER_DELETION_CONTEXT:" . wp_json_encode([
    "success" => true,
    "source_user" => $sourceRow,
    "content_count" => $sourceCount,
    "administrators" => $administrators,
    "top_authors" => $topAuthors,
    "other_authors" => [],
]);
PHP;

        $php = str_replace(
            ["__SOURCE_USER_ID__", "__SUGGESTION_LIMIT__", "__EXCLUDED_USER_IDS__"],
            [(string) $sourceUserId, (string) $limit, var_export($excludedUserIds, true)],
            $php,
        );
        $evaluated = $this->wordpress->evaluatePhp($target, $php);
        if (!($evaluated["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($evaluated["message"] ?? "WordPress user deletion context failed.")];
        }

        $payload = $this->decodeMarkedPayload((string) ($evaluated["stdout"] ?? ""), "HEXA_USER_DELETION_CONTEXT:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "WordPress returned an invalid user deletion context."];
        }

        $payload["connection_mode"] = "wptoolkit";
        return $payload;
    }

    private function restContext(array $target, int $sourceUserId, int $limit, array $excludedUserIds): array
    {
        $sourceResult = $this->wordpress->listUsers($target, [
            "include" => [$sourceUserId],
            "per_page" => 1,
            "force_refresh" => true,
        ]);
        $source = (array) ($sourceResult["users"][0] ?? []);
        if (!($sourceResult["success"] ?? false) || (int) ($source["id"] ?? $source["ID"] ?? 0) !== $sourceUserId) {
            return ["success" => false, "message" => (string) ($sourceResult["message"] ?? "The WordPress user was not found.")];
        }

        $loaded = $this->wordpress->listUsers($target, [
            "per_page" => max(20, $limit * 5),
            "force_refresh" => true,
        ]);
        if (!($loaded["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($loaded["message"] ?? "WordPress users could not be loaded.")];
        }

        $administrators = [];
        $otherAuthors = [];
        foreach ((array) ($loaded["users"] ?? []) as $user) {
            if (!is_array($user) || in_array((int) ($user["id"] ?? $user["ID"] ?? 0), $excludedUserIds, true)) {
                continue;
            }
            $roles = array_map("strval", (array) ($user["roles"] ?? []));
            if (in_array("administrator", $roles, true) && count($administrators) < $limit) {
                $user["reason"] = "administrator";
                $administrators[] = $user;
                continue;
            }
            if (count($otherAuthors) < $limit) {
                $user["reason"] = "author";
                $otherAuthors[] = $user;
            }
        }

        return [
            "success" => true,
            "connection_mode" => "rest",
            "source_user" => $source,
            "content_count" => null,
            "administrators" => $administrators,
            "top_authors" => [],
            "other_authors" => $otherAuthors,
        ];
    }

    private function destinationUser(array $target, int $userId): ?array
    {
        $loaded = $this->wordpress->listUsers($target, [
            "include" => [$userId],
            "per_page" => 1,
            "force_refresh" => true,
        ]);
        if (!($loaded["success"] ?? false) || empty($loaded["users"][0])) {
            return null;
        }

        $candidate = $this->normalizeCandidate((array) $loaded["users"][0], "destination");
        return ($candidate["id"] ?? 0) > 0 ? $candidate : null;
    }

    private function normalizeCandidates(array $rows, string $reason, array &$excluded, int $limit): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = $this->normalizeCandidate($row, $reason);
            $id = (int) ($candidate["id"] ?? 0);
            if ($id <= 0 || isset($excluded[$id])) {
                continue;
            }
            $excluded[$id] = true;
            $normalized[] = $candidate;
            if (count($normalized) >= $limit) {
                break;
            }
        }

        return $normalized;
    }

    private function normalizeCandidate(array $candidate, string $reason): array
    {
        $id = (int) ($candidate["id"] ?? $candidate["ID"] ?? $candidate["wp_user_id"] ?? 0);
        $login = trim((string) ($candidate["user_login"] ?? $candidate["login"] ?? ""));
        $email = trim((string) ($candidate["user_email"] ?? $candidate["email"] ?? ""));
        $name = trim((string) ($candidate["display_name"] ?? $candidate["name"] ?? $login));
        $postCount = isset($candidate["post_count"]) && is_numeric($candidate["post_count"])
            ? max(0, (int) $candidate["post_count"])
            : null;

        return [
            "id" => $id,
            "ID" => $id,
            "wp_user_id" => $id,
            "name" => $name !== "" ? $name : ($login !== "" ? $login : "WordPress user #" . $id),
            "display_name" => $name,
            "user_login" => $login,
            "user_email" => $email,
            "roles" => array_values(array_map("strval", (array) ($candidate["roles"] ?? []))),
            "post_count" => $postCount,
            "reason" => (string) ($candidate["reason"] ?? $reason),
        ];
    }

    private function userIds(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[\s,]+/', $values) ?: [];
        } elseif (!is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function deleteFailure(string $message, array $context, ?array $destination = null): array
    {
        return array_merge($context, [
            "success" => false,
            "message" => $message,
            "destination_user" => $destination,
        ]);
    }

    private function failure(string $message, int $sourceUserId, array $extra = []): array
    {
        return array_merge([
            "success" => false,
            "message" => $message,
            "source_user_id" => $sourceUserId,
            "content_count" => null,
            "content_count_known" => false,
            "requires_reassignment" => true,
            "candidate_groups" => [],
            "suggestions" => ["admins" => [], "top_posters" => []],
            "post_count" => null,
            "requires_reassign" => true,
        ], $extra);
    }

    private function decodeMarkedPayload(string $stdout, string $marker): ?array
    {
        foreach (preg_split("/\r?\n/", $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === "" || !str_contains($line, $marker)) {
                continue;
            }
            $decoded = json_decode(trim(substr($line, strpos($line, $marker) + strlen($marker))), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
