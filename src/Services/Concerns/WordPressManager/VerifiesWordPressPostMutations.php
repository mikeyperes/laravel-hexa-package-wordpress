<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

trait VerifiesWordPressPostMutations
{
    private function createToolkitPost(array $target, array $payload): array
    {
        return $this->mutateToolkitPostWithVerification($target, 'create', 0, $payload);
    }

    private function updateToolkitPost(array $target, int $postId, array $payload): array
    {
        return $this->mutateToolkitPostWithVerification($target, 'update', $postId, $payload);
    }

    private function mutateToolkitPostWithVerification(array $target, string $operation, int $postId, array $payload): array
    {
        $marker = $operation === 'create' ? 'HEXA_TOOLKIT_CREATE' : 'HEXA_TOOLKIT_UPDATE';
        $php = <<<'PHP'
$operation = __OPERATION__;
$postId = __POST_ID__;
$payload = __PAYLOAD__;
$provided = is_array($payload["_provided"] ?? null) ? $payload["_provided"] : [];
$providedTaxonomies = array_values(array_unique(array_map("strval", (array) ($payload["_provided_taxonomies"] ?? []))));
$isCreate = $operation === "create";
$has = static fn(string $field): bool => (bool) ($provided[$field] ?? false);
$normalizeIds = static function ($values): array {
    $ids = array_values(array_unique(array_filter(array_map("intval", (array) $values))));
    sort($ids, SORT_NUMERIC);
    return $ids;
};
$describe = static function ($value) {
    if (is_string($value) && strlen($value) > 160) {
        return [
            "bytes" => strlen($value),
            "sha256" => hash("sha256", $value),
            "sample" => substr($value, 0, 120),
        ];
    }
    return $value;
};
$canonicalizeKsesField = static function (string $field, string $value): string {
    // WordPress applies the post KSES allow-list before persisting content and
    // excerpts. Compare against that canonical database representation so
    // harmless entity normalization (for example ' to &apos; in attributes)
    // is not mistaken for content corruption. Every other transformation still
    // fails the exact readback below.
    return in_array($field, ["content", "excerpt"], true)
        ? wp_kses_post($value)
        : $value;
};
$canonicalizePostDate = static function (string $value): string {
    $value = trim($value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $value, $matches) === 1) {
        return $matches[1] . ' ' . $matches[2];
    }

    return $value;
};
$emit = static function (array $result): void {
    echo "__MARKER__:" . wp_json_encode($result);
};

$originalPost = !$isCreate && $postId > 0 ? get_post($postId) : null;
if (!$isCreate && !$originalPost) {
    $emit(["success" => false, "message" => "WordPress post update preflight failed: post not found.", "data" => null]);
    return;
}

$requestedPostType = $isCreate
    ? (trim((string) ($payload["post_type"] ?? "post")) ?: "post")
    : ($has("post_type") ? (trim((string) ($payload["post_type"] ?? "")) ?: "post") : (string) $originalPost->post_type);
$requestedStatus = $isCreate || $has("status")
    ? (trim((string) ($payload["status"] ?? "draft")) ?: "draft")
    : (string) $originalPost->post_status;
$stageStatus = !$isCreate && $originalPost && $requestedStatus === (string) $originalPost->post_status
    ? $requestedStatus
    : "draft";
$preflightErrors = [];

if (!post_type_exists($requestedPostType)) {
    $preflightErrors[] = "Post type not found: " . $requestedPostType . ".";
}
if (!get_post_status_object($requestedStatus)) {
    $preflightErrors[] = "Post status not found: " . $requestedStatus . ".";
}
if ($has("date") && trim((string) ($payload["date"] ?? "")) === "") {
    $preflightErrors[] = "Post date cannot be empty when supplied.";
}

$resolvedAuthorId = null;
if ($has("author")) {
    $requestedAuthor = trim((string) ($payload["author"] ?? ""));
    if ($requestedAuthor === "") {
        $preflightErrors[] = "Post author cannot be empty when supplied.";
    } elseif (is_numeric($requestedAuthor)) {
        $candidateId = (int) $requestedAuthor;
        $resolvedAuthorId = $candidateId > 0 && get_userdata($candidateId) ? $candidateId : null;
    } else {
        $resolvedUser = get_user_by("login", $requestedAuthor);
        $resolvedAuthorId = $resolvedUser ? (int) $resolvedUser->ID : null;
    }
    if ($requestedAuthor !== "" && !$resolvedAuthorId) {
        $preflightErrors[] = "Post author not found: " . $requestedAuthor . ".";
    }
}

$expectedTermChanges = [];
if ($has("categories")) {
    $expectedTermChanges["category"] = $normalizeIds($payload["categories"] ?? []);
}
if ($has("tags")) {
    $expectedTermChanges["post_tag"] = $normalizeIds($payload["tags"] ?? []);
}
foreach ($providedTaxonomies as $taxonomy) {
    $taxonomy = trim($taxonomy);
    if ($taxonomy !== "") {
        $expectedTermChanges[$taxonomy] = $normalizeIds($payload["taxonomies"][$taxonomy] ?? []);
    }
}

if (array_key_exists("category", $expectedTermChanges)
    && $expectedTermChanges["category"] === []
    && $requestedPostType === "post") {
    $defaultCategoryId = (int) get_option("default_category");
    if ($defaultCategoryId > 0 && term_exists($defaultCategoryId, "category")) {
        $expectedTermChanges["category"] = [$defaultCategoryId];
    }
}
$taxonomyAttempts = array_fill_keys(array_keys($expectedTermChanges), 0);

foreach ($expectedTermChanges as $taxonomy => $termIds) {
    if (!taxonomy_exists($taxonomy)) {
        $preflightErrors[] = "Taxonomy not found: " . $taxonomy . ".";
        continue;
    }
    if (post_type_exists($requestedPostType) && !is_object_in_taxonomy($requestedPostType, $taxonomy)) {
        $preflightErrors[] = "Taxonomy " . $taxonomy . " is not registered for post type " . $requestedPostType . ".";
        continue;
    }
    foreach ($termIds as $termId) {
        if (!term_exists($termId, $taxonomy)) {
            $preflightErrors[] = "Term #" . $termId . " was not found in taxonomy " . $taxonomy . ".";
        }
    }
}

if ($has("featured_media")) {
    $featuredMediaId = (int) ($payload["featured_media"] ?? 0);
    if ($featuredMediaId > 0) {
        $attachment = get_post($featuredMediaId);
        if (!$attachment || (string) $attachment->post_type !== "attachment" || !wp_attachment_is_image($featuredMediaId)) {
            $preflightErrors[] = "Featured media #" . $featuredMediaId . " is not a valid image attachment.";
        }
    }
}

$taxonomyNames = [];
if ($originalPost) {
    $taxonomyNames = array_merge($taxonomyNames, get_object_taxonomies((string) $originalPost->post_type, "names"));
}
if (post_type_exists($requestedPostType)) {
    $taxonomyNames = array_merge($taxonomyNames, get_object_taxonomies($requestedPostType, "names"));
}
$taxonomyNames = array_values(array_unique(array_merge($taxonomyNames, array_keys($expectedTermChanges))));
$taxonomyNames = array_values(array_filter($taxonomyNames, "taxonomy_exists"));

$snapshot = static function (int $id) use (&$taxonomyNames, $normalizeIds): ?array {
    $post = get_post($id);
    if (!$post) {
        return null;
    }
    $terms = [];
    foreach ($taxonomyNames as $taxonomy) {
        $ids = wp_get_object_terms($id, $taxonomy, ["fields" => "ids"]);
        $terms[$taxonomy] = is_wp_error($ids) ? ["error" => $ids->get_error_message()] : $normalizeIds($ids);
    }
    $author = get_userdata((int) $post->post_author);
    return [
        "post_id" => (int) $post->ID,
        "post_url" => (string) (get_permalink($id) ?: ""),
        "post_title" => (string) $post->post_title,
        "post_content" => (string) $post->post_content,
        "post_excerpt" => (string) $post->post_excerpt,
        "post_status" => (string) $post->post_status,
        "post_date" => (string) $post->post_date,
        "post_date_gmt" => (string) $post->post_date_gmt,
        "post_modified" => (string) $post->post_modified,
        "post_modified_gmt" => (string) $post->post_modified_gmt,
        "post_name" => (string) $post->post_name,
        "post_type" => (string) $post->post_type,
        "post_parent" => (int) $post->post_parent,
        "post_author" => (int) $post->post_author,
        "author_name" => $author ? (string) $author->display_name : "",
        "author_url" => $author ? (string) get_author_posts_url((int) $author->ID, (string) $author->user_nicename) : "",
        "featured_media" => (int) get_post_thumbnail_id($id),
        "terms" => $terms,
    ];
};

$compare = static function (array $expected, ?array $actual, array $coreFields, array $termFields, bool $checkFeatured) use ($describe): array {
    if (!$actual) {
        return ["post" => ["expected" => "present", "actual" => "missing"]];
    }
    $mismatches = [];
    foreach ($coreFields as $field) {
        $expectedValue = $expected[$field] ?? null;
        $actualValue = $actual[$field] ?? null;
        if ($actualValue !== $expectedValue) {
            $mismatches[$field] = ["expected" => $describe($expectedValue), "actual" => $describe($actualValue)];
        }
    }
    foreach ($termFields as $taxonomy => $expectedIds) {
        $actualIds = $actual["terms"][$taxonomy] ?? null;
        if ($actualIds !== $expectedIds) {
            $mismatches["taxonomy:" . $taxonomy] = ["expected" => $expectedIds, "actual" => $actualIds];
        }
    }
    if ($checkFeatured && (int) ($actual["featured_media"] ?? 0) !== (int) ($expected["featured_media"] ?? 0)) {
        $mismatches["featured_media"] = [
            "expected" => (int) ($expected["featured_media"] ?? 0),
            "actual" => (int) ($actual["featured_media"] ?? 0),
        ];
    }
    return $mismatches;
};

$formatData = static function (?array $state, array $verification = []) use ($expectedTermChanges, &$taxonomyAttempts): ?array {
    if (!$state) {
        return null;
    }
    $verifiedTaxonomies = [];
    foreach ($expectedTermChanges as $taxonomy => $expectedIds) {
        $actual = $state["terms"][$taxonomy] ?? null;
        $actualIds = is_array($actual) && !array_key_exists("error", $actual)
            ? array_values(array_map("intval", $actual))
            : [];
        sort($actualIds, SORT_NUMERIC);
        $success = $actualIds === $expectedIds;
        $verifiedTaxonomies[$taxonomy] = [
            "success" => $success,
            "attempts" => (int) ($taxonomyAttempts[$taxonomy] ?? 0),
            "expected" => $expectedIds,
            "actual" => $actualIds,
            "message" => $success
                ? "Taxonomy assignment verified."
                : sprintf(
                    "%s expected [%s] but WordPress confirmed [%s].",
                    $taxonomy,
                    implode(", ", $expectedIds),
                    implode(", ", $actualIds),
                ),
        ];
    }
    $taxonomySuccess = !in_array(false, array_column($verifiedTaxonomies, "success"), true);
    $data = [
        "post_id" => (int) $state["post_id"],
        "post_url" => (string) $state["post_url"],
        "post_status" => (string) $state["post_status"],
        "post_title" => (string) $state["post_title"],
        "post_excerpt" => (string) $state["post_excerpt"],
        "post_date" => (string) $state["post_date"],
        "post_slug" => (string) $state["post_name"],
        "post_type" => (string) $state["post_type"],
        "author_id" => (int) $state["post_author"],
        "author_name" => (string) $state["author_name"],
        "author_url" => (string) $state["author_url"],
        "featured_media" => (int) $state["featured_media"],
        "categories" => (array) ($state["terms"]["category"] ?? []),
        "tags" => (array) ($state["terms"]["post_tag"] ?? []),
        "taxonomies" => (array) $state["terms"],
        "post_content_bytes" => strlen((string) $state["post_content"]),
        "post_content_sha256" => hash("sha256", (string) $state["post_content"]),
        "verification" => $verification,
        "taxonomy_verification" => [
            "success" => $taxonomySuccess,
            "message" => $taxonomySuccess
                ? "Post taxonomies were assigned and verified."
                : "One or more post taxonomies could not be verified.",
            "taxonomies" => $verifiedTaxonomies,
        ],
    ];
    if (is_array($verification["rollback"] ?? null)) {
        $data["rollback"] = $verification["rollback"];
    }

    return $data;
};

$original = $originalPost ? $snapshot((int) $originalPost->ID) : null;
if ($preflightErrors !== []) {
    $emit([
        "success" => false,
        "message" => "WordPress post mutation preflight failed: " . implode(" ", $preflightErrors),
        "data" => $formatData($original, ["verified" => false, "phase" => "preflight", "errors" => $preflightErrors]),
    ]);
    return;
}

$rollback = static function (int $id, array $before) use ($snapshot, $compare, $taxonomyNames): array {
    global $wpdb;
    $core = [
        "post_author" => (int) $before["post_author"],
        "post_date" => (string) $before["post_date"],
        "post_date_gmt" => (string) $before["post_date_gmt"],
        "post_content" => (string) $before["post_content"],
        "post_title" => (string) $before["post_title"],
        "post_excerpt" => (string) $before["post_excerpt"],
        "post_status" => (string) $before["post_status"],
        "post_name" => (string) $before["post_name"],
        "post_modified" => (string) $before["post_modified"],
        "post_modified_gmt" => (string) $before["post_modified_gmt"],
        "post_type" => (string) $before["post_type"],
    ];
    $errors = [];
    $updated = $wpdb->update($wpdb->posts, $core, ["ID" => $id]);
    if ($updated === false) {
        $errors[] = "Core rollback failed: " . ((string) $wpdb->last_error ?: "unknown database error");
    }
    foreach ($taxonomyNames as $taxonomy) {
        $termIds = $before["terms"][$taxonomy] ?? [];
        if (!is_array($termIds) || array_key_exists("error", $termIds)) {
            continue;
        }
        $termResult = wp_set_object_terms($id, $termIds, $taxonomy, false);
        if (is_wp_error($termResult)) {
            $errors[] = "Taxonomy rollback failed for " . $taxonomy . ": " . $termResult->get_error_message();
        }
    }
    $thumbnailId = (int) ($before["featured_media"] ?? 0);
    if ($thumbnailId > 0) {
        update_post_meta($id, "_thumbnail_id", $thumbnailId);
    } else {
        delete_post_meta($id, "_thumbnail_id");
    }
    clean_post_cache($id);
    $actual = $snapshot($id);
    $rollbackCoreFields = [
        "post_author", "post_date", "post_date_gmt", "post_content", "post_title", "post_excerpt",
        "post_status", "post_name", "post_modified", "post_modified_gmt", "post_type",
    ];
    $rollbackTerms = [];
    foreach ($taxonomyNames as $taxonomy) {
        if (isset($before["terms"][$taxonomy]) && is_array($before["terms"][$taxonomy]) && !array_key_exists("error", $before["terms"][$taxonomy])) {
            $rollbackTerms[$taxonomy] = $before["terms"][$taxonomy];
        }
    }
    $mismatches = $compare($before, $actual, $rollbackCoreFields, $rollbackTerms, true);
    return [
        "success" => $errors === [] && $mismatches === [],
        "errors" => $errors,
        "mismatches" => $mismatches,
        "state" => $actual,
    ];
};

$forceDraft = static function (int $id) use ($snapshot): array {
    global $wpdb;
    $updated = $wpdb->update($wpdb->posts, ["post_status" => "draft"], ["ID" => $id]);
    clean_post_cache($id);
    $state = $snapshot($id);
    $mismatches = ($state["post_status"] ?? null) === "draft"
        ? []
        : ["post_status" => ["expected" => "draft", "actual" => $state["post_status"] ?? null]];

    return [
        "success" => $updated !== false && $mismatches === [],
        "errors" => $updated === false ? ["Failed to leave the created post as a draft."] : [],
        "mismatches" => $mismatches,
        "state" => $state,
    ];
};

$writeTaxonomies = static function (int $id, array $taxonomies) use (&$taxonomyAttempts): array {
    $errors = [];
    foreach ($taxonomies as $taxonomy => $termIds) {
        $taxonomyAttempts[$taxonomy] = (int) ($taxonomyAttempts[$taxonomy] ?? 0) + 1;
        $termResult = wp_set_object_terms($id, $termIds, $taxonomy, false);
        if (is_wp_error($termResult)) {
            $errors[$taxonomy] = "Taxonomy write failed for " . $taxonomy . ": " . $termResult->get_error_message();
        }
    }

    return $errors;
};

$writeRelationships = static function (int $id) use ($expectedTermChanges, $has, $payload, $writeTaxonomies): array {
    $errors = $writeTaxonomies($id, $expectedTermChanges);
    if ($has("featured_media")) {
        $featuredMediaId = (int) ($payload["featured_media"] ?? 0);
        if ($featuredMediaId > 0) {
            set_post_thumbnail($id, $featuredMediaId);
        } else {
            delete_post_thumbnail($id);
        }
    }
    clean_post_cache($id);

    return $errors;
};

$writeErrors = !$isCreate ? $writeRelationships($postId) : [];
if ($writeErrors !== []) {
    $writeErrors = $writeTaxonomies($postId, array_intersect_key($expectedTermChanges, $writeErrors));
    clean_post_cache($postId);
    if ($writeErrors !== []) {
        $rollbackResult = $original ? $rollback($postId, $original) : null;
        $state = $rollbackResult["state"] ?? $snapshot($postId);
        $emit([
            "success" => false,
            "message" => "WordPress rejected a staged post relationship mutation: " . implode(" ", $writeErrors),
            "data" => $formatData($state, [
                "verified" => false,
                "phase" => "stage_relationship_write",
                "write_errors" => $writeErrors,
                "rollback" => $rollbackResult,
            ]),
        ]);
        return;
    }
}

$core = [];
if ($isCreate || !$originalPost || $stageStatus !== (string) $originalPost->post_status) {
    $core["post_status"] = $stageStatus;
}
$fieldMap = [
    "title" => "post_title",
    "content" => "post_content",
    "excerpt" => "post_excerpt",
    "date" => "post_date",
    "slug" => "post_name",
    "post_type" => "post_type",
];
foreach ($fieldMap as $source => $destination) {
    if (!$has($source)) {
        continue;
    }
    if ($source === "date" && trim((string) ($payload[$source] ?? "")) === "") {
        continue;
    }
    $value = (string) ($payload[$source] ?? "");
    if ($source === "date") {
        $value = $canonicalizePostDate($value);
    }
    $core[$destination] = $source === "slug" ? sanitize_title((string) ($payload[$source] ?? "")) : wp_slash($value);
}
if ($isCreate && !$has("post_type")) {
    $core["post_type"] = $requestedPostType;
}
if ($has("author")) {
    $core["post_author"] = (int) $resolvedAuthorId;
}
$writeThrowableMessage = "";
try {
    if ($isCreate) {
        $writeResult = wp_insert_post($core, true);
    } elseif ($core === []) {
        // Relationship-only changes must not trigger WordPress core date normalization.
        $writeResult = $postId;
    } else {
        $core["ID"] = $postId;
        // wp_update_post intentionally resets the date of an undated draft unless
        // edit_date is present. A staged integrity write must preserve the exact
        // original date until the requested final status is committed.
        $core["edit_date"] = true;
        $writeResult = wp_update_post($core, true);
    }
} catch (\Throwable $exception) {
    $writeThrowableMessage = $exception->getMessage();
    // A third-party hook can throw after WordPress has already committed an update.
    // Updates have a known identity, so continue into exact readback verification.
    // Creates cannot be adopted without a returned identity and remain fail-closed.
    $writeResult = !$isCreate && get_post($postId)
        ? $postId
        : new WP_Error("post_write_hook_exception", $writeThrowableMessage);
}
if (is_wp_error($writeResult)) {
    $rollbackResult = !$isCreate && $original ? $rollback($postId, $original) : null;
    $state = !$isCreate ? ($rollbackResult["state"] ?? $snapshot($postId)) : null;
    $emit([
        "success" => false,
        "message" => "WordPress rejected the staged post mutation: " . $writeResult->get_error_message(),
        "data" => $formatData($state, ["verified" => false, "phase" => "stage_write", "rollback" => $rollbackResult]),
    ]);
    return;
}
$postId = (int) $writeResult;

$writeErrors = $isCreate ? $writeRelationships($postId) : [];

$stageExpected = $isCreate ? [] : $original;
$stageCoreFields = $isCreate
    ? ["post_title", "post_content", "post_status", "post_type"]
    : ["post_title", "post_content", "post_excerpt", "post_status", "post_date", "post_name", "post_type", "post_author"];
$stageExpected["post_status"] = $stageStatus;
$stageExpected["post_type"] = $requestedPostType;
foreach ($fieldMap as $source => $destination) {
    if (!$has($source)) {
        continue;
    }
    if ($source === "date" && trim((string) ($payload[$source] ?? "")) === "") {
        continue;
    }
    $expectedValue = (string) ($payload[$source] ?? "");
    if ($source === "date") {
        $expectedValue = $canonicalizePostDate($expectedValue);
    }
    $stageExpected[$destination] = $source === "slug"
        ? sanitize_title($expectedValue)
        : $canonicalizeKsesField($source, $expectedValue);
    if (!in_array($destination, $stageCoreFields, true)) {
        $stageCoreFields[] = $destination;
    }
}
if ($has("author")) {
    $stageExpected["post_author"] = (int) $resolvedAuthorId;
    if (!in_array("post_author", $stageCoreFields, true)) {
        $stageCoreFields[] = "post_author";
    }
}
$stageTerms = $isCreate ? $expectedTermChanges : (array) $original["terms"];
foreach ($expectedTermChanges as $taxonomy => $termIds) {
    $stageTerms[$taxonomy] = $termIds;
}
$stageExpected["featured_media"] = $has("featured_media")
    ? (int) ($payload["featured_media"] ?? 0)
    : (int) ($original["featured_media"] ?? 0);
$checkStageFeatured = !$isCreate || $has("featured_media");
$stageState = $snapshot($postId);
$stageMismatches = $compare($stageExpected, $stageState, $stageCoreFields, $stageTerms, $checkStageFeatured);
$retryTaxonomies = array_intersect_key($expectedTermChanges, $writeErrors);
foreach ($expectedTermChanges as $taxonomy => $termIds) {
    if (array_key_exists("taxonomy:" . $taxonomy, $stageMismatches)) {
        $retryTaxonomies[$taxonomy] = $termIds;
    }
}
if ($retryTaxonomies !== []) {
    $writeErrors = array_replace(
        array_diff_key($writeErrors, $retryTaxonomies),
        $writeTaxonomies($postId, $retryTaxonomies),
    );
    clean_post_cache($postId);
    $stageState = $snapshot($postId);
    $stageMismatches = $compare($stageExpected, $stageState, $stageCoreFields, $stageTerms, $checkStageFeatured);
}

if ($writeErrors !== [] || $stageMismatches !== []) {
    $rollbackResult = $isCreate ? $forceDraft($postId) : ($original ? $rollback($postId, $original) : null);
    $state = $rollbackResult["state"] ?? $snapshot($postId);
    $problems = array_values($writeErrors);
    foreach ($stageMismatches as $field => $mismatch) {
        if (str_starts_with($field, "taxonomy:")) {
            $taxonomy = substr($field, strlen("taxonomy:"));
            $problems[] = sprintf(
                "%s expected [%s] but WordPress confirmed [%s]",
                $taxonomy,
                implode(", ", (array) ($mismatch["expected"] ?? [])),
                implode(", ", (array) ($mismatch["actual"] ?? [])),
            );
            continue;
        }
        $problems[] = $field;
    }
    $emit([
        "success" => false,
        "message" => "WordPress post verification failed during staging: " . implode(", ", $problems) . ".",
        "data" => $formatData($state, [
            "verified" => false,
            "phase" => "stage_readback",
            "write_errors" => $writeErrors,
            "mismatches" => $stageMismatches,
            "rollback" => $rollbackResult,
        ]),
    ]);
    return;
}

if ($requestedStatus !== $stageStatus) {
    $statusUpdate = ["ID" => $postId, "post_status" => $requestedStatus, "edit_date" => true];
    if ($has("date")) {
        $statusUpdate["post_date"] = wp_slash($canonicalizePostDate((string) $payload["date"]));
    } elseif (in_array((string) ($stageState["post_status"] ?? ""), ["draft", "pending", "auto-draft"], true)
        && (string) ($stageState["post_date_gmt"] ?? "") === "0000-00-00 00:00:00") {
        // Publishing an undated draft is intentionally dated now. Supply that
        // timestamp explicitly so both the mutation and the verifier agree on
        // one deterministic value instead of relying on wp_update_post's hidden
        // clear_date branch.
        $statusUpdate["post_date"] = current_time("mysql");
        $statusUpdate["post_date_gmt"] = current_time("mysql", true);
        $finalTransitionDate = (string) $statusUpdate["post_date"];
    }
    if (!$has("slug")
        && trim((string) ($stageState["post_name"] ?? "")) === ""
        && !in_array($requestedStatus, ["draft", "pending", "auto-draft"], true)) {
        // WordPress generates a slug only when an empty-slug draft moves to a
        // public/final state. Resolve the same unique slug before the write so
        // the final exact readback can distinguish that core behavior from a
        // plugin unexpectedly rewriting the permalink.
        $finalTransitionSlug = wp_unique_post_slug(
            sanitize_title((string) ($stageState["post_title"] ?? "")),
            $postId,
            $requestedStatus,
            (string) ($stageState["post_type"] ?? "post"),
            (int) ($stageState["post_parent"] ?? 0),
        );
        $statusUpdate["post_name"] = wp_slash($finalTransitionSlug);
    }
    $statusThrowableMessage = "";
    try {
        $statusResult = wp_update_post($statusUpdate, true);
    } catch (\Throwable $exception) {
        $statusThrowableMessage = $exception->getMessage();
        clean_post_cache($postId);
        $committedStatus = (string) get_post_field("post_status", $postId);
        // Treat the marker as authoritative only after the exact final readback
        // below proves that the status and every requested field were committed.
        $statusResult = $committedStatus === $requestedStatus
            ? $postId
            : new WP_Error("post_status_hook_exception", $statusThrowableMessage);
    }
    if (is_wp_error($statusResult)) {
        $rollbackResult = $isCreate ? $forceDraft($postId) : ($original ? $rollback($postId, $original) : null);
        $state = $rollbackResult["state"] ?? $snapshot($postId);
        $emit([
            "success" => false,
            "message" => "WordPress rejected the requested final status: " . $statusResult->get_error_message(),
            "data" => $formatData($state, ["verified" => false, "phase" => "finalize_status", "rollback" => $rollbackResult]),
        ]);
        return;
    }
}
clean_post_cache($postId);

$finalExpected = $stageExpected;
$finalExpected["post_status"] = $requestedStatus;
if (isset($finalTransitionDate)) {
    $finalExpected["post_date"] = $finalTransitionDate;
}
if (isset($finalTransitionSlug)) {
    $finalExpected["post_name"] = $finalTransitionSlug;
}
$finalState = $snapshot($postId);
$finalMismatches = $compare($finalExpected, $finalState, $stageCoreFields, $stageTerms, $checkStageFeatured);
if ($finalMismatches !== []) {
    $rollbackResult = $isCreate ? $forceDraft($postId) : ($original ? $rollback($postId, $original) : null);
    $state = $rollbackResult["state"] ?? $snapshot($postId);
    $emit([
        "success" => false,
        "message" => "WordPress changed one or more fields while finalizing the post: " . implode(", ", array_keys($finalMismatches)) . ".",
        "data" => $formatData($state, [
            "verified" => false,
            "phase" => "final_readback",
            "mismatches" => $finalMismatches,
            "rollback" => $rollbackResult,
        ]),
    ]);
    return;
}

$checkedFields = array_values($stageCoreFields);
foreach (array_keys($stageTerms) as $taxonomy) {
    $checkedFields[] = "taxonomy:" . $taxonomy;
}
if ($checkStageFeatured) {
    $checkedFields[] = "featured_media";
}
$emit([
    "success" => true,
    "message" => $isCreate ? "Post created and verified." : "Post updated and verified.",
    "data" => $formatData($finalState, [
        "verified" => true,
        "phase" => "final_readback",
        "checked_fields" => array_values(array_unique($checkedFields)),
        "mismatches" => [],
        "hook_errors" => array_values(array_filter([$writeThrowableMessage, $statusThrowableMessage ?? ""])),
    ]),
]);
PHP;

