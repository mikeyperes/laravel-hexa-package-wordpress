<?php

namespace hexa_package_wordpress\Services\Concerns;

use Illuminate\Support\Facades\Cache;

trait ManagesWordPressContent
{
    public function resolvePreferredTaxonomy(array $target, array $candidates = ["publication", "category"]): array
    {
        $target = $this->normalizeTarget($target);
        $fallback = [
            "success" => true,
            "taxonomy" => "category",
            "label" => "Categories",
            "hierarchical" => true,
            "message" => "Using category taxonomy fallback.",
        ];

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliResolvePreferredTaxonomy($target["server"], (int) $target["install_id"], $candidates);
            if ((bool) ($result["success"] ?? false)) {
                return [
                    "success" => true,
                    "taxonomy" => (string) ($result["taxonomy"] ?? "category"),
                    "label" => (string) ($result["label"] ?? "Categories"),
                    "hierarchical" => (bool) ($result["hierarchical"] ?? true),
                    "message" => (string) ($result["message"] ?? "Preferred taxonomy resolved."),
                ];
            }
        }

        if (in_array("publication", $candidates, true)) {
            $terms = $this->listTerms($target, "publication");
            if ((bool) ($terms["success"] ?? false) && !empty($terms["terms"])) {
                return [
                    "success" => true,
                    "taxonomy" => "publication",
                    "label" => "Publications",
                    "hierarchical" => true,
                    "message" => "Using publication taxonomy.",
                ];
            }
        }

