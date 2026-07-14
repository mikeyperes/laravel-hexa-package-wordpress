<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
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
            $result = $this->wptoolkit->wpCliCreatePost(
                $target["server"],
                (int) $target["install_id"],
                (string) ($payload["title"] ?? ""),
                (string) ($payload["content"] ?? ""),
                (string) ($payload["status"] ?? "draft"),
                (array) ($payload["categories"] ?? []),
                (array) ($payload["tags"] ?? []),
                $payload["date"] ?? null,
                $payload["author"] ?? ($target["default_author"] ?: null),
                isset($payload["featured_media"]) ? (int) $payload["featured_media"] : null,
                $postType,
            );

            if (($result["success"] ?? false) && !empty($result["data"]["post_id"]) && !empty($payload["taxonomies"])) {
                foreach ((array) $payload["taxonomies"] as $taxonomy => $termIds) {
                    $this->setPostTerms($target, (int) $result["data"]["post_id"], (string) $taxonomy, (array) $termIds);
                }
            }

            return $result;
        }

        $endpoint = $postType === "post" ? "posts" : trim($postType, "/");
        $response = $this->restRequest($target, "post", $endpoint, $this->buildRestPostPayload($payload));
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "REST publish failed."), "data" => null];
        }

        return ["success" => true, "message" => "Post created via REST.", "data" => $this->formatRestPostData((array) $response["data"])];
    }

    public function updatePost(array $target, int $postId, array $postData): array
    {
        $target = $this->normalizeTarget($target);
        $payload = $this->normalizePostPayload($postData);

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliUpdatePost($target["server"], (int) $target["install_id"], $postId, $this->buildToolkitPostData($payload));
            if (($result["success"] ?? false) && !empty($payload["taxonomies"])) {
                foreach ((array) $payload["taxonomies"] as $taxonomy => $termIds) {
                    $this->setPostTerms($target, $postId, (string) $taxonomy, (array) $termIds);
                }
            }
            return $result;
        }

        $response = $this->restRequest($target, "post", "posts/" . $postId, $this->buildRestPostPayload($payload));
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "REST update failed."), "data" => null];
        }

        return ["success" => true, "message" => "Post updated via REST.", "data" => $this->formatRestPostData((array) $response["data"])];
    }

    public function getPost(array $target, int $postId, string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->wpCliGetPost($target["server"], (int) $target["install_id"], $postId);
        }

        $response = $this->restRequest($target, "get", trim($postType, "/") . "/" . $postId, [], ["context" => "edit"]);
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "REST fetch failed."), "data" => null];
        }

        return ["success" => true, "message" => "Post fetched via REST.", "data" => $this->formatRestPostData((array) $response["data"])];
    }

    public function listPosts(array $target, array $query = [], string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);

        if ($this->usesWpToolkit($target)) {
            $cliPostType = $postType === "posts" ? "post" : rtrim($postType, "s");
            $parts = [
                '$args=[',
                '"post_type"=>' . var_export($cliPostType, true) . ',',
                '"post_status"=>' . var_export((string) ($query["status"] ?? "any"), true) . ',',
                '"posts_per_page"=>' . (int) ($query["per_page"] ?? 100) . ',',
                '"orderby"=>' . var_export((string) ($query["orderby"] ?? "date"), true) . ',',
                '"order"=>' . var_export(strtoupper((string) ($query["order"] ?? "DESC")), true) . ',',
                '"fields"=>"ids",',
                '];',
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
                '    "slug"=>(string) get_post_field("post_name", $postId),',
                '    "title"=>["rendered"=>(string) get_the_title($postId)],',
                '  ];',
                '}',
                'echo "HEXA_POST_LIST:" . wp_json_encode($rows);',
            ];
            $php = implode("", $parts);

            $eval = $this->evaluatePhp($target, $php);
            if (!($eval["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($eval["message"] ?? "WP Toolkit list posts failed."), "data" => []];
            }

            $payload = $this->decodeMarkedPayload((string) ($eval["stdout"] ?? ""), "HEXA_POST_LIST:");
            if (!is_array($payload)) {
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