        $php = strtr($php, [
            '__OPERATION__' => var_export($operation, true),
            '__POST_ID__' => (string) $postId,
            '__PAYLOAD__' => var_export($payload, true),
            '__MARKER__' => $marker,
        ]);
        $result = $this->evaluatePhp($target, $php);
        $parsed = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), $marker.':');
        if (! is_array($parsed)) {
            if (! ($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($result['message'] ?? 'WP Toolkit post mutation failed.'),
                    'data' => null,
                ];
            }

            return ['success' => false, 'message' => 'Failed to parse WP Toolkit post mutation output.', 'data' => null];
        }

        return [
            'success' => (bool) ($parsed['success'] ?? false),
            'message' => (string) ($parsed['message'] ?? (($parsed['success'] ?? false) ? 'WordPress post mutation succeeded.' : 'WordPress post mutation failed.')),
            'data' => is_array($parsed['data'] ?? null) ? $parsed['data'] : null,
        ];
    }

    private function createRestPost(array $target, string $endpoint, array $payload): array
    {
        return $this->mutateRestPostWithVerification($target, 'create', $endpoint, 0, $payload);
    }

    private function getToolkitPostSnapshot(array $target, int $postId): array
    {
        $php = <<<'PHP'
$postId = __POST_ID__;
$post = get_post($postId);
if (!$post) {
    echo "HEXA_TOOLKIT_POST_SNAPSHOT:" . wp_json_encode(["success" => false, "message" => "Post not found.", "data" => null]);
    return;
}
$taxonomies = [];
foreach (get_object_taxonomies((string) $post->post_type, "names") as $taxonomy) {
    $ids = wp_get_object_terms($postId, $taxonomy, ["fields" => "ids"]);
    if (!is_wp_error($ids)) {
        $ids = array_values(array_unique(array_map("intval", (array) $ids)));
        sort($ids, SORT_NUMERIC);
        $taxonomies[$taxonomy] = $ids;
    }
}
$author = get_userdata((int) $post->post_author);
echo "HEXA_TOOLKIT_POST_SNAPSHOT:" . wp_json_encode([
    "success" => true,
    "message" => "Post fetched and read back natively.",
    "data" => [
        "post_id" => (int) $post->ID,
        "post_url" => (string) (get_permalink($postId) ?: ""),
        "post_status" => (string) $post->post_status,
        "post_title" => (string) $post->post_title,
        "post_content" => (string) $post->post_content,
        "post_excerpt" => (string) $post->post_excerpt,
        "post_date" => (string) $post->post_date,
        "post_slug" => (string) $post->post_name,
        "post_type" => (string) $post->post_type,
        "author_id" => (int) $post->post_author,
        "author_name" => $author ? (string) $author->display_name : "",
        "author_url" => $author ? (string) get_author_posts_url((int) $author->ID, (string) $author->user_nicename) : "",
        "featured_media" => (int) get_post_thumbnail_id($postId),
        "categories" => (array) ($taxonomies["category"] ?? []),
        "tags" => (array) ($taxonomies["post_tag"] ?? []),
        "taxonomies" => $taxonomies,
        "post_content_bytes" => strlen((string) $post->post_content),
        "post_content_sha256" => hash("sha256", (string) $post->post_content),
    ],
]);
PHP;
        $result = $this->evaluatePhp($target, str_replace('__POST_ID__', (string) $postId, $php));
        $parsed = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_TOOLKIT_POST_SNAPSHOT:');
        if (! is_array($parsed)) {
            if (! ($result['success'] ?? false)) {
                return ['success' => false, 'message' => (string) ($result['message'] ?? 'WP Toolkit post readback failed.'), 'data' => null];
            }

            return ['success' => false, 'message' => 'Failed to parse WP Toolkit post readback output.', 'data' => null];
        }

        return [
            'success' => (bool) ($parsed['success'] ?? false),
            'message' => (string) ($parsed['message'] ?? 'WP Toolkit post readback completed.'),
            'data' => is_array($parsed['data'] ?? null) ? $parsed['data'] : null,
        ];
    }

    private function updateRestPost(array $target, string $endpoint, int $postId, array $payload): array
    {
        return $this->mutateRestPostWithVerification($target, 'update', $endpoint, $postId, $payload);
    }

    private function mutateRestPostWithVerification(array $target, string $operation, string $endpoint, int $postId, array $payload): array
    {
        $isCreate = $operation === 'create';
        $provided = (array) ($payload['_provided'] ?? []);
        $has = static fn (string $field): bool => (bool) ($provided[$field] ?? false);
        $taxonomyFields = [];
        foreach ((array) ($payload['_provided_taxonomies'] ?? []) as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            $taxonomyFields[$taxonomy] = $this->restTaxonomyField($taxonomy);
        }

        $before = null;
        if (! $isCreate) {
            $beforeResponse = $this->restRequest($target, 'get', $endpoint.'/'.$postId, [], ['context' => 'edit']);
            if (! ($beforeResponse['success'] ?? false) || ! is_array($beforeResponse['data'] ?? null)) {
                return [
                    'success' => false,
                    'message' => 'REST update preflight readback failed: '.(string) ($beforeResponse['message'] ?? 'Post could not be fetched.'),
                    'data' => null,
                ];
            }
            $before = $this->restPostSnapshot((array) $beforeResponse['data'], $taxonomyFields);
        }

        $requestedStatus = $isCreate || $has('status')
            ? (trim((string) ($payload['status'] ?? 'draft')) ?: 'draft')
            : (string) ($before['post_status'] ?? 'draft');
        $stageStatus = ! $isCreate && $requestedStatus === (string) ($before['post_status'] ?? '')
            ? $requestedStatus
            : 'draft';
        $writePayload = $this->buildRestPostPayload($payload);
        $writePayload['status'] = $stageStatus;
        $writeEndpoint = $isCreate ? $endpoint : $endpoint.'/'.$postId;
        $writeResponse = $this->restRequest($target, 'post', $writeEndpoint, $writePayload);
        if (! ($writeResponse['success'] ?? false) || ! is_array($writeResponse['data'] ?? null)) {
            return [
                'success' => false,
                'message' => 'REST staged post mutation failed: '.(string) ($writeResponse['message'] ?? 'WordPress rejected the request.'),
                'data' => $before ? $this->formatVerifiedRestPostData($before, ['verified' => false, 'phase' => 'stage_write']) : null,
            ];
        }

        $postId = (int) (($writeResponse['data']['id'] ?? 0) ?: $postId);
        if ($postId <= 0) {
            return ['success' => false, 'message' => 'REST mutation returned no post ID.', 'data' => null];
        }

        $stageResponse = $this->restRequest($target, 'get', $endpoint.'/'.$postId, [], ['context' => 'edit']);
        $stageState = ($stageResponse['success'] ?? false) && is_array($stageResponse['data'] ?? null)
            ? $this->restPostSnapshot((array) $stageResponse['data'], $taxonomyFields)
            : null;
        [$stageExpected, $stageFields] = $this->expectedRestPostState($payload, $before, $stageStatus, $taxonomyFields, $isCreate);
        $requestedEmptyPostCategories = $has('categories')
            && $this->sortedIntegerIds((array) ($payload['categories'] ?? [])) === []
            && (string) ($stageExpected['post_type'] ?? 'post') === 'post';
        $effectiveDefaultCategories = $this->sortedIntegerIds((array) ($stageState['categories'] ?? []));
        if ($requestedEmptyPostCategories && count($effectiveDefaultCategories) === 1) {
            // WordPress always assigns its configured default category to standard posts.
            $stageExpected['categories'] = $effectiveDefaultCategories;
            $payload['categories'] = $effectiveDefaultCategories;
        }
        $stageMismatches = $this->compareRestPostState($stageExpected, $stageState, $stageFields);
        if (! ($stageResponse['success'] ?? false)) {
            $stageMismatches['readback'] = ['expected' => 'successful context=edit response', 'actual' => (string) ($stageResponse['message'] ?? 'failed')];
        }

        if ($stageMismatches !== []) {
            $rollback = $isCreate
                ? $this->forceRestPostDraft($target, $endpoint, $postId, $taxonomyFields)
                : $this->rollbackRestPost($target, $endpoint, $postId, (array) $before, $taxonomyFields);
            $state = is_array($rollback['state'] ?? null) ? $rollback['state'] : $stageState;

            return [
                'success' => false,
                'message' => 'REST post verification failed during staging: '.implode(', ', array_keys($stageMismatches)).'.',
                'data' => $state ? $this->formatVerifiedRestPostData($state, [
                    'verified' => false,
                    'phase' => 'stage_readback',
                    'mismatches' => $stageMismatches,
                    'rollback' => $rollback,
                ]) : null,
            ];
        }

        if ($requestedStatus !== $stageStatus) {
            $finalizePayload = ['status' => $requestedStatus];
            if ($has('date') && trim((string) ($payload['date'] ?? '')) !== '') {
                $finalizePayload['date'] = (string) $payload['date'];
            }
            $finalizeResponse = $this->restRequest($target, 'post', $endpoint.'/'.$postId, $finalizePayload);
            if (! ($finalizeResponse['success'] ?? false)) {
                $rollback = $isCreate
                    ? $this->forceRestPostDraft($target, $endpoint, $postId, $taxonomyFields)
                    : $this->rollbackRestPost($target, $endpoint, $postId, (array) $before, $taxonomyFields);
                $state = is_array($rollback['state'] ?? null) ? $rollback['state'] : $stageState;

                return [
                    'success' => false,
                    'message' => 'REST final status update failed: '.(string) ($finalizeResponse['message'] ?? 'WordPress rejected the request.'),
                    'data' => $state ? $this->formatVerifiedRestPostData($state, ['verified' => false, 'phase' => 'finalize_status', 'rollback' => $rollback]) : null,
                ];
            }
        }

        $finalResponse = $this->restRequest($target, 'get', $endpoint.'/'.$postId, [], ['context' => 'edit']);
        $finalState = ($finalResponse['success'] ?? false) && is_array($finalResponse['data'] ?? null)
            ? $this->restPostSnapshot((array) $finalResponse['data'], $taxonomyFields)
            : null;
        [$finalExpected, $finalFields] = $this->expectedRestPostState($payload, $before, $requestedStatus, $taxonomyFields, $isCreate);
        $finalMismatches = $this->compareRestPostState($finalExpected, $finalState, $finalFields);
        if (! ($finalResponse['success'] ?? false)) {
            $finalMismatches['readback'] = ['expected' => 'successful context=edit response', 'actual' => (string) ($finalResponse['message'] ?? 'failed')];
        }

        if ($finalMismatches !== []) {
            $rollback = $isCreate
                ? $this->forceRestPostDraft($target, $endpoint, $postId, $taxonomyFields)
                : $this->rollbackRestPost($target, $endpoint, $postId, (array) $before, $taxonomyFields);
            $state = is_array($rollback['state'] ?? null) ? $rollback['state'] : $finalState;

            return [
                'success' => false,
                'message' => 'REST changed one or more fields while finalizing the post: '.implode(', ', array_keys($finalMismatches)).'.',
                'data' => $state ? $this->formatVerifiedRestPostData($state, [
                    'verified' => false,
                    'phase' => 'final_readback',
                    'mismatches' => $finalMismatches,
                    'rollback' => $rollback,
                ]) : null,
            ];
        }

        return [
            'success' => true,
            'message' => $isCreate ? 'Post created and verified via REST.' : 'Post updated and verified via REST.',
            'data' => $this->formatVerifiedRestPostData((array) $finalState, [
                'verified' => true,
                'phase' => 'final_readback',
                'checked_fields' => $finalFields,
                'mismatches' => [],
            ]),
        ];
    }

    private function restPostSnapshot(array $post, array $taxonomyFields): array
    {
        $rawValue = static function ($value): string {
            if (! is_array($value)) {
                return (string) $value;
            }

            return array_key_exists('raw', $value) ? (string) $value['raw'] : (string) ($value['rendered'] ?? '');
        };
        $normalizeIds = static function ($values): array {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $values))));
            sort($ids, SORT_NUMERIC);

            return $ids;
        };
        $taxonomies = [];
        foreach ($taxonomyFields as $taxonomy => $field) {
            $taxonomies[$taxonomy] = array_key_exists($field, $post) ? $normalizeIds($post[$field]) : null;
        }

        return [
            'post_id' => (int) ($post['id'] ?? 0),
            'post_url' => (string) ($post['link'] ?? ''),
            'post_title' => $rawValue($post['title'] ?? ''),
            'post_content' => $rawValue($post['content'] ?? ''),
            'post_excerpt' => $rawValue($post['excerpt'] ?? ''),
            'post_status' => (string) ($post['status'] ?? ''),
            'post_date' => $this->canonicalRestDate((string) ($post['date'] ?? '')),
            'post_name' => (string) ($post['slug'] ?? ''),
            'post_type' => (string) ($post['type'] ?? 'post'),
            'post_author' => (int) ($post['author'] ?? 0),
            'featured_media' => (int) ($post['featured_media'] ?? 0),
            'categories' => $normalizeIds($post['categories'] ?? []),
            'tags' => $normalizeIds($post['tags'] ?? []),
            'taxonomies' => $taxonomies,
            'raw' => $post,
        ];
    }

    private function expectedRestPostState(array $payload, ?array $before, string $status, array $taxonomyFields, bool $isCreate): array
    {
        $provided = (array) ($payload['_provided'] ?? []);
        $has = static fn (string $field): bool => (bool) ($provided[$field] ?? false);
        $expected = $isCreate ? [] : (array) $before;
        $fields = $isCreate
            ? ['post_title', 'post_content', 'post_status', 'post_type']
            : ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_name', 'post_type', 'post_author', 'featured_media', 'categories', 'tags'];
        $expected['post_status'] = $status;
        $expected['post_type'] = trim((string) ($payload['post_type'] ?? ($before['post_type'] ?? 'post'))) ?: 'post';
        $mapping = [
            'title' => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'date' => 'post_date',
            'slug' => 'post_name',
            'author' => 'post_author',
            'featured_media' => 'featured_media',
            'categories' => 'categories',
            'tags' => 'tags',
        ];
        foreach ($mapping as $source => $destination) {
            if (! $has($source)) {
                continue;
            }
            $value = $payload[$source] ?? null;
            $expected[$destination] = match ($source) {
                'date' => $this->canonicalRestDate((string) $value),
                'slug' => trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $value)), '-'),
                'author', 'featured_media' => (int) $value,
                'categories', 'tags' => $this->sortedIntegerIds((array) $value),
                default => (string) $value,
            };
            if (! in_array($destination, $fields, true)) {
                $fields[] = $destination;
            }
        }
        foreach ($taxonomyFields as $taxonomy => $field) {
            $key = 'taxonomy:'.$taxonomy;
            $expected[$key] = $this->sortedIntegerIds((array) ($payload['taxonomies'][$taxonomy] ?? []));
            $fields[] = $key;
        }

        return [$expected, array_values(array_unique($fields))];
    }

    private function compareRestPostState(array $expected, ?array $actual, array $fields): array
    {
        if ($actual === null) {
            return ['post' => ['expected' => 'present', 'actual' => 'missing']];
        }
        $mismatches = [];
        foreach ($fields as $field) {
            if (str_starts_with($field, 'taxonomy:')) {
                $taxonomy = substr($field, strlen('taxonomy:'));
                $actualValue = $actual['taxonomies'][$taxonomy] ?? null;
            } else {
                $actualValue = $actual[$field] ?? null;
            }
            $expectedValue = $expected[$field] ?? null;
            if ($actualValue !== $expectedValue) {
                $mismatches[$field] = [
                    'expected' => $this->compactVerificationValue($expectedValue),
                    'actual' => $this->compactVerificationValue($actualValue),
                ];
            }
        }

        return $mismatches;
    }

    private function rollbackRestPost(array $target, string $endpoint, int $postId, array $before, array $taxonomyFields): array
    {
        $rollbackPayload = [
            'title' => (string) ($before['post_title'] ?? ''),
            'content' => (string) ($before['post_content'] ?? ''),
            'excerpt' => (string) ($before['post_excerpt'] ?? ''),
            'status' => (string) ($before['post_status'] ?? 'draft'),
            'date' => str_replace(' ', 'T', (string) ($before['post_date'] ?? '')),
            'slug' => (string) ($before['post_name'] ?? ''),
            'author' => (int) ($before['post_author'] ?? 0),
            'featured_media' => (int) ($before['featured_media'] ?? 0),
            'categories' => (array) ($before['categories'] ?? []),
            'tags' => (array) ($before['tags'] ?? []),
        ];
        foreach ($taxonomyFields as $taxonomy => $field) {
            if (is_array($before['taxonomies'][$taxonomy] ?? null)) {
                $rollbackPayload[$field] = $before['taxonomies'][$taxonomy];
            }
        }
        $response = $this->restRequest($target, 'post', $endpoint.'/'.$postId, $rollbackPayload);
        $readback = $this->restRequest($target, 'get', $endpoint.'/'.$postId, [], ['context' => 'edit']);
        $state = ($readback['success'] ?? false) && is_array($readback['data'] ?? null)
            ? $this->restPostSnapshot((array) $readback['data'], $taxonomyFields)
            : null;
        $fields = ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_name', 'post_type', 'post_author', 'featured_media', 'categories', 'tags'];
        $expected = $before;
        foreach (array_keys($taxonomyFields) as $taxonomy) {
            $key = 'taxonomy:'.$taxonomy;
            $expected[$key] = $before['taxonomies'][$taxonomy] ?? null;
            $fields[] = $key;
        }
        $mismatches = $this->compareRestPostState($expected, $state, $fields);
        if (! ($response['success'] ?? false) || $mismatches !== []) {
            $forced = $this->forceRestPostDraft($target, $endpoint, $postId, $taxonomyFields);

            return [
                'success' => false,
                'errors' => [! ($response['success'] ?? false) ? (string) ($response['message'] ?? 'REST rollback failed.') : 'REST rollback readback mismatch.'],
                'mismatches' => $mismatches,
                'state' => $forced['state'] ?? $state,
            ];
        }

        return ['success' => true, 'errors' => [], 'mismatches' => [], 'state' => $state];
    }

    private function forceRestPostDraft(array $target, string $endpoint, int $postId, array $taxonomyFields): array
    {
        $response = $this->restRequest($target, 'post', $endpoint.'/'.$postId, ['status' => 'draft']);
        $readback = $this->restRequest($target, 'get', $endpoint.'/'.$postId, [], ['context' => 'edit']);
        $state = ($readback['success'] ?? false) && is_array($readback['data'] ?? null)
            ? $this->restPostSnapshot((array) $readback['data'], $taxonomyFields)
            : null;

        return [
            'success' => ($response['success'] ?? false) && ($state['post_status'] ?? null) === 'draft',
            'errors' => ($response['success'] ?? false) ? [] : [(string) ($response['message'] ?? 'Could not force post to draft.')],
            'mismatches' => ($state['post_status'] ?? null) === 'draft' ? [] : ['post_status' => ['expected' => 'draft', 'actual' => $state['post_status'] ?? null]],
            'state' => $state,
        ];
    }

    private function formatVerifiedRestPostData(array $state, array $verification): array
    {
        return [
            'post_id' => (int) ($state['post_id'] ?? 0),
            'post_url' => (string) ($state['post_url'] ?? ''),
            'post_status' => (string) ($state['post_status'] ?? ''),
            'post_title' => (string) ($state['post_title'] ?? ''),
            'post_excerpt' => (string) ($state['post_excerpt'] ?? ''),
            'post_date' => (string) ($state['post_date'] ?? ''),
            'post_slug' => (string) ($state['post_name'] ?? ''),
            'post_type' => (string) ($state['post_type'] ?? ''),
            'author_id' => (int) ($state['post_author'] ?? 0),
            'featured_media' => (int) ($state['featured_media'] ?? 0),
            'categories' => (array) ($state['categories'] ?? []),
            'tags' => (array) ($state['tags'] ?? []),
            'taxonomies' => (array) ($state['taxonomies'] ?? []),
            'post_content_bytes' => strlen((string) ($state['post_content'] ?? '')),
            'post_content_sha256' => hash('sha256', (string) ($state['post_content'] ?? '')),
            'verification' => $verification,
            'raw' => (array) ($state['raw'] ?? []),
        ];
    }

    private function canonicalRestDate(string $date): string
    {
        $date = trim($date);

        return $date === '' ? '' : str_replace('T', ' ', substr($date, 0, 19));
    }

    private function sortedIntegerIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function compactVerificationValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 160) {
            return ['bytes' => strlen($value), 'sha256' => hash('sha256', $value), 'sample' => substr($value, 0, 120)];
        }

        return $value;
    }
}