        return $fallback;
    }

    public function listTerms(array $target, string $taxonomy = "category", bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";

        if ($this->usesWpToolkit($target)) {
            if ($taxonomy === "category") {
                $result = $this->wptoolkit->wpCliListCategories($target["server"], (int) $target["install_id"]);
                $terms = array_values(array_map(static fn ($term) => [
                    "id" => (int) ($term["id"] ?? 0),
                    "term_id" => (int) ($term["id"] ?? 0),
                    "parent" => (int) ($term["parent"] ?? 0),
                    "count" => (int) ($term["count"] ?? 0),
                    "name" => (string) ($term["name"] ?? ""),
                    "slug" => (string) ($term["slug"] ?? ""),
                ], array_values(array_filter((array) ($result["categories"] ?? []), "is_array"))));

                return [
                    "success" => (bool) ($result["success"] ?? false),
                    "message" => (string) ($result["message"] ?? ""),
                    "terms" => $terms,
                    "categories" => $terms,
                    "taxonomy" => "category",
                    "taxonomy_requested" => "category",
                    "taxonomy_label" => "Categories",
                    "hierarchical" => true,
                ];
            }

            if ($taxonomy === "publication") {
                $direct = $this->wptoolkit->wpCliListTaxonomyTerms($target["server"], (int) $target["install_id"], "publication");
                if ((bool) ($direct["success"] ?? false) && !empty($direct["terms"])) {
                    $terms = array_values(array_map([$this, "normalizeTermRow"], (array) ($direct["terms"] ?? [])));
                    return [
                        "success" => true,
                        "message" => (string) ($direct["message"] ?? count($terms) . " publication term(s) loaded."),
                        "terms" => $terms,
                        "categories" => $terms,
                        "taxonomy" => "publication",
                        "taxonomy_requested" => "publication",
                        "taxonomy_label" => "Publications",
                        "hierarchical" => true,
                    ];
                }

                $rows = $this->fetchPublicationTermsViaDb($target["server"], (int) $target["install_id"]);
                return [
                    "success" => !empty($rows),
                    "message" => !empty($rows) ? count($rows) . " publication term(s) loaded from database fallback." : "No publication terms found.",
                    "terms" => $rows,
                    "categories" => $rows,
                    "taxonomy" => "publication",
                    "taxonomy_requested" => "publication",
                    "taxonomy_label" => "Publications",
                    "hierarchical" => true,
                    "cache_hit" => !$forceRefresh ? null : null,
                ];
            }

            $result = $this->wptoolkit->wpCliListTaxonomyTerms($target["server"], (int) $target["install_id"], $taxonomy);
            $terms = array_values(array_map([$this, "normalizeTermRow"], (array) ($result["terms"] ?? [])));

            return [
                "success" => (bool) ($result["success"] ?? false),
                "message" => (string) ($result["message"] ?? ""),
                "terms" => $terms,
                "categories" => $terms,
                "taxonomy" => $taxonomy,
                "taxonomy_requested" => $taxonomy,
                "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
                "hierarchical" => $taxonomy !== "post_tag",
            ];
        }

        $endpoint = $this->restTaxonomyEndpoint($taxonomy);
        $response = $this->restRequest($target, "get", $endpoint, [], ["per_page" => 100]);
        if (!($response["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($response["message"] ?? "Term lookup failed."),
                "terms" => [],
                "categories" => [],
                "taxonomy" => $taxonomy,
                "taxonomy_requested" => $taxonomy,
                "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
                "hierarchical" => $taxonomy !== "post_tag",
            ];
        }

        $terms = array_values(array_map(static function (array $term): array {
            return [
                "id" => (int) ($term["id"] ?? 0),
                "term_id" => (int) ($term["id"] ?? 0),
                "parent" => (int) ($term["parent"] ?? 0),
                "count" => (int) ($term["count"] ?? 0),
                "name" => (string) ($term["name"] ?? ""),
                "slug" => (string) ($term["slug"] ?? ""),
            ];
        }, array_values(array_filter((array) ($response["data"] ?? []), "is_array"))));

        return [
            "success" => true,
            "message" => count($terms) . " term(s) loaded via REST.",
            "terms" => $terms,
            "categories" => $terms,
            "taxonomy" => $taxonomy,
            "taxonomy_requested" => $taxonomy,
            "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
            "hierarchical" => $taxonomy !== "post_tag",
        ];
    }

    public function ensureTerms(array $target, array $names, string $taxonomy = "category"): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";
        $names = array_values(array_unique(array_filter(array_map(static fn ($name) => trim((string) $name), $names))));

        if ($names === []) {
            return ["success" => true, "message" => "No terms to resolve.", "term_ids" => [], "term_details" => []];
        }

        if ($this->usesWpToolkit($target)) {
            if ($taxonomy === "category") {
                return $this->wptoolkit->wpCliBatchCategories($target["server"], (int) $target["install_id"], $names);
            }
            if ($taxonomy === "post_tag") {
                return $this->wptoolkit->wpCliBatchTags($target["server"], (int) $target["install_id"], $names);
            }
            return $this->ensureToolkitTerms($target, $names, $taxonomy);
        }

        $existing = $this->listTerms($target, $taxonomy);
        $map = [];
        foreach ((array) ($existing["terms"] ?? []) as $term) {
            if (is_array($term)) {
                $map[mb_strtolower(trim((string) ($term["name"] ?? "")))] = (int) ($term["id"] ?? $term["term_id"] ?? 0);
            }
        }

        $termIds = [];
        $details = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if (isset($map[$key]) && $map[$key] > 0) {
                $termIds[] = $map[$key];
                $details[] = ["name" => $name, "id" => $map[$key], "existed" => true, "error" => null];
                continue;
            }

            $created = $this->restRequest($target, "post", $this->restTaxonomyEndpoint($taxonomy), ["name" => $name]);
            if (($created["success"] ?? false) && is_array($created["data"] ?? null) && !empty($created["data"]["id"])) {
                $termId = (int) $created["data"]["id"];
                $termIds[] = $termId;
                $details[] = ["name" => $name, "id" => $termId, "existed" => false, "error" => null];
                $map[$key] = $termId;
                continue;
            }

            $details[] = ["name" => $name, "id" => 0, "existed" => false, "error" => (string) ($created["message"] ?? "Term creation failed.")];
        }

        return [
            "success" => count($termIds) > 0,
            "message" => count($termIds) . "/" . count($names) . " term(s) resolved.",
            "term_ids" => array_values(array_unique(array_filter(array_map("intval", $termIds)))),
            "term_details" => $details,
        ];
    }

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


    public function getUserProfile(array $target, int $userId, bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) return ["success" => false, "message" => "User ID is required.", "data" => []];

        $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1, "force_refresh" => $forceRefresh]);
        if (!($users["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($users["message"] ?? "User lookup failed."), "data" => []];
        }

        $data = is_array($users["users"][0] ?? null) ? $users["users"][0] : [];
        if ($data === []) {
            return ["success" => false, "message" => "WordPress user #" . $userId . " was not found.", "data" => []];
        }

        if ($this->usesWpToolkit($target)) {
            $meta = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "user meta list " . $userId . " --format=json");
            foreach ((array) (json_decode((string) ($meta["stdout"] ?? "[]"), true) ?: []) as $row) {
                if (is_array($row)) $data[(string) ($row["meta_key"] ?? "")] = (string) ($row["meta_value"] ?? "");
            }
            if (empty($data["avatar_url"]) && !empty($data["wp_user_avatars"])) {
                foreach (explode(chr(34), $data["wp_user_avatars"]) as $part) {
                    if (str_starts_with($part, "http")) { $data["avatar_url"] = $part; break; }
                }
            }
            $data["avatar_media_id"] = (string) ($data["wp_user_avatar"] ?? "");
        }
        $data["ID"] = (string) $userId;
        $data["wp_admin_url"] = "/wp-admin/user-edit.php?user_id=" . $userId;
        $data["profile_admin_url"] = $data["wp_admin_url"];
        return ["success" => true, "message" => "User profile loaded.", "data" => $data];
    }


    public function setUserAvatar(array $target, int $userId, ?int $mediaId, bool $deletePreviousMedia = false): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) return ["success" => false, "message" => "User ID is required.", "media" => null];
        if (!$this->usesWpToolkit($target)) return ["success" => false, "message" => "Profile avatar writes require WP Toolkit.", "media" => null];
        $before = $this->getUserProfile($target, $userId);
        $previous = (int) (($before["data"]["wp_user_avatar"] ?? $before["data"]["avatar_media_id"] ?? 0));
        $mediaId = $mediaId !== null && $mediaId > 0 ? (int) $mediaId : 0;
        $command = $mediaId > 0 ? "user meta update " . $userId . " wp_user_avatar " . $mediaId : "user meta delete " . $userId . " wp_user_avatar";
        $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command);
        if ($mediaId > 0) {
            $url = $this->wpCliAttachmentUrl($target, $mediaId);
            $payload = serialize(["media_id" => $mediaId, "site_id" => 1, "full" => $url, 96 => $url]);
            $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "user meta update " . $userId . " wp_user_avatars " . escapeshellarg($payload));
        } else {
            $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "user meta delete " . $userId . " wp_user_avatars");
        }
        if ($deletePreviousMedia && $previous > 0 && $previous !== $mediaId) $this->deleteMedia($target, $previous, true);
        $profile = $this->getUserProfile($target, $userId);
        return ["success" => !str_contains(strtolower((string) ($result["stdout"] ?? "")), "error"), "message" => $mediaId > 0 ? "Profile avatar updated via WP Toolkit." : "Profile avatar cleared via WP Toolkit.", "media" => ["media_id" => $mediaId, "avatar_url" => (string) ($profile["data"]["avatar_url"] ?? "")]];
    }

    public function updateNativeField(array $target, string $objectType, int $objectId, string $field, string $value): array
    {
        $target = $this->normalizeTarget($target);
        $objectType = strtolower(trim($objectType));
        $field = trim($field);
        if ($objectId <= 0 || $field === "") {
            return ["success" => false, "message" => "A WordPress object ID and field are required."];
        }

        if ($objectType === "post") {
            if ($field !== "post_title") {
                return ["success" => false, "message" => "Unsupported native post field: " . $field];
            }
            $result = $this->updatePost($target, $objectId, ["title" => $value]);
            return ["success" => (bool) ($result["success"] ?? false), "message" => (string) ($result["message"] ?? "Post field update finished."), "data" => $result["data"] ?? null];
        }

        if ($objectType !== "user") {
            return ["success" => false, "message" => "Unsupported native object type: " . $objectType];
        }

        $allowed = [
            "user_email" => "user_email",
            "email" => "user_email",
            "display_name" => "display_name",
            "first_name" => "first_name",
            "last_name" => "last_name",
            "description" => "description",
            "nickname" => "nickname",
            "user_url" => "user_url",
        ];
        if (!isset($allowed[$field])) {
            return ["success" => false, "message" => "Unsupported native user field: " . $field];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "user update " . $objectId . " --" . $allowed[$field] . "=" . escapeshellarg($value);
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            $failed = !($result["success"] ?? false) || str_contains(strtolower($stdout), "error") || str_contains(strtolower($stdout), "fatal");
            if (!$failed) {
                $this->bumpToolkitCacheVersion($target, "users");
            }
            return ["success" => !$failed, "message" => $failed ? ($stdout ?: "User field update failed.") : "User field updated via WP Toolkit.", "data" => null];
        }

        $payload = $allowed[$field] === "user_email" ? ["email" => $value] : ["meta" => [$field => $value]];
        if ($field === "display_name") {
            $payload = ["name" => $value];
        }
        $response = $this->restRequest($target, "post", "users/" . $objectId, $payload);
        return ["success" => (bool) ($response["success"] ?? false), "message" => ($response["success"] ?? false) ? "User field updated via REST." : (string) ($response["message"] ?? "User field update failed."), "data" => $response["data"] ?? null];
    }

    public function updateUserMeta(array $target, int $userId, string $key, mixed $value): array
    {
        $target = $this->normalizeTarget($target);
        $key = trim($key);
        if ($userId <= 0 || $key === "") {
            return ["success" => false, "message" => "A user ID and meta key are required."];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "user meta update " . $userId . " " . escapeshellarg($key) . " " . escapeshellarg((string) $value);
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            $failed = !($result["success"] ?? false) || str_contains(strtolower($stdout), "error") || str_contains(strtolower($stdout), "fatal");
            if (!$failed) {
                $this->bumpToolkitCacheVersion($target, "users");
            }
            return ["success" => !$failed, "message" => $failed ? ($stdout ?: "User meta update failed.") : "User meta updated via WP Toolkit."];
        }

        $response = $this->restRequest($target, "post", "users/" . $userId, ["meta" => [$key => $value]]);
        return ["success" => (bool) ($response["success"] ?? false), "message" => ($response["success"] ?? false) ? "User meta updated via REST." : (string) ($response["message"] ?? "User meta update failed.")];
    }

    public function updateOption(array $target, string $option, mixed $value): array
    {
        $target = $this->normalizeTarget($target);
        $option = trim($option);
        if ($option === "") {
            return ["success" => false, "message" => "An option name is required."];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "option update " . escapeshellarg($option) . " " . escapeshellarg((string) $value);
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            $failed = !($result["success"] ?? false) || str_contains(strtolower($stdout), "error") || str_contains(strtolower($stdout), "fatal");
            return ["success" => !$failed, "message" => $failed ? ($stdout ?: "Option update failed.") : "Option updated via WP Toolkit."];
        }

        return ["success" => false, "message" => "Option updates require WP Toolkit."];
    }


    public function getOption(array $target, string $option, mixed $default = null): array
    {
        $target = $this->normalizeTarget($target);
        $option = trim($option);
        if ($option === "") {
            return ["success" => false, "message" => "An option name is required.", "value" => $default];
        }

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "option get " . escapeshellarg($option), 120);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            $failed = !($result["success"] ?? false) || str_contains(strtolower($stdout), "error") || str_contains(strtolower($stdout), "fatal");
            return [
                "success" => !$failed,
                "message" => $failed ? ($stdout ?: "Option lookup failed.") : "Option loaded via WP Toolkit.",
                "value" => $failed ? $default : $stdout,
            ];
        }

        return ["success" => false, "message" => "Option reads require WP Toolkit.", "value" => $default];
    }

    public function getSiteIcon(array $target): array
    {
        $target = $this->normalizeTarget($target);
        $parts = [
            '$id=(int) get_option("site_icon");',
            '$url="";',
            'if (function_exists("get_site_icon_url")) { $url=(string) get_site_icon_url(512); }',
            'if ($url==="" && $id>0 && function_exists("wp_get_attachment_image_url")) { $u=wp_get_attachment_image_url($id,"full"); if ($u) { $url=(string) $u; } }',
            '$mediaId=$id; if ($mediaId<=0 && $url!=="" && function_exists("attachment_url_to_postid")) { $mediaId=(int) attachment_url_to_postid($url); }',
            '$favicon_path=rtrim(ABSPATH,"/")."/favicon.ico";',
            '$favicon_url=home_url("/favicon.ico");',
            '$favicon_exists=@is_file($favicon_path);',
            '$favicon_size=$favicon_exists ? (int) @filesize($favicon_path) : 0;',
            '$head=$favicon_exists ? (string) @file_get_contents($favicon_path,false,null,0,4) : "";',
            '$favicon_valid=($head === "\\x00\\x00\\x01\\x00");',
            'echo "HEXA_SITE_ICON:" . wp_json_encode(["success"=>true,"site_icon_id"=>$id,"site_icon_url"=>$url,"media_id"=>$mediaId,"source"=>($url!=="" ? "wordpress_site_icon" : "none"),"wordpress_site_icon_valid"=>($id>0 && $url!==""),"favicon_ico_url"=>$favicon_url,"favicon_ico_path"=>$favicon_path,"favicon_ico_exists"=>$favicon_exists,"favicon_ico_valid"=>$favicon_valid,"favicon_ico_size"=>$favicon_size]);',
        ];
        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Site icon lookup failed."), "site_icon_id" => 0, "site_icon_url" => "", "media_id" => 0, "source" => "none", "wordpress_site_icon_valid" => false, "favicon_ico_url" => rtrim((string) ($target["url"] ?? ""), "/") . "/favicon.ico", "favicon_ico_exists" => false, "favicon_ico_valid" => false, "favicon_ico_size" => 0];
        }
        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_SITE_ICON:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse site icon output.", "site_icon_id" => 0, "site_icon_url" => "", "media_id" => 0, "source" => "none", "wordpress_site_icon_valid" => false, "favicon_ico_url" => rtrim((string) ($target["url"] ?? ""), "/") . "/favicon.ico", "favicon_ico_exists" => false, "favicon_ico_valid" => false, "favicon_ico_size" => 0];
        }
        $payload["wordpress_site_icon_valid"] = (bool) ($payload["wordpress_site_icon_valid"] ?? ((int) ($payload["site_icon_id"] ?? 0) > 0 && (string) ($payload["site_icon_url"] ?? "") !== ""));
        $payload["favicon_ico_url"] = (string) ($payload["favicon_ico_url"] ?? (rtrim((string) ($target["url"] ?? ""), "/") . "/favicon.ico"));
        $payload["favicon_ico_exists"] = (bool) ($payload["favicon_ico_exists"] ?? false);
        $payload["favicon_ico_valid"] = (bool) ($payload["favicon_ico_valid"] ?? false);
        $payload["favicon_ico_size"] = (int) ($payload["favicon_ico_size"] ?? 0);
        if ((string) ($payload["site_icon_url"] ?? "") === "") {
            $fallback = $this->discoverSiteIconFallback((string) ($target["url"] ?? ""));
            if ((string) ($fallback["url"] ?? "") !== "") {
                $payload["site_icon_url"] = (string) $fallback["url"];
                $payload["source"] = (string) ($fallback["source"] ?? "html_icon_link");
                $payload["media_id"] = 0;
            }
        }
        $source = (string) ($payload["source"] ?? "none");
        $payload["message"] = ((string) ($payload["site_icon_url"] ?? "")) !== ""
            ? ($source === "wordpress_site_icon" ? "WordPress site icon loaded." : "Favicon found via " . str_replace("_", " ", $source) . ".")
            : "No favicon found.";
        return $payload;
    }

    public function purgeSiteCache(array $target): array
    {
        $target = $this->normalizeTarget($target);
        $parts = [
            '$actions=[];',
            '$warnings=[];',
            'if (function_exists("wp_cache_flush")) { wp_cache_flush(); $actions[]="wp_cache_flush"; }',
            '$front=(int) get_option("page_on_front"); if ($front>0 && function_exists("clean_post_cache")) { clean_post_cache($front); $actions[]="front_page_post_cache"; }',
            'if (function_exists("clean_post_cache")) { clean_post_cache((int) get_option("site_icon")); $actions[]="site_icon_post_cache"; }',
            'if (function_exists("delete_transient")) { delete_transient("site_icon_url"); delete_transient("_site_icon_url"); $actions[]="site_icon_transients"; }',
            '$active=(array) get_option("active_plugins", []);',
            '$litespeed=in_array("litespeed-cache/litespeed-cache.php", $active, true) || defined("LSCWP_V");',
            'if ($litespeed && has_action("litespeed_purge_all")) { ob_start(); do_action("litespeed_purge_all"); ob_end_clean(); $actions[]="litespeed_purge_all"; }',
            'elseif ($litespeed) { $warnings[]="LiteSpeed was detected, but the purge hook is unavailable in the WP Toolkit wrapper context."; }',
            '$actions=array_values(array_unique($actions));',
            '$message=count($actions)." WordPress cache purge action(s) requested.";',
            'if (!empty($warnings)) { $message.=" Warning: ".implode(" ", array_values(array_unique($warnings))); }',
            'echo "HEXA_SITE_CACHE_PURGE:" . wp_json_encode(["success"=>true,"message"=>$message,"actions"=>$actions,"warnings"=>array_values(array_unique($warnings)),"litespeed_detected"=>$litespeed]);',
        ];
        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WordPress cache purge failed."), "actions" => [], "warnings" => []];
        }
        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_SITE_CACHE_PURGE:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse WordPress cache purge output.", "actions" => [], "warnings" => []];
        }

        $actions = array_values(array_unique((array) ($payload["actions"] ?? [])));
        $warnings = array_values(array_filter((array) ($payload["warnings"] ?? [])));
        $needsDirectLiteSpeed = ($payload["litespeed_detected"] ?? false)
            && !in_array("litespeed_purge_all", $actions, true)
            && $this->usesWpToolkit($target)
            && method_exists($this->wptoolkit, "wpCliEvalWithPlugins");

        if ($needsDirectLiteSpeed) {
            $directPhp = '$actions=[];$warnings=[];'
                . 'if (has_action("litespeed_purge_all")) { ob_start(); do_action("litespeed_purge_all"); ob_end_clean(); $actions[]="litespeed_purge_all"; }'
                . 'elseif (defined("LSCWP_V")) { $warnings[]="LiteSpeed is loaded, but the purge hook is unavailable."; }'
                . 'else { $warnings[]="LiteSpeed is not loaded in the direct wp-cli context."; }'
                . 'echo "HEXA_LITESPEED_PURGE:" . wp_json_encode(["success"=>empty($warnings),"actions"=>$actions,"warnings"=>$warnings]);';
            $direct = $this->wptoolkit->wpCliEvalWithPlugins($target["server"], (int) $target["install_id"], $directPhp, 45);
            if (($direct["success"] ?? false)) {
                $directPayload = $this->decodeMarkedPayload((string) ($direct["stdout"] ?? ""), "HEXA_LITESPEED_PURGE:");
                if (is_array($directPayload)) {
                    foreach ((array) ($directPayload["actions"] ?? []) as $action) {
                        $actions[] = (string) $action;
                    }
                    foreach ((array) ($directPayload["warnings"] ?? []) as $warning) {
                        $warnings[] = (string) $warning;
                    }
                    if (in_array("litespeed_purge_all", $actions, true)) {
                        $warnings = array_values(array_filter($warnings, fn ($warning) => !str_contains($warning, "purge hook is unavailable")));
                    }
                }
            } else {
                $warnings[] = (string) ($direct["message"] ?? "Direct LiteSpeed purge failed.");
            }
        }

        $actions = array_values(array_unique($actions));
        $warnings = array_values(array_unique(array_filter($warnings)));
        $payload["actions"] = $actions;
        $payload["warnings"] = $warnings;
        $payload["message"] = count($actions) . " WordPress cache purge action(s) requested.";
        if (!empty($warnings)) {
            $payload["message"] .= " Warning: " . implode(" ", $warnings);
        }

        return $payload;
    }

    public function createLetterSiteIcon(array $target, string $letter, array $options = []): array
    {
        $target = $this->normalizeTarget($target);
        $letter = strtoupper(substr((string) preg_replace("/[^A-Za-z0-9]/", "", $letter), 0, 1));
        if ($letter === "") {
            $letter = "H";
        }
        if (!function_exists("imagecreatetruecolor") || !function_exists("imagepng")) {
            return ["success" => false, "message" => "PHP GD is required to generate a letter favicon."];
        }

        $background = $this->hexToRgb((string) ($options["background"] ?? "#111827"), [17, 24, 39]);
        $foreground = $this->hexToRgb((string) ($options["foreground"] ?? "#ffffff"), [255, 255, 255]);
        $size = 512;
        $image = imagecreatetruecolor($size, $size);
        if (!$image) {
            return ["success" => false, "message" => "Could not create favicon canvas."];
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $bg = imagecolorallocate($image, $background[0], $background[1], $background[2]);
        $fg = imagecolorallocate($image, $foreground[0], $foreground[1], $foreground[2]);
        imagefilledrectangle($image, 0, 0, $size, $size, $bg ?: 0);

        $font = $this->firstExistingPath([
            "/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
            "/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf",
            "/usr/share/fonts/google-droid/DroidSans-Bold.ttf",
            "/usr/share/fonts/liberation-mono/LiberationMono-Bold.ttf",
            "/usr/share/fonts/liberation/LiberationSans-Bold.ttf",
            "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
            "/usr/share/fonts/google-noto/NotoSans-Bold.ttf",
        ]);
        if ($font !== "" && function_exists("imagettfbbox") && function_exists("imagettftext")) {
            $fontSize = (int) round($size * 0.74);
            $box = imagettfbbox($fontSize, 0, $font, $letter);
            $xs = [(int) $box[0], (int) $box[2], (int) $box[4], (int) $box[6]];
            $ys = [(int) $box[1], (int) $box[3], (int) $box[5], (int) $box[7]];
            $minX = min($xs);
            $maxX = max($xs);
            $minY = min($ys);
            $maxY = max($ys);
            $textWidth = $maxX - $minX;
            $textHeight = $maxY - $minY;
            $x = (int) (($size - $textWidth) / 2 - $minX);
            $y = (int) (($size - $textHeight) / 2 - $minY);
            imagettftext($image, $fontSize, 0, $x, $y, $fg ?: 0, $font, $letter);
        } else {
            $smallSize = 18;
            $small = imagecreatetruecolor($smallSize, $smallSize);
            imagefilledrectangle($small, 0, 0, $smallSize, $smallSize, $bg ?: 0);
            $fontId = 5;
            $tw = imagefontwidth($fontId) * strlen($letter);
            $th = imagefontheight($fontId);
            imagestring($small, $fontId, (int) (($smallSize - $tw) / 2), (int) (($smallSize - $th) / 2), $letter, $fg ?: 0);
            $scaled = 390;
            imagecopyresampled($image, $small, (int) (($size - $scaled) / 2), (int) (($size - $scaled) / 2), 0, 0, $scaled, $scaled, $smallSize, $smallSize);
            imagedestroy($small);
        }

        $tmp = tempnam(sys_get_temp_dir(), "sfpf-favicon-");
        if (!$tmp) {
            imagedestroy($image);
            return ["success" => false, "message" => "Could not allocate a temporary favicon file."];
        }
        $png = $tmp . ".png";
        @rename($tmp, $png);
        if (!imagepng($image, $png)) {
            imagedestroy($image);
            @unlink($png);
            return ["success" => false, "message" => "Generated favicon image could not be written."];
        }
        imagedestroy($image);

        $filename = "favicon-" . strtolower($letter) . "-" . gmdate("YmdHis") . ".png";
        $upload = $this->uploadMedia($target, $png, $filename, "Site icon " . $letter, "", "Generated letter favicon");
        @unlink($png);
        $data = is_array($upload["data"] ?? null) ? $upload["data"] : [];
        $mediaId = (int) ($data["media_id"] ?? $upload["media_id"] ?? 0);
        if (!($upload["success"] ?? false) || $mediaId <= 0) {
            return ["success" => false, "message" => (string) ($upload["message"] ?? "Generated favicon upload failed.")];
        }
        $set = $this->setSiteIcon($target, $mediaId);
        if (!($set["success"] ?? false)) {
            return ["success" => false, "message" => "Generated media #" . $mediaId . " but applying it as the site icon failed: " . (string) ($set["message"] ?? "")];
        }
        $set["source"] = "generated_letter";
        $set["message"] = "Letter favicon generated and applied.";
        return $set;
    }

    public function setSiteIcon(array $target, int $attachmentId): array
    {
        $target = $this->normalizeTarget($target);
        if ($attachmentId <= 0) {
            return ["success" => false, "message" => "A media attachment ID is required to set the site icon."];
        }
        $parts = [
            '$id=' . $attachmentId . ';',
            '$att=get_post($id);',
            'if (!$att || $att->post_type!=="attachment") { echo "HEXA_SITE_ICON_SET:" . wp_json_encode(["success"=>false,"message"=>"Attachment #".$id." was not found."]); return; }',
            'update_option("site_icon", $id);',
            'if (function_exists("delete_transient")) { delete_transient("site_icon_url"); delete_transient("_site_icon_url"); }',
            '$url=function_exists("get_site_icon_url") ? (string) get_site_icon_url(512) : (string) wp_get_attachment_image_url($id,"full");',
            '$favicon_path=rtrim(ABSPATH,"/")."/favicon.ico"; $favicon_url=home_url("/favicon.ico"); $favicon_ico=false; $favicon_valid=false; $favicon_size=0; $favicon_message=""; $favicon_src=get_attached_file($id);',
            '$make_png=function($source,$size){ $sw=(int) imagesx($source); $sh=(int) imagesy($source); if ($sw<=0 || $sh<=0) { return ""; } $canvas=imagecreatetruecolor($size,$size); if (!$canvas) { return ""; } imagealphablending($canvas,false); imagesavealpha($canvas,true); $clear=imagecolorallocatealpha($canvas,0,0,0,127); imagefilledrectangle($canvas,0,0,$size,$size,$clear); $scale=min($size/$sw,$size/$sh); $dw=max(1,(int) round($sw*$scale)); $dh=max(1,(int) round($sh*$scale)); $dx=(int) floor(($size-$dw)/2); $dy=(int) floor(($size-$dh)/2); imagecopyresampled($canvas,$source,$dx,$dy,0,0,$dw,$dh,$sw,$sh); ob_start(); imagepng($canvas); $png=(string) ob_get_clean(); imagedestroy($canvas); return $png; };',
            '$make_ico=function($source) use ($make_png){ $images=[]; foreach ([256,32] as $size) { $data=$make_png($source,$size); if ($data!=="") { $images[]=[$size,$size,$data]; } } if (empty($images)) { return ""; } $offset=6+(count($images)*16); $dir=""; $body=""; foreach ($images as $img) { $w=(int) $img[0]; $h=(int) $img[1]; $data=(string) $img[2]; $dir.=pack("CCCCvvVV",$w>=256?0:$w,$h>=256?0:$h,0,0,1,32,strlen($data),$offset); $body.=$data; $offset+=strlen($data); } return pack("vvv",0,1,count($images)).$dir.$body; };',
            'if (!$favicon_src || !@is_file($favicon_src)) { $favicon_message="Attachment file is unavailable, so root favicon.ico was not written."; } elseif (!function_exists("imagecreatefromstring") || !function_exists("imagecreatetruecolor") || !function_exists("imagepng")) { $favicon_message="PHP GD is unavailable, so root favicon.ico was not written."; } else { $bytes=@file_get_contents($favicon_src); $img=$bytes!==false ? @imagecreatefromstring($bytes) : false; if (!$img) { $favicon_message="Attachment image could not be decoded for favicon.ico."; } else { $ico=$make_ico($img); imagedestroy($img); if ($ico==="") { $favicon_message="Could not generate ICO bytes from the attachment."; } else { $written=@file_put_contents($favicon_path,$ico); if ($written===false) { $favicon_message="Could not write root favicon.ico."; } else { @chmod($favicon_path,0644); $favicon_ico=true; $favicon_size=(int) @filesize($favicon_path); $head=(string) @file_get_contents($favicon_path,false,null,0,4); $favicon_valid=($head === "\\x00\\x00\\x01\\x00"); if (!$favicon_valid) { $favicon_message="Root favicon.ico was written but failed ICO header validation."; } } } } }',
            '$message=$favicon_ico && $favicon_valid ? "Site icon updated and root favicon.ico written as a valid ICO." : "Site icon updated."; if ($favicon_message!=="") { $message.=" ".$favicon_message; }',
            'echo "HEXA_SITE_ICON_SET:" . wp_json_encode(["success"=>true,"message"=>$message,"site_icon_id"=>$id,"site_icon_url"=>$url,"media_id"=>$id,"source"=>"wordpress_site_icon","wordpress_site_icon_valid"=>($id>0 && $url!==""),"favicon_ico"=>$favicon_ico,"favicon_ico_url"=>$favicon_url,"favicon_ico_path"=>$favicon_path,"favicon_ico_exists"=>@is_file($favicon_path),"favicon_ico_valid"=>$favicon_valid,"favicon_ico_size"=>$favicon_size,"favicon_message"=>$favicon_message]);',
        ];
        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Site icon update failed.")];
        }
        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_SITE_ICON_SET:");
        return is_array($payload) ? $payload : ["success" => false, "message" => "Failed to parse site icon update output."];
    }

    public function clearSiteIcon(array $target, bool $deleteMedia = false): array
    {
        $target = $this->normalizeTarget($target);
        $parts = [
            '$prev=(int) get_option("site_icon");',
            '$deleteMedia=' . ($deleteMedia ? "true" : "false") . ';',
            'delete_option("site_icon");',
            'if (function_exists("delete_transient")) { delete_transient("site_icon_url"); delete_transient("_site_icon_url"); }',
            '$deleted=false;',
            'if ($deleteMedia && $prev>0) { $att=get_post($prev); if ($att && $att->post_type==="attachment") { $deleted=(bool) wp_delete_attachment($prev, true); } }',
            '$favicon_path=rtrim(ABSPATH,"/")."/favicon.ico"; $favicon_url=home_url("/favicon.ico"); $favicon_removed=false; if (@file_exists($favicon_path)) { $favicon_removed=(bool) @unlink($favicon_path); }',
            'echo "HEXA_SITE_ICON_CLEAR:" . wp_json_encode(["success"=>true,"message"=>$favicon_removed ? "Site icon cleared and root favicon.ico removed." : "Site icon cleared.","previous_media_id"=>$prev,"deleted_previous_media"=>$deleted,"site_icon_id"=>0,"site_icon_url"=>"","media_id"=>0,"source"=>"none","wordpress_site_icon_valid"=>false,"favicon_removed"=>$favicon_removed,"favicon_ico_url"=>$favicon_url,"favicon_ico_path"=>$favicon_path,"favicon_ico_exists"=>@is_file($favicon_path),"favicon_ico_valid"=>false,"favicon_ico_size"=>0]);',
        ];
        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Site icon clear failed.")];
        }
        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_SITE_ICON_CLEAR:");
        return is_array($payload) ? $payload : ["success" => false, "message" => "Failed to parse site icon clear output."];
    }

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
}
