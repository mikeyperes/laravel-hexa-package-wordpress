<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait HandlesWordPressRestAndToolkit
{
    private function restRequest(array $target, string $method, string $endpoint, array $body = [], array $query = []): array
    {
        $target = $this->normalizeTarget($target);
        if ($target["url"] === "" || $target["username"] === "" || $target["application_password"] === "") {
            return ["success" => false, "message" => "REST credentials are incomplete.", "data" => null, "status" => null];
        }

        $url = $target["url"] . "/wp-json/wp/v2/" . ltrim($endpoint, "/");

        try {
            $request = Http::withBasicAuth($target["username"], $target["application_password"])->timeout(60);
            $response = match (strtolower($method)) {
                "get" => $request->get($url, $query),
                "delete" => $request->delete($url, $body ?: $query),
                default => $request->post($url, $body),
            };

            if ($response->successful()) {
                return ["success" => true, "message" => "REST request succeeded.", "data" => $response->json(), "status" => $response->status()];
            }

            $payload = $response->json();
            return [
                "success" => false,
                "message" => is_array($payload) && !empty($payload["message"]) ? (string) $payload["message"] : ("HTTP " . $response->status()),
                "data" => is_array($payload) ? $payload : null,
                "status" => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning("WordPressManagerService::restRequest failed", [
                "endpoint" => $endpoint,
                "method" => $method,
                "error" => $e->getMessage(),
            ]);

            return ["success" => false, "message" => $e->getMessage(), "data" => null, "status" => null];
        }
    }

    private function fetchPublicationTermsViaDb(WhmServer $server, int $installId): array
    {
        if ($installId <= 0) {
            return [];
        }

        $parts = [
            'global $wpdb;',
            '$tax = "publication";',
            '$rows = $wpdb->get_results($wpdb->prepare("SELECT t.term_id, t.name, t.slug, tt.parent, tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.parent ASC, t.name ASC LIMIT 500", $tax));',
            '$payload = array_map(static function ($t) { return ["id" => (int) $t->term_id, "term_id" => (int) $t->term_id, "parent" => (int) $t->parent, "count" => (int) $t->count, "name" => (string) $t->name, "slug" => (string) $t->slug]; }, is_array($rows) ? $rows : []);',
            'echo "HEXA_PUBLICATION_TERMS:" . wp_json_encode($payload);',
        ];
        $php = implode("", $parts);
        $result = $this->wptoolkit->wpCliEval($server, $installId, $php);
        if (!($result["success"] ?? false)) {
            return [];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_PUBLICATION_TERMS:");
        return is_array($payload) ? array_values(array_map([$this, "normalizeTermRow"], array_filter($payload, "is_array"))) : [];
    }

    private function normalizeTermRow(array $term): array
    {
        return [
            "id" => (int) ($term["id"] ?? $term["term_id"] ?? 0),
            "term_id" => (int) ($term["term_id"] ?? $term["id"] ?? 0),
            "parent" => (int) ($term["parent"] ?? 0),
            "count" => (int) ($term["count"] ?? 0),
            "name" => (string) ($term["name"] ?? ""),
            "slug" => (string) ($term["slug"] ?? ""),
        ];
    }

    private function formatRestPostData(array $post): array
    {
        $excerpt = $post["excerpt"] ?? "";
        if (is_array($excerpt)) {
            $excerpt = $excerpt["raw"] ?? $excerpt["rendered"] ?? "";
        }
        $content = $post["content"] ?? "";
        if (is_array($content)) {
            $content = $content["raw"] ?? $content["rendered"] ?? "";
        }
        $title = $post["title"] ?? "";
        if (is_array($title)) {
            $title = $title["raw"] ?? $title["rendered"] ?? "";
        }

        return [
            "post_id" => (int) ($post["id"] ?? 0),
            "post_url" => (string) ($post["link"] ?? ""),
            "post_status" => (string) ($post["status"] ?? ""),
            "post_title" => (string) $title,
            "post_content" => (string) $content,
            "post_excerpt" => (string) $excerpt,
            "post_date" => isset($post["date"]) ? (string) $post["date"] : null,
            "post_slug" => (string) ($post["slug"] ?? ""),
            "post_type" => (string) ($post["type"] ?? "post"),
            "author_id" => (int) ($post["author"] ?? 0),
            "featured_media" => (int) ($post["featured_media"] ?? 0),
            "categories" => array_values(array_map("intval", (array) ($post["categories"] ?? []))),
            "tags" => array_values(array_map("intval", (array) ($post["tags"] ?? []))),
            "post_content_bytes" => strlen((string) $content),
            "post_content_sha256" => hash("sha256", (string) $content),
            "raw" => $post,
        ];
    }

    private function restTaxonomyEndpoint(string $taxonomy): string
    {
        return match ($taxonomy) {
            "category" => "categories",
            "post_tag" => "tags",
            default => trim($taxonomy, "/"),
        };
    }

    private function restTaxonomyField(string $taxonomy): string
    {
        return match ($taxonomy) {
            "category" => "categories",
            "post_tag" => "tags",
            default => $taxonomy,
        };
    }

    private function looksLikeIntegerList(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                return false;
            }
        }

        return $value !== [];
    }

    private function decodeMarkedPayload(string $stdout, string $marker): array|null
    {
        return (new \hexa_package_wordpress\Services\WordPressEvalPayloadDecoder())->decode($stdout, $marker);
    }

    private function legacyCreateToolkitPost(array $target, array $payload): array
    {
        $php = <<<'PHP'
$payload = __PAYLOAD__ ;
$requestedExcerpt = array_key_exists("excerpt", $payload) && $payload["excerpt"] !== null
    ? (string) $payload["excerpt"]
    : null;
$requestedStatus = (string) (($payload["status"] ?? "draft") ?: "draft");
$post = [
    "post_title" => (string) ($payload["title"] ?? ""),
    "post_content" => (string) ($payload["content"] ?? ""),
    "post_status" => $requestedExcerpt !== null ? "draft" : $requestedStatus,
    "post_type" => (string) (($payload["post_type"] ?? "post") ?: "post"),
];
if ($requestedExcerpt !== null) {
    $post["post_excerpt"] = $requestedExcerpt;
}
if (!empty($payload["date"])) {
    $post["post_date"] = (string) $payload["date"];
}
if (!empty($payload["slug"])) {
    $post["post_name"] = sanitize_title((string) $payload["slug"]);
}
$author = $payload["author"] ?? null;
if ($author !== null && $author !== "") {
    if (is_numeric($author)) {
        $post["post_author"] = (int) $author;
    } else {
        $user = get_user_by("login", (string) $author);
        if ($user) {
            $post["post_author"] = (int) $user->ID;
        }
    }
}
$postId = wp_insert_post($post, true);
if (is_wp_error($postId)) {
    echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode(["success" => false, "message" => $postId->get_error_message()]);
    return;
}
$createdPost = get_post($postId);
$storedExcerpt = $createdPost ? (string) $createdPost->post_excerpt : "";
if (!$createdPost || ($requestedExcerpt !== null && $storedExcerpt !== $requestedExcerpt)) {
    if ($createdPost && (string) $createdPost->post_status !== "draft") {
        wp_update_post(["ID" => $postId, "post_status" => "draft"]);
    }
    clean_post_cache($postId);
    $failedPost = get_post($postId);
    echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode([
        "success" => false,
        "message" => "WordPress did not persist the requested excerpt. The post was left as a draft.",
        "data" => [
            "post_id" => (int) $postId,
            "post_url" => (string) (get_permalink($postId) ?: ""),
            "post_status" => $failedPost ? (string) $failedPost->post_status : "",
            "post_title" => $failedPost ? (string) $failedPost->post_title : "",
            "post_excerpt" => $failedPost ? (string) $failedPost->post_excerpt : "",
            "post_date" => $failedPost ? (string) $failedPost->post_date : "",
        ],
    ]);
    return;
}
if (!empty($payload["categories"])) {
    wp_set_post_terms($postId, array_values(array_filter(array_map("intval", (array) $payload["categories"]))), "category", false);
}
if (!empty($payload["tags"])) {
    wp_set_post_terms($postId, array_values(array_filter(array_map("intval", (array) $payload["tags"]))), "post_tag", false);
}
foreach ((array) ($payload["taxonomies"] ?? []) as $taxonomy => $termIds) {
    $taxonomy = (string) $taxonomy;
    if (!taxonomy_exists($taxonomy)) {
        continue;
    }
    $cleanIds = array_values(array_filter(array_map("intval", (array) $termIds)));
    if ($cleanIds !== []) {
        wp_set_post_terms($postId, $cleanIds, $taxonomy, false);
    }
}
if (!empty($payload["featured_media"])) {
    update_post_meta($postId, "_thumbnail_id", (int) $payload["featured_media"]);
}
if ($requestedExcerpt !== null && $requestedStatus !== "draft") {
    $statusUpdate = ["ID" => $postId, "post_status" => $requestedStatus];
    if (!empty($payload["date"])) {
        $statusUpdate["post_date"] = (string) $payload["date"];
    }
    $statusResult = wp_update_post($statusUpdate, true);
    if (is_wp_error($statusResult)) {
        wp_update_post(["ID" => $postId, "post_status" => "draft"]);
        clean_post_cache($postId);
        $failedPost = get_post($postId);
        echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode([
            "success" => false,
            "message" => "The excerpt was saved, but WordPress rejected the requested post status: " . $statusResult->get_error_message(),
            "data" => [
                "post_id" => (int) $postId,
                "post_url" => (string) (get_permalink($postId) ?: ""),
                "post_status" => $failedPost ? (string) $failedPost->post_status : "",
                "post_title" => $failedPost ? (string) $failedPost->post_title : "",
                "post_excerpt" => $failedPost ? (string) $failedPost->post_excerpt : "",
                "post_date" => $failedPost ? (string) $failedPost->post_date : "",
            ],
        ]);
        return;
    }
}
$finalPost = get_post($postId);
$finalExcerpt = $finalPost ? (string) $finalPost->post_excerpt : "";
$finalStatus = $finalPost ? (string) $finalPost->post_status : "";
$excerptMismatch = $requestedExcerpt !== null && $finalExcerpt !== $requestedExcerpt;
$statusMismatch = $finalPost && $finalStatus !== $requestedStatus;
if (!$finalPost || $excerptMismatch || $statusMismatch) {
    if ($finalPost) {
        wp_update_post(["ID" => $postId, "post_status" => "draft"]);
    }
    clean_post_cache($postId);
    $failedPost = get_post($postId);
    echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode([
        "success" => false,
        "message" => $statusMismatch
            ? "WordPress did not retain the requested post status. The post was reverted to draft."
            : "WordPress changed the requested excerpt while finalizing the post. The post was reverted to draft.",
        "data" => [
            "post_id" => (int) $postId,
            "post_url" => (string) (get_permalink($postId) ?: ""),
            "post_status" => $failedPost ? (string) $failedPost->post_status : "",
            "post_title" => $failedPost ? (string) $failedPost->post_title : "",
            "post_excerpt" => $failedPost ? (string) $failedPost->post_excerpt : "",
            "post_date" => $failedPost ? (string) $failedPost->post_date : "",
        ],
    ]);
    return;
}
echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode([
    "success" => true,
    "data" => [
        "post_id" => (int) $postId,
        "post_url" => (string) (get_permalink($postId) ?: ""),
        "post_status" => (string) $finalPost->post_status,
        "post_title" => (string) $finalPost->post_title,
        "post_excerpt" => (string) $finalPost->post_excerpt,
        "post_date" => (string) $finalPost->post_date,
    ],
]);
PHP;
        $php = str_replace("__PAYLOAD__", var_export($payload, true), $php);
        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WP Toolkit post create failed."), "data" => null];
        }
        $parsed = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_TOOLKIT_CREATE:");
        if (!is_array($parsed)) {
            return ["success" => false, "message" => "Failed to parse WP Toolkit post create output.", "data" => null];
        }
        if (!($parsed["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($parsed["message"] ?? "WP Toolkit post create failed."),
                "data" => is_array($parsed["data"] ?? null) ? $parsed["data"] : null,
            ];
        }
        return ["success" => true, "message" => "Post created via WP Toolkit.", "data" => is_array($parsed["data"] ?? null) ? $parsed["data"] : null];
    }

    private function isLocalWhmServerTarget(array $target): bool
    {
        $target = $this->normalizeTarget($target);
        return $this->usesWpToolkit($target)
            && $target["server"] instanceof WhmServer
            && $this->wptoolkit->isSameHostServer($target["server"]);
    }

    private function normalizeUserRow(array $user): array
    {
        $simpleAvatarPayload = $user["simple_local_avatar"] ?? "";
        $avatarPayload = $simpleAvatarPayload ?: ($user["wp_user_avatars"] ?? "");
        $resolvedAvatar = $this->resolveUserAvatarPayload(
            $avatarPayload !== "" ? $avatarPayload : ($user["avatar_urls"] ?? []),
            224,
        );
        $avatarUrl = (string) (
            ($user["avatar_thumbnail_url"] ?? null)
            ?: ($resolvedAvatar["thumbnail_url"] ?? null)
            ?: ($user["avatar_url"] ?? "")
        );
        $avatarFullUrl = (string) (
            ($user["avatar_full_url"] ?? null)
            ?: ($resolvedAvatar["full_url"] ?? null)
            ?: ($user["avatar_url"] ?? null)
            ?: $avatarUrl
        );
        $avatarMediaId = (string) (
            $user["avatar_media_id"]
            ?? $resolvedAvatar["media_id"]
            ?: ($user["wp_user_avatar"] ?? "")
        );
        $postCountKnown = array_key_exists("post_count", $user)
            && is_numeric($user["post_count"])
            && (!array_key_exists("post_count_known", $user) || (bool) $user["post_count_known"]);
        $postCount = $postCountKnown ? max(0, (int) $user["post_count"]) : null;
        $contentCountKnown = array_key_exists("content_count", $user)
            && is_numeric($user["content_count"])
            && (!array_key_exists("content_count_known", $user) || (bool) $user["content_count_known"]);
        $contentCount = $contentCountKnown ? max(0, (int) $user["content_count"]) : null;

        return [
            "id" => (int) ($user["id"] ?? $user["ID"] ?? 0),
            "ID" => (int) ($user["ID"] ?? $user["id"] ?? 0),
            "user_login" => (string) ($user["user_login"] ?? $user["slug"] ?? ""),
            "user_nicename" => (string) ($user["user_nicename"] ?? $user["slug"] ?? $user["user_login"] ?? ""),
            "display_name" => (string) ($user["display_name"] ?? $user["name"] ?? ""),
            "user_email" => (string) ($user["user_email"] ?? $user["email"] ?? ""),
            "user_url" => (string) ($user["user_url"] ?? $user["url"] ?? ""),
            "roles" => array_values(array_map("strval", (array) ($user["roles"] ?? []))),
            "wp_user_avatar" => (string) ($user["wp_user_avatar"] ?? ""),
            "wp_user_avatars" => is_scalar($avatarPayload) ? (string) $avatarPayload : serialize($avatarPayload),
            "simple_local_avatar" => is_scalar($simpleAvatarPayload) ? (string) $simpleAvatarPayload : serialize($simpleAvatarPayload),
            "avatar_media_id" => $avatarMediaId,
            "avatar_url" => $avatarUrl,
            "avatar_thumbnail_url" => $avatarUrl,
            "avatar_full_url" => $avatarFullUrl,
            "avatar_sizes" => (array) ($resolvedAvatar["sizes"] ?? []),
            "author_url" => (string) ($user["author_url"] ?? $user["link"] ?? ""),
            "wp_admin_url" => (string) ($user["wp_admin_url"] ?? ""),
            "post_count" => $postCount,
            "post_count_known" => $postCountKnown,
            "content_count" => $contentCount,
            "content_count_known" => $contentCountKnown,
        ];
    }

    private function restPostEndpoint(string $postType, ?string $customEndpoint = null): string
    {
        $customEndpoint = trim((string) $customEndpoint);
        if ($customEndpoint !== "") {
            return trim($customEndpoint, "/");
        }

        $postType = trim((string) $postType, "/");
        if ($postType === "" || $postType === "post" || $postType === "posts") {
            return "posts";
        }

        return $postType;
    }

    private function uploadToolkitLocalFile(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $target = $this->normalizeTarget($target);
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ["success" => false, "message" => "Local media file does not exist or is not readable."];
        }

        return $this->wptoolkit->wpCliImportLocalMediaFile(
            $target["server"],
            (int) $target["install_id"],
            $filePath,
            $fileName,
            $altText,
            $caption,
            $description,
        );
    }

    private function wpCliAttachmentUrl(array $target, int $mediaId): string
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target) || $mediaId <= 0) {
            return "";
        }

        $code = "echo wp_get_attachment_url(" . $mediaId . ");";
        $eval = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "eval " . escapeshellarg($code), 120);
        $url = trim((string) ($eval["stdout"] ?? ""));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $guid = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "post get " . $mediaId . " --field=guid", 120);
        $url = trim((string) ($guid["stdout"] ?? ""));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : "";
    }

}
