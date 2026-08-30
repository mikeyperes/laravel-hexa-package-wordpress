<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressPosts
{
    public function createPost(array $target, string $title, string $content, string $status = "draft", array $options = []): array
    {
        $target = $this->normalizeTarget($target);
        $payload = $this->normalizePostPayload(array_merge($options, [
            "title" => $title,
            "content" => $content,
            "status" => $status,
        ]));
        $postType = trim((string) ($payload["post_type"] ?? "post")) ?: "post";

        if ($this->usesWpToolkit($target)) {
            if (($payload["author"] ?? null) === null && $target["default_author"] !== "") {
                $payload["author"] = $target["default_author"];
            }

            return $this->createToolkitPost($target, $payload);
        }

        $endpoint = $postType === "post" ? "posts" : trim($postType, "/");
        return $this->createRestPost($target, $endpoint, $payload);
    }

    public function updatePost(array $target, int $postId, array $postData): array
    {
        $target = $this->normalizeTarget($target);
        $payload = $this->normalizePostPayload($postData);

        if ($this->usesWpToolkit($target)) {
            return $this->updateToolkitPost($target, $postId, $payload);
        }

        $postType = ($payload["_provided"]["post_type"] ?? false) ? (string) $payload["post_type"] : "post";
        $endpoint = $postType === "post" ? "posts" : trim($postType, "/");
        return $this->updateRestPost($target, $endpoint, $postId, $payload);
    }

    public function getPost(array $target, int $postId, string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->getToolkitPostSnapshot($target, $postId);
        }

        $response = $this->restRequest($target, "get", trim($postType, "/") . "/" . $postId, [], ["context" => "edit"]);
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "REST fetch failed."), "data" => null];
        }

        return ["success" => true, "message" => "Post fetched via REST.", "data" => $this->formatRestPostData((array) $response["data"])];
    }

    /**
     * Load a complete, read-only post snapshot for reusable administrative previews.
     * Raw metadata stays internal so registered extensions can derive provider data.
     *
     * @param array<string, mixed> $target
     * @return array{success: bool, message: string, post: array<string, mixed>|null}
     */
    public function getPostSnapshot(array $target, int $postId, string $postType = "post"): array
    {
        if ($postId <= 0) {
            return ["success" => false, "message" => "A WordPress post ID is required.", "post" => null];
        }

        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            $php = <<<'PHP'
$postId = __POST_ID__;
$post = get_post($postId);
if (!$post) {
    echo "HEXA_POST_SNAPSHOT:" . wp_json_encode(["success" => false, "message" => "WordPress post not found."]);
    return;
}
$author = get_userdata((int) $post->post_author);
$lastLogin = null;
if ($author) {
    foreach (["wfls-last-login", "wp-last-login", "last_login", "last_login_at"] as $lastLoginKey) {
        $candidate = get_user_meta((int) $author->ID, $lastLoginKey, true);
        if ($candidate !== "" && $candidate !== null) {
            $timestamp = is_numeric($candidate) ? (int) $candidate : strtotime((string) $candidate);
            if ($timestamp > 0) {
                $lastLogin = wp_date(DATE_ATOM, $timestamp);
                break;
            }
        }
    }
}
$statusObject = get_post_status_object((string) $post->post_status);
$taxonomies = [];
foreach ((array) get_object_taxonomies((string) $post->post_type, "objects") as $taxonomy => $taxonomyObject) {
    $terms = wp_get_object_terms($postId, (string) $taxonomy);
    if (is_wp_error($terms)) {
        continue;
    }
    $taxonomies[(string) $taxonomy] = [
        "label" => (string) ($taxonomyObject->label ?? $taxonomy),
        "terms" => array_values(array_map(static fn ($term): array => [
            "id" => (int) $term->term_id,
            "name" => (string) $term->name,
            "slug" => (string) $term->slug,
            "parent" => (int) $term->parent,
        ], (array) $terms)),
    ];
}
$featuredId = (int) get_post_thumbnail_id($postId);
$featured = null;
if ($featuredId > 0) {
    $source = wp_get_attachment_image_src($featuredId, "large");
    $featured = [
        "id" => $featuredId,
        "url" => (string) ($source[0] ?? wp_get_attachment_url($featuredId)),
        "width" => (int) ($source[1] ?? 0),
        "height" => (int) ($source[2] ?? 0),
        "alt" => (string) get_post_meta($featuredId, "_wp_attachment_image_alt", true),
        "caption" => (string) wp_get_attachment_caption($featuredId),
    ];
}
$meta = [];
foreach ((array) get_post_meta($postId) as $key => $values) {
    $decoded = array_map("maybe_unserialize", (array) $values);
    $meta[(string) $key] = count($decoded) === 1 ? $decoded[0] : array_values($decoded);
}
$contentHtml = function_exists("do_blocks") ? do_blocks((string) $post->post_content) : (string) $post->post_content;
$contentHtml = wpautop($contentHtml);
$excerptRaw = (string) $post->post_excerpt;
if ($excerptRaw === "") {
    $excerptRaw = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 55);
}
$payload = [
    "id" => $postId,
    "title" => (string) get_the_title($postId),
    "slug" => (string) $post->post_name,
    "type" => (string) $post->post_type,
    "status" => (string) $post->post_status,
    "status_label" => (string) ($statusObject->label ?? ucfirst((string) $post->post_status)),
    "content_raw" => (string) $post->post_content,
    "content_html" => (string) $contentHtml,
    "excerpt_raw" => (string) $post->post_excerpt,
    "excerpt_html" => (string) wpautop($excerptRaw),
    "permalink" => (string) get_permalink($postId),
    "preview_url" => (string) get_preview_post_link($post),
    "edit_url" => (string) get_edit_post_link($postId, ""),
    "date" => $post->post_date !== "0000-00-00 00:00:00" ? mysql2date(DATE_ATOM, $post->post_date, false) : null,
    "date_gmt" => $post->post_date_gmt !== "0000-00-00 00:00:00" ? mysql2date(DATE_ATOM, $post->post_date_gmt, false) : null,
    "modified" => $post->post_modified !== "0000-00-00 00:00:00" ? mysql2date(DATE_ATOM, $post->post_modified, false) : null,
    "modified_gmt" => $post->post_modified_gmt !== "0000-00-00 00:00:00" ? mysql2date(DATE_ATOM, $post->post_modified_gmt, false) : null,
    "author" => $author ? [
        "id" => (int) $author->ID,
        "username" => (string) $author->user_login,
        "name" => (string) $author->display_name,
        "email" => (string) $author->user_email,
        "roles" => array_values(array_map("strval", (array) $author->roles)),
        "last_login_at" => $lastLogin,
    ] : null,
    "featured_image" => $featured,
    "taxonomies" => $taxonomies,
    "comment_status" => (string) $post->comment_status,
    "ping_status" => (string) $post->ping_status,
    "comment_count" => (int) $post->comment_count,
    "parent_id" => (int) $post->post_parent,
    "menu_order" => (int) $post->menu_order,
    "password_protected" => (string) $post->post_password !== "",
    "meta" => $meta,
];
echo "HEXA_POST_SNAPSHOT:" . wp_json_encode(["success" => true, "post" => $payload]);
PHP;
            $result = $this->evaluatePhp(
                $target,
                str_replace("__POST_ID__", (string) $postId, $php)
            );
            if (! ($result["success"] ?? false)) {
                return [
                    "success" => false,
                    "message" => (string) ($result["message"] ?? "WordPress post snapshot failed."),
                    "post" => null,
                ];
            }

            $payload = $this->decodeMarkedPayload(
                (string) ($result["stdout"] ?? ""),
                "HEXA_POST_SNAPSHOT:"
            );
            if (! is_array($payload) || ! ($payload["success"] ?? false)) {
                return [
                    "success" => false,
                    "message" => (string) ($payload["message"] ?? "WordPress post snapshot could not be parsed."),
                    "post" => null,
                ];
            }

            return [
                "success" => true,
                "message" => "WordPress post snapshot loaded via WP Toolkit.",
                "post" => (array) ($payload["post"] ?? []),
            ];
        }

        $endpoint = trim($postType, "/");
        $endpoint = $endpoint === "post" ? "posts" : $endpoint;
        $response = $this->restRequest($target, "get", $endpoint . "/" . $postId, [], ["context" => "edit"]);
        if (! ($response["success"] ?? false) || ! is_array($response["data"] ?? null)) {
            return [
                "success" => false,
                "message" => (string) ($response["message"] ?? "WordPress REST post snapshot failed."),
                "post" => null,
            ];
        }

        $post = (array) $response["data"];
        return [
            "success" => true,
            "message" => "WordPress post snapshot loaded via REST.",
            "post" => [
                "id" => (int) ($post["id"] ?? $postId),
                "title" => (string) data_get($post, "title.rendered", data_get($post, "title.raw", "")),
                "slug" => (string) ($post["slug"] ?? ""),
                "type" => (string) ($post["type"] ?? $postType),
                "status" => (string) ($post["status"] ?? ""),
                "status_label" => ucfirst((string) ($post["status"] ?? "unknown")),
                "content_raw" => (string) data_get($post, "content.raw", ""),
                "content_html" => (string) data_get($post, "content.rendered", ""),
                "excerpt_raw" => (string) data_get($post, "excerpt.raw", ""),
                "excerpt_html" => (string) data_get($post, "excerpt.rendered", ""),
                "permalink" => (string) ($post["link"] ?? ""),
                "preview_url" => (string) data_get($post, "_links.preview.0.href", ""),
                "edit_url" => "",
                "date" => $post["date"] ?? null,
                "date_gmt" => $post["date_gmt"] ?? null,
                "modified" => $post["modified"] ?? null,
                "modified_gmt" => $post["modified_gmt"] ?? null,
                "author" => ["id" => (int) ($post["author"] ?? 0)],
                "featured_image" => isset($post["featured_media"])
                    ? ["id" => (int) $post["featured_media"]]
                    : null,
                "taxonomies" => [],
                "comment_status" => (string) ($post["comment_status"] ?? ""),
                "ping_status" => (string) ($post["ping_status"] ?? ""),
                "comment_count" => 0,
                "parent_id" => (int) ($post["parent"] ?? 0),
                "menu_order" => (int) ($post["menu_order"] ?? 0),
                "password_protected" => (string) ($post["password"] ?? "") !== "",
                "meta" => (array) ($post["meta"] ?? []),
            ],
        ];
    }

    public function listPosts(array $target, array $query = [], string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);

        if ($this->usesWpToolkit($target)) {
            $cliPostType = $postType === "posts" ? "post" : rtrim($postType, "s");
            $authorId = max(0, (int) ($query["author"] ?? 0));
            $perPage = max(1, min(100, (int) ($query["per_page"] ?? 100)));
            $page = max(1, (int) ($query["page"] ?? 1));
            $slug = trim((string) ($query["slug"] ?? ""));
            $search = trim((string) ($query["search"] ?? ""));
            $parts = [
                '$args=[',
                '"post_type"=>' . var_export($cliPostType, true) . ',',
                '"post_status"=>' . var_export((string) ($query["status"] ?? "any"), true) . ',',
                '"posts_per_page"=>' . $perPage . ',',
                '"paged"=>' . $page . ',',
                '"orderby"=>' . var_export((string) ($query["orderby"] ?? "date"), true) . ',',
                '"order"=>' . var_export(strtoupper((string) ($query["order"] ?? "DESC")), true) . ',',
                '"fields"=>"ids",',
                '];',
                'if (' . $authorId . '>0) { $args["author"]=' . $authorId . '; }',
                'if (' . var_export($slug !== '', true) . ') { $args["name"]=' . var_export($slug, true) . '; }',
                'if (' . var_export($search !== '', true) . ') { $args["s"]=' . var_export($search, true) . '; }',
                '$dateQuery=[];',
                'if (' . var_export(!empty($query["after"]), true) . ') { $dateQuery[]=["after"=>' . var_export((string) ($query["after"] ?? ""), true) . ']; }',
                'if (' . var_export(!empty($query["before"]), true) . ') { $dateQuery[]=["before"=>' . var_export((string) ($query["before"] ?? ""), true) . ']; }',
                'if (!empty($dateQuery)) { $args["date_query"]=$dateQuery; }',
                '$query=new WP_Query($args);',
                '$rows=[];',
                'foreach ((array) $query->posts as $postId) {',
                '  $rows[]=[',
                '    "id"=>(int) $postId,',
                '    "date"=>(string) get_post_field("post_date", $postId),',
                '    "status"=>(string) get_post_status($postId),',
                '    "link"=>(string) get_permalink($postId),',
                '    "edit_url"=>(string) get_edit_post_link($postId, "raw"),',
                '    "slug"=>(string) get_post_field("post_name", $postId),',
                '    "title"=>["rendered"=>(string) get_the_title($postId)],',
                '    "author"=>(int) get_post_field("post_author", $postId),',
                '  ];',
                '}',
                'echo "HEXA_POST_LIST:" . wp_json_encode($rows);',
            ];
            $php = implode("", $parts);

            $eval = $this->evaluatePhp($target, $php);
            $payload = $this->decodeMarkedPayload((string) ($eval["stdout"] ?? ""), "HEXA_POST_LIST:");
            if (!is_array($payload)) {
                if (!($eval["success"] ?? false)) {
                    return ["success" => false, "message" => (string) ($eval["message"] ?? "WP Toolkit list posts failed."), "data" => []];
                }

                return ["success" => false, "message" => "Failed to parse WP Toolkit post list output.", "data" => []];
            }

            return ["success" => true, "message" => count($payload) . " post(s) loaded via WP Toolkit.", "data" => $payload];
        }

        $response = $this->restRequest($target, "get", trim($postType, "/"), [], $query);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => (string) ($response["message"] ?? "REST list failed."),
            "data" => ($response["success"] ?? false) ? array_values((array) ($response["data"] ?? [])) : [],
        ];
    }


    public function listMedia(array $target, array $query = []): array
    {
        $target = $this->normalizeTarget($target);
        $mimeType = trim((string) ($query["mime_type"] ?? "image"));
        $perPage = max(1, min(100, (int) ($query["per_page"] ?? 60)));
        $page = max(1, (int) ($query["page"] ?? 1));
        $search = trim((string) ($query["search"] ?? ""));
        $forceRefresh = (bool) ($query["force_refresh"] ?? false);

        if ($this->usesWpToolkit($target)) {
            if (method_exists($this->wptoolkit, "wpCliMediaSelector")) {
                $selectorQuery = [
                    "mime_type" => $mimeType,
                    "per_page" => $perPage,
                    "page" => $page,
                    "search" => $search,
                    "include_ids" => (array) ($query["include_ids"] ?? []),
                ];
                $loader = fn (): array => $this->wptoolkit->wpCliMediaSelector($target["server"], (int) $target["install_id"], $selectorQuery);
                $cacheable = empty($selectorQuery["include_ids"]);
                $selector = (!$cacheable || $forceRefresh)
                    ? $loader()
                    : Cache::remember($this->toolkitCacheKey($target, "media", md5(json_encode($selectorQuery))), now()->addMinutes(5), $loader);
                $items = array_values(array_filter((array) ($selector["items"] ?? []), "is_array"));
                return array_replace($selector, [
                    "success" => (bool) ($selector["success"] ?? false),
                    "message" => (string) ($selector["message"] ?? (count($items) . " media item(s) loaded via WP Toolkit selector.")),
                    "items" => $items,
                    "data" => $items,
                    "source" => "wptoolkit.media_selector",
                    "cached" => $cacheable && !$forceRefresh,
                ]);
            }

            $parts = [
                '$mimeType=' . var_export($mimeType, true) . ';',
                '$perPage=' . $perPage . ';',
                '$page=' . $page . ';',
                '$search=' . var_export($search, true) . ';',
                '$args=["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>$perPage,"paged"=>$page,"orderby"=>"date","order"=>"DESC"];',
                'if ($mimeType !== "") { $args["post_mime_type"]=$mimeType; }',
                'if ($search !== "") { $args["s"]=$search; }',
                '$q=new WP_Query($args);',
                '$items=[];',
                'foreach ($q->posts as $post) {',
                '  $id=(int) $post->ID;',
                '  $full=(string) wp_get_attachment_url($id);',
                '  $sizes=[];',
                '  foreach (["thumbnail","medium","medium_large","large","full"] as $size) { $img=wp_get_attachment_image_src($id,$size); if ($img) { $sizes[$size]=["url"=>(string)$img[0],"width"=>(int)$img[1],"height"=>(int)$img[2]]; } }',
                '  $items[]=["ID"=>$id,"id"=>$id,"post_title"=>(string)$post->post_title,"title"=>(string)$post->post_title,"guid"=>$full,"url"=>$full,"media_url"=>$full,"source_url"=>$full,"thumbnail_url"=>(string)($sizes["thumbnail"]["url"] ?? $full),"medium_url"=>(string)($sizes["medium"]["url"] ?? ($sizes["thumbnail"]["url"] ?? $full)),"post_mime_type"=>(string)$post->post_mime_type,"mime_type"=>(string)$post->post_mime_type,"date"=>(string)$post->post_date,"alt_text"=>(string)get_post_meta($id,"_wp_attachment_image_alt",true),"sizes"=>$sizes];',
                '}',
                'echo "HEXA_MEDIA_LIST:" . wp_json_encode(["success"=>true,"message"=>count($items)." media item(s) loaded via WP Toolkit.","items"=>$items]);',
            ];
            $result = $this->evaluatePhp($target, implode("", $parts));
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($result["message"] ?? "Media list failed."), "items" => []];
            }
            $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_MEDIA_LIST:");
            if (!is_array($payload)) {
                return ["success" => false, "message" => "Failed to parse WordPress media list output.", "items" => []];
            }
            $items = array_values(array_filter((array) ($payload["items"] ?? []), "is_array"));
            return ["success" => true, "message" => (string) ($payload["message"] ?? (count($items) . " media item(s) loaded.")), "items" => $items, "data" => $items];
        }

        $restQuery = ["per_page" => $perPage, "page" => $page];
        if ($search !== "") $restQuery["search"] = $search;
        if ($mimeType !== "") {
            if (str_contains($mimeType, "/")) $restQuery["mime_type"] = $mimeType;
            else $restQuery["media_type"] = $mimeType;
        }
        $response = $this->restRequest($target, "get", "media", [], $restQuery);
        $items = array_values(array_filter((array) ($response["data"] ?? []), "is_array"));
        $items = array_map(static function (array $item): array {
            $sizes = is_array($item["media_details"]["sizes"] ?? null) ? $item["media_details"]["sizes"] : [];
            $thumbnail = (string) ($sizes["thumbnail"]["source_url"] ?? ($item["source_url"] ?? ""));
            $medium = (string) ($sizes["medium"]["source_url"] ?? ($thumbnail ?: ($item["source_url"] ?? "")));
            return array_replace($item, [
                "ID" => (int) ($item["id"] ?? 0),
                "url" => (string) ($item["source_url"] ?? ""),
                "media_url" => (string) ($item["source_url"] ?? ""),
                "thumbnail_url" => $thumbnail,
                "medium_url" => $medium,
            ]);
        }, $items);
        return ["success" => (bool) ($response["success"] ?? false), "message" => ($response["success"] ?? false) ? "Media loaded via REST." : (string) ($response["message"] ?? "Media list failed."), "items" => $items, "data" => $items];
    }


}
