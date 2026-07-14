<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressMedia
{
    public function uploadMedia(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            $normalizedPath = trim($filePath);
            if ($normalizedPath !== "" && !filter_var($normalizedPath, FILTER_VALIDATE_URL) && is_file($normalizedPath)) {
                return $this->uploadToolkitLocalFile($target, $normalizedPath, $fileName, $altText, $caption, $description);
            }

            return $this->wptoolkit->wpCliUploadMedia($target["server"], (int) $target["install_id"], $filePath, $fileName, $altText, $caption, $description);
        }

        return $this->rest->uploadMedia($target["url"], $target["username"], $target["application_password"], $filePath, $fileName, $altText);
    }

    public function updateMedia(array $target, int $mediaId, array $attributes): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            $parts = [
                '$mediaId=' . (int) $mediaId . ';',
                '$updates=' . var_export($attributes, true) . ';',
                '$post=["ID"=>$mediaId];',
                'foreach (["title"=>"post_title","caption"=>"post_excerpt","description"=>"post_content"] as $src=>$dest){ if (array_key_exists($src,$updates) && $updates[$src]!==null && $updates[$src]!=="") { $post[$dest]=(string) $updates[$src]; }}',
                'if (count($post) > 1) { $res = wp_update_post($post, true); if (is_wp_error($res)) { echo "HEXA_MEDIA_UPDATE:" . wp_json_encode(["success"=>false,"message"=>$res->get_error_message()]); return; } }',
                'if (isset($updates["alt_text"])) { update_post_meta($mediaId, "_wp_attachment_image_alt", (string) $updates["alt_text"]); }',
                'if (!empty($updates["meta"]) && is_array($updates["meta"])) { foreach ($updates["meta"] as $metaKey=>$metaValue) { update_post_meta($mediaId, (string) $metaKey, $metaValue); } }',
                'echo "HEXA_MEDIA_UPDATE:" . wp_json_encode(["success"=>true,"message"=>"Media updated."]);',
            ];
            $php = implode("", $parts);
            $result = $this->evaluatePhp($target, $php);
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($result["message"] ?? "Media update failed.")];
            }
            $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_UPDATE:");
            return is_array($payload) ? $payload : ["success" => false, "message" => "Failed to parse media update output."];
        }

        $response = $this->restRequest($target, "post", "media/" . $mediaId, $attributes);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Media updated via REST." : (string) ($response["message"] ?? "Media update failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }


    public function renameMediaFile(array $target, int $mediaId, string $fileName): array
    {
        $target = $this->normalizeTarget($target);
        $fileName = trim(basename($fileName));
        if ($mediaId <= 0 || $fileName === "") {
            return ["success" => false, "message" => "A media ID and file name are required."];
        }
        $safeName = trim((string) preg_replace("/[^a-z0-9._-]+/i", "-", $fileName), "-._");
        if ($safeName === "") {
            return ["success" => false, "message" => "The requested file name is not valid."];
        }

        $parts = [
            "\$mediaId=" . (int) $mediaId . ";",
            "\$requested=" . var_export($safeName, true) . ";",
            "\$post=get_post(\$mediaId); if (!\$post || \$post->post_type !== \"attachment\") { echo \"HEXA_MEDIA_RENAME:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"Attachment not found.\"]); return; }",
            "\$old=get_attached_file(\$mediaId); if (!\$old || !file_exists(\$old)) { echo \"HEXA_MEDIA_RENAME:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"Attached file was not found on disk.\"]); return; }",
            "\$oldExt=pathinfo(\$old, PATHINFO_EXTENSION); \$reqExt=pathinfo(\$requested, PATHINFO_EXTENSION); if (\$reqExt === \"\" && \$oldExt !== \"\") { \$requested .= \".\" . \$oldExt; }",
            "\$requested=sanitize_file_name(\$requested); \$new=dirname(\$old) . DIRECTORY_SEPARATOR . \$requested; if (\$new !== \$old) { if (file_exists(\$new)) { echo \"HEXA_MEDIA_RENAME:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"A media file with that file name already exists.\"]); return; } if (!@rename(\$old, \$new)) { echo \"HEXA_MEDIA_RENAME:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"File rename failed.\"]); return; } update_attached_file(\$mediaId, \$new); }",
            "\$uploads=wp_upload_dir(); \$relative=(string) get_post_meta(\$mediaId, \"_wp_attached_file\", true); \$url=trailingslashit(\$uploads[\"baseurl\"] ?? \"\") . ltrim(\$relative, \"/\"); wp_update_post([\"ID\"=>\$mediaId,\"post_name\"=>sanitize_title(pathinfo(\$requested, PATHINFO_FILENAME)),\"guid\"=>esc_url_raw(\$url)]); clean_post_cache(\$mediaId);",
            "echo \"HEXA_MEDIA_RENAME:\" . wp_json_encode([\"success\"=>true,\"message\"=>\"Media file renamed.\",\"media_id\"=>\$mediaId,\"file_name\"=>\$requested,\"url\"=>wp_get_attachment_url(\$mediaId)]);",
        ];
        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Media rename failed.")];
        }
        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_RENAME:");
        return is_array($payload) ? $payload : ["success" => false, "message" => "Failed to parse media rename output."];
    }

    public function deletePost(array $target, int $postId, bool $force = true, string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            if (!$force) {
                return $this->wpCliTrashPostViaPhp($target, $postId);
            }
            return $this->wptoolkit->wpCliDeletePost($target["server"], (int) $target["install_id"], $postId, $force);
        }

        $response = $this->restRequest($target, "delete", trim($postType, "/") . "/" . $postId, ["force" => $force]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Post deleted via REST." : (string) ($response["message"] ?? "Post delete failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

    protected function wpCliTrashPostViaPhp(array $target, int $postId): array
    {
        $parts = [
            "\$postId=" . var_export($postId, true) . ";",
            "\$post=get_post(\$postId);",
            "if (!\$post) { echo \"HEXA_TRASH_POST:\" . wp_json_encode([\"success\"=>false,\"message\"=>\"Post not found.\",\"post_id\"=>\$postId]); return; }",
            "\$oldStatus=(string) \$post->post_status;",
            "\$trashed=wp_trash_post(\$postId);",
            "if (!\$trashed && get_post(\$postId)) { \$updated=wp_update_post([\"ID\"=>\$postId,\"post_status\"=>\"trash\"], true); if (is_wp_error(\$updated)) { echo \"HEXA_TRASH_POST:\" . wp_json_encode([\"success\"=>false,\"message\"=>\$updated->get_error_message(),\"post_id\"=>\$postId,\"old_status\"=>\$oldStatus]); return; } }",
            "clean_post_cache(\$postId);",
            "\$newPost=get_post(\$postId);",
            "\$newStatus=\$newPost ? (string) \$newPost->post_status : \"\";",
            "\$success=(bool) (\$newPost && \$newStatus === \"trash\");",
            "echo \"HEXA_TRASH_POST:\" . wp_json_encode([\"success\"=>\$success,\"message\"=>\$success ? \"Post moved to Trash.\" : \"Post could not be moved to Trash.\",\"post_id\"=>\$postId,\"old_status\"=>\$oldStatus,\"new_status\"=>\$newStatus,\"url\"=>\$newPost ? get_permalink(\$newPost) : \"\"]);",
        ];

        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Post trash failed.")];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_TRASH_POST:");
        return is_array($payload) ? $payload : ["success" => false, "message" => "Failed to parse post trash output."];
    }

    public function deleteMedia(array $target, int $mediaId, bool $force = true): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->wpCliDeleteMedia($target["server"], (int) $target["install_id"], $mediaId, $force);
        }

        $response = $this->restRequest($target, "delete", "media/" . $mediaId, ["force" => $force]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Media deleted via REST." : (string) ($response["message"] ?? "Media delete failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

    public function setPostTerms(array $target, int $postId, string $taxonomy, array $termIds): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";
        $termIds = array_values(array_unique(array_filter(array_map("intval", $termIds))));

        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->wpCliSetPostTerms($target["server"], (int) $target["install_id"], $postId, $taxonomy, $termIds);
        }

        $response = $this->restRequest($target, "post", "posts/" . $postId, [$this->restTaxonomyField($taxonomy) => $termIds]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Post terms updated via REST." : (string) ($response["message"] ?? "Post term update failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

    public function evaluatePhp(array $target, string $php): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "PHP evaluation is only available on WP Toolkit targets.", "stdout" => ""];
        }

        if (method_exists($this->wptoolkit, "wpCliEvalWithPlugins")) {
            $pluginResult = $this->wptoolkit->wpCliEvalWithPlugins($target["server"], (int) $target["install_id"], $php, 120);
            if ($pluginResult["success"] ?? false) {
                return $pluginResult;
            }
        }

        return $this->wptoolkit->wpCliEval($target["server"], (int) $target["install_id"], $php);
    }
    private function ensureToolkitTerms(array $target, array $names, string $taxonomy): array
    {
        $parts = [
            '$taxonomy=' . var_export($taxonomy, true) . ';',
            '$names=' . var_export(array_values($names), true) . ';',
            'if (!taxonomy_exists($taxonomy)) { echo "HEXA_BATCH_TERMS:" . wp_json_encode(["success"=>false,"message"=>"Taxonomy not found: " . $taxonomy,"term_ids"=>[],"term_details"=>[]]); return; }',
            '$termIds=[]; $details=[];',
            'foreach ($names as $name) {',
            '  $clean=trim((string) $name);',
            '  if ($clean === "") { continue; }',
            '  $exists = term_exists($clean, $taxonomy);',
            '  if (is_array($exists) && !empty($exists["term_id"])) {',
            '    $termId=(int) $exists["term_id"]; $termIds[]=$termId; $details[]=["name"=>$clean,"id"=>$termId,"existed"=>true,"error"=>null]; continue;',
            '  }',
            '  $inserted = wp_insert_term($clean, $taxonomy);',
            '  if (is_wp_error($inserted)) { $details[]=["name"=>$clean,"id"=>0,"existed"=>false,"error"=>$inserted->get_error_message()]; continue; }',
            '  $termId=(int) ($inserted["term_id"] ?? 0); if ($termId > 0) { $termIds[]=$termId; } $details[]=["name"=>$clean,"id"=>$termId,"existed"=>false,"error"=>null];',
            '}',
            'echo "HEXA_BATCH_TERMS:" . wp_json_encode(["success"=>count($termIds)>0,"message"=>count($termIds)."/".count($names)." term(s) resolved.","term_ids"=>array_values(array_unique(array_filter(array_map("intval", $termIds)))),"term_details"=>$details]);',
        ];
        $php = implode("", $parts);

        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Failed to resolve taxonomy terms."), "term_ids" => [], "term_details" => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_BATCH_TERMS:");
        return is_array($payload)
            ? $payload
            : ["success" => false, "message" => "Failed to parse taxonomy batch output.", "term_ids" => [], "term_details" => []];
    }

    private function normalizePostPayload(array $payload): array
    {
        $standardKeys = ["title", "content", "status", "excerpt", "date", "featured_media", "featured_media_id", "author", "categories", "category_ids", "tags", "tag_ids", "taxonomies", "post_type", "slug", "post_name"];
        $taxonomies = (array) ($payload["taxonomies"] ?? []);

        foreach ($payload as $key => $value) {
            if (in_array($key, $standardKeys, true)) {
                continue;
            }
            if (is_array($value) && $value !== [] && $this->looksLikeIntegerList($value)) {
                $taxonomies[(string) $key] = array_values(array_unique(array_filter(array_map("intval", $value))));
            }
        }

        return [
            "title" => array_key_exists("title", $payload) ? (string) ($payload["title"] ?? "") : null,
            "content" => array_key_exists("content", $payload) ? (string) ($payload["content"] ?? "") : null,
            "status" => array_key_exists("status", $payload) ? (string) ($payload["status"] ?? "draft") : null,
            "post_type" => trim((string) ($payload["post_type"] ?? "post")) ?: "post",
            "slug" => array_key_exists("slug", $payload) ? (string) ($payload["slug"] ?? "") : (array_key_exists("post_name", $payload) ? (string) ($payload["post_name"] ?? "") : null),
            "excerpt" => array_key_exists("excerpt", $payload) ? (string) ($payload["excerpt"] ?? "") : null,
            "date" => array_key_exists("date", $payload) ? ($payload["date"] !== null ? (string) $payload["date"] : null) : null,
            "featured_media" => array_key_exists("featured_media", $payload) ? (int) $payload["featured_media"] : (array_key_exists("featured_media_id", $payload) ? (int) $payload["featured_media_id"] : null),
            "author" => isset($payload["author"]) ? (string) $payload["author"] : null,
            "categories" => array_values(array_unique(array_filter(array_map("intval", (array) ($payload["categories"] ?? $payload["category_ids"] ?? []))))),
            "tags" => array_values(array_unique(array_filter(array_map("intval", (array) ($payload["tags"] ?? $payload["tag_ids"] ?? []))))),
            "taxonomies" => $taxonomies,
        ];
    }

    private function buildToolkitPostData(array $payload): array
    {
        $data = [];
        foreach (["title", "content", "status", "excerpt", "date", "author"] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== "") {
                $data[$field] = $payload[$field];
            }
        }
        if (!empty($payload["categories"])) {
            $data["categories"] = $payload["categories"];
        }
        if (!empty($payload["tags"])) {
            $data["tags"] = $payload["tags"];
        }
        if (isset($payload["slug"]) && $payload["slug"] !== null && $payload["slug"] !== "") {
            $data["slug"] = trim((string) preg_replace("/[^a-z0-9]+/i", "-", strtolower((string) $payload["slug"])), "-");
        }
        if (array_key_exists("featured_media", $payload) && $payload["featured_media"] !== null) {
            $data["featured_media"] = (int) $payload["featured_media"];
        }
        return $data;
    }

    private function buildRestPostPayload(array $payload): array
    {
        $data = [];
        foreach (["title", "content", "status", "excerpt", "date"] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== "") {
                $data[$field] = $payload[$field];
            }
        }
        if (isset($payload["slug"]) && $payload["slug"] !== null && $payload["slug"] !== "") {
            $data["slug"] = trim((string) preg_replace("/[^a-z0-9]+/i", "-", strtolower((string) $payload["slug"])), "-");
        }
        if (array_key_exists("featured_media", $payload) && $payload["featured_media"] !== null) {
            $data["featured_media"] = (int) $payload["featured_media"];
        }
        if (!empty($payload["author"]) && is_numeric($payload["author"])) {
            $data["author"] = (int) $payload["author"];
        }
        if (!empty($payload["categories"])) {
            $data["categories"] = $payload["categories"];
        }
        if (!empty($payload["tags"])) {
            $data["tags"] = $payload["tags"];
        }
        foreach ((array) ($payload["taxonomies"] ?? []) as $taxonomy => $termIds) {
            $data[$this->restTaxonomyField((string) $taxonomy)] = array_values(array_unique(array_filter(array_map("intval", (array) $termIds))));
        }
        return $data;
    }

    private function discoverSiteIconFallback(string $siteUrl): array
    {
        $siteUrl = rtrim(trim($siteUrl), "/");
        if ($siteUrl === "") {
            return ["url" => "", "source" => "none"];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withHeaders(["User-Agent" => "Hexa WordPress Manager"])
                ->get($siteUrl . "/");
            if ($response->successful()) {
                $html = (string) $response->body();
                if (preg_match_all('/<link\s+[^>]*>/i', $html, $matches)) {
                    foreach ($matches[0] as $tag) {
                        $rel = strtolower($this->htmlAttribute((string) $tag, "rel"));
                        $href = $this->htmlAttribute((string) $tag, "href");
                        if ($href !== "" && (str_contains($rel, "icon") || str_contains($rel, "apple-touch-icon"))) {
                            return ["url" => $this->absoluteUrl($href, $siteUrl), "source" => "html_icon_link"];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug("WordPressManagerService::discoverSiteIconFallback html lookup failed", ["url" => $siteUrl, "error" => $e->getMessage()]);
        }

        $rootIcon = $siteUrl . "/favicon.ico";
        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders(["User-Agent" => "Hexa WordPress Manager", "Range" => "bytes=0-256"])
                ->get($rootIcon);
            $contentType = strtolower((string) $response->header("content-type"));
            if ($response->successful() && (str_contains($contentType, "image") || strlen((string) $response->body()) > 0)) {
                return ["url" => $rootIcon, "source" => "root_favicon_ico"];
            }
        } catch (\Throwable $e) {
            Log::debug("WordPressManagerService::discoverSiteIconFallback root lookup failed", ["url" => $rootIcon, "error" => $e->getMessage()]);
        }

        return ["url" => "", "source" => "none"];
    }

    private function htmlAttribute(string $tag, string $attribute): string
    {
        $attribute = preg_quote($attribute, "/");
        if (!preg_match('/\s' . $attribute . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match)) {
            return "";
        }
        return html_entity_decode((string) ($match[2] ?: ($match[3] ?: ($match[4] ?? ""))), ENT_QUOTES);
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === "") {
            return "";
        }
        if (str_starts_with($url, "//")) {
            return "https:" . $url;
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        return rtrim($base, "/") . "/" . ltrim($url, "/");
    }

    private function hexToRgb(string $hex, array $fallback): array
    {
        $hex = ltrim(trim($hex), "#");
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function firstExistingPath(array $paths): string
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }
        return "";
    }

}
