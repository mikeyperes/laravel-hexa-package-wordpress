<?php

namespace hexa_package_wordpress\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressManagerService
{
    public function __construct(
        protected WpToolkitService $wptoolkit,
        protected WordPressService $rest,
    ) {
    }

    public function normalizeTarget(array $target): array
    {
        $mode = (string) ($target["mode"] ?? $target["connection_type"] ?? "");
        $server = $target["server"] ?? null;
        $installId = isset($target["install_id"]) ? (int) $target["install_id"] : (isset($target["wordpress_install_id"]) ? (int) $target["wordpress_install_id"] : 0);

        if ($mode === "") {
            $mode = ($server instanceof WhmServer && $installId > 0) ? "wptoolkit" : "rest";
        }

        return [
            "mode" => $mode === "wptoolkit" ? "wptoolkit" : "rest",
            "site_name" => (string) ($target["site_name"] ?? $target["name"] ?? "WordPress site"),
            "url" => rtrim((string) ($target["url"] ?? $target["site_url"] ?? ""), "/"),
            "username" => (string) ($target["username"] ?? $target["wp_username"] ?? ""),
            "application_password" => (string) ($target["application_password"] ?? $target["wp_application_password"] ?? $target["app_password"] ?? ""),
            "server" => $server instanceof WhmServer ? $server : null,
            "install_id" => $installId > 0 ? $installId : null,
            "default_author" => (string) ($target["default_author"] ?? ""),
            "site_id" => isset($target["site_id"]) ? (int) $target["site_id"] : null,
        ];
    }

    public function usesWpToolkit(array $target): bool
    {
        $target = $this->normalizeTarget($target);
        return $target["mode"] === "wptoolkit" && $target["server"] instanceof WhmServer && !empty($target["install_id"]);
    }

    public function connectionMode(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionMode($target["server"]);
        }

        return "rest";
    }

    public function connectionLabel(array $target): string
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->connectionLabel($target["server"]);
        }

        return "REST API";
    }

    public function warmConnection(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            $ssh = $this->wptoolkit->getConnection($target["server"]);

            return [
                "success" => (bool) ($ssh["success"] ?? false),
                "message" => (bool) ($ssh["success"] ?? false)
                    ? $this->connectionLabel($target) . " ready."
                    : (string) ($ssh["error"] ?? "WP Toolkit connection failed."),
                "mode" => $this->connectionMode($target),
                "label" => $this->connectionLabel($target),
            ];
        }

        if (($target["url"] ?? "") === "" || ($target["username"] ?? "") === "" || ($target["application_password"] ?? "") === "") {
            return [
                "success" => false,
                "message" => "REST credentials are incomplete.",
                "mode" => "rest",
                "label" => "REST API",
            ];
        }

        return [
            "success" => true,
            "message" => "REST credentials ready.",
            "mode" => "rest",
            "label" => "REST API",
        ];
    }

    public function discoverInstallsForAccount(WhmServer $server, string $cpanelUsername): array
    {
        return $this->wptoolkit->getInstallsForAccount($server, $cpanelUsername);
    }

    public function testConnection(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            return $this->wptoolkit->wpCliTestWriteAccess($target["server"], (int) $target["install_id"]);
        }

        return $this->rest->testConnection($target["url"], $target["username"], $target["application_password"]);
    }

    public function testWriteAccess(array $target): array
    {
        return $this->testConnection($target);
    }

    public function inspectPlugin(array $target, string $slug, array $bootstrapCandidates = ['initialization.php', 'plugin.php']): array
    {
        $target = $this->normalizeTarget($target);
        $slug = trim($slug, " \t\n\r\0\x0B/");
        $bootstrapCandidates = array_values(array_filter(array_map(static fn ($candidate) => trim((string) $candidate), $bootstrapCandidates)));

        if ($slug === '') {
            return ['success' => false, 'message' => 'Plugin slug is required.', 'plugin' => null];
        }

        if (!$this->usesWpToolkit($target)) {
            return [
                'success' => false,
                'message' => 'Plugin inspection is only available on WP Toolkit targets.',
                'plugin' => null,
            ];
        }

        $parts = [
            'require_once ABSPATH . "wp-admin/includes/plugin.php";',
            '$slug=' . var_export($slug, true) . ';',
            '$bootstrapCandidates=' . var_export($bootstrapCandidates, true) . ';',
            '$dir=trailingslashit(WP_PLUGIN_DIR) . $slug;',
            '$found=is_dir($dir);',
            '$plugins=$found ? (array) get_plugins($slug) : [];',
            '$availableFiles=array_values(array_map("strval", array_keys($plugins)));',
            '$bootstrapFile=""; $pluginFile=""; $pluginData=[];',
            'foreach ($bootstrapCandidates as $candidate) { if (isset($plugins[$candidate])) { $bootstrapFile=(string) $candidate; $pluginFile=$slug . "/" . $bootstrapFile; $pluginData=(array) $plugins[$candidate]; break; } }',
            'if ($pluginFile === "" && !empty($plugins)) { $firstKey=array_key_first($plugins); $bootstrapFile=(string) $firstKey; $pluginFile=$slug . "/" . $bootstrapFile; $pluginData=(array) ($plugins[$firstKey] ?? []); }',
            '$active=$pluginFile !== "" && (is_plugin_active($pluginFile) || (function_exists("is_plugin_active_for_network") && is_plugin_active_for_network($pluginFile)));',
            '$payload=[',
            '"slug"=>$slug,',
            '"found"=>$found,',
            '"active"=>$active,',
            '"directory"=>$dir,',
            '"plugin_file"=>$pluginFile,',
            '"bootstrap_file"=>$bootstrapFile,',
            '"available_files"=>$availableFiles,',
            '"name"=>(string) ($pluginData["Name"] ?? ""),',
            '"version"=>(string) ($pluginData["Version"] ?? ""),',
            '"description"=>(string) ($pluginData["Description"] ?? ""),',
            '"author"=>(string) ($pluginData["Author"] ?? ""),',
            '];',
            'echo "HEXA_PLUGIN_INSPECT:" . wp_json_encode($payload);',
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Plugin inspection failed.'), 'plugin' => null];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_PLUGIN_INSPECT:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse plugin inspection output.', 'plugin' => null];
        }

        $found = (bool) ($payload['found'] ?? false);
        $active = (bool) ($payload['active'] ?? false);
        $message = !$found
            ? "Plugin {$slug} was not found."
            : ($active ? "Plugin {$slug} is active." : "Plugin {$slug} is installed but inactive.");

        return [
            'success' => true,
            'message' => $message,
            'plugin' => $payload,
        ];
    }

    public function getAcfFieldInventory(array $target, array $groupKeys = [], array $fieldNames = []): array
    {
        $target = $this->normalizeTarget($target);
        $groupKeys = array_values(array_filter(array_map('strval', $groupKeys)));
        $fieldNames = array_values(array_filter(array_map('strval', $fieldNames)));

        if (!$this->usesWpToolkit($target)) {
            return [
                'success' => false,
                'message' => 'ACF field inventory is only available on WP Toolkit targets.',
                'groups' => [],
                'fields_flat' => [],
            ];
        }

        $parts = [
            '$groupKeys=' . var_export($groupKeys, true) . ';',
            '$fieldNames=' . var_export($fieldNames, true) . ';',
            'if (!function_exists("acf_get_field_groups") || !function_exists("acf_get_fields")) { echo "HEXA_ACF_INVENTORY:" . wp_json_encode(["success"=>false,"message"=>"ACF field APIs are unavailable.","groups"=>[],"fields_flat"=>[]]); return; }',
            '$groups=(array) acf_get_field_groups();',
            '$groupRows=[]; $flat=[];',
            '$flatten=function(array $fields, string $groupKey, string $groupTitle, string $parentPath = "") use (&$flatten, &$flat, $fieldNames) { $rows=[]; foreach ($fields as $field) { if (!is_array($field)) { continue; } $name=(string) ($field["name"] ?? ""); $path=$parentPath !== "" && $name !== "" ? $parentPath . "." . $name : ($name !== "" ? $name : $parentPath); $row=["group_key"=>$groupKey,"group_title"=>$groupTitle,"field_key"=>(string) ($field["key"] ?? ""),"field_name"=>$name,"field_label"=>(string) ($field["label"] ?? ""),"field_type"=>(string) ($field["type"] ?? ""),"parent_path"=>$parentPath,"path"=>$path,"has_sub_fields"=>!empty($field["sub_fields"]),"instructions"=>(string) ($field["instructions"] ?? "")]; $rows[]=$row; if ($name !== "" && ($fieldNames === [] || in_array($name, $fieldNames, true))) { $flat[]=$row; } if (!empty($field["sub_fields"]) && is_array($field["sub_fields"])) { $rows=array_merge($rows, $flatten($field["sub_fields"], $groupKey, $groupTitle, $path)); } } return $rows; };',
            'foreach ($groups as $group) { if (!is_array($group)) { continue; } $groupKey=(string) ($group["key"] ?? ""); if ($groupKeys !== [] && !in_array($groupKey, $groupKeys, true)) { continue; } $groupTitle=(string) ($group["title"] ?? $groupKey); $fields=(array) acf_get_fields($group); $flattened=$flatten($fields, $groupKey, $groupTitle); $groupRows[]=["key"=>$groupKey,"title"=>$groupTitle,"field_count"=>count($flattened),"location"=>$group["location"] ?? [],"fields"=>$flattened]; }',
            'echo "HEXA_ACF_INVENTORY:" . wp_json_encode(["success"=>true,"message"=>count($groupRows) . " field group(s) loaded.","groups"=>$groupRows,"fields_flat"=>$flat]);',
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Failed to inspect ACF field inventory.'), 'groups' => [], 'fields_flat' => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_ACF_INVENTORY:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse ACF inventory output.', 'groups' => [], 'fields_flat' => []];
        }

        return [
            'success' => (bool) ($payload['success'] ?? false),
            'message' => (string) ($payload['message'] ?? 'ACF field inventory loaded.'),
            'groups' => array_values(array_filter((array) ($payload['groups'] ?? []), 'is_array')),
            'fields_flat' => array_values(array_filter((array) ($payload['fields_flat'] ?? []), 'is_array')),
        ];
    }

    public function getAcfValues(array $target, string $objectType, string|int|null $objectId = null, array $fieldNames = []): array
    {
        $target = $this->normalizeTarget($target);
        $fieldNames = array_values(array_filter(array_map('strval', $fieldNames)));

        if (!$this->usesWpToolkit($target)) {
            return [
                'success' => false,
                'message' => 'ACF value reads are only available on WP Toolkit targets.',
                'selector' => null,
                'values' => [],
                'available_fields' => [],
            ];
        }

        $normalizedObjectType = trim(strtolower($objectType));
        $selector = match ($normalizedObjectType) {
            'user' => 'user_' . (string) $objectId,
            'term' => 'term_' . (string) $objectId,
            'option', 'options' => 'option',
            'raw' => (string) ($objectId ?? ''),
            default => (string) ((int) ($objectId ?? 0)),
        };

        if ($selector === '' || $selector === '0') {
            return [
                'success' => false,
                'message' => 'A valid ACF target selector is required.',
                'selector' => $selector,
                'values' => [],
                'available_fields' => [],
            ];
        }

        $parts = [
            '$selector=' . var_export($selector, true) . ';',
            '$fieldNames=' . var_export($fieldNames, true) . ';',
            'if (!function_exists("get_field") || !function_exists("get_field_objects")) { echo "HEXA_ACF_VALUES:" . wp_json_encode(["success"=>false,"message"=>"ACF value APIs are unavailable.","selector"=>$selector,"values"=>[],"available_fields"=>[]]); return; }',
            '$objects=get_field_objects($selector, false, true, false); if (!is_array($objects)) { $objects=[]; }',
            '$values=[];',
            'if ($fieldNames !== []) { foreach ($fieldNames as $fieldName) { $values[$fieldName]=get_field($fieldName, $selector, false); } } else { foreach ($objects as $fieldName => $field) { $values[(string) $fieldName]=$field["value"] ?? get_field((string) $fieldName, $selector, false); } }',
            'echo "HEXA_ACF_VALUES:" . wp_json_encode(["success"=>true,"message"=>count($values) . " ACF value(s) loaded.","selector"=>$selector,"values"=>$values,"available_fields"=>array_values(array_map("strval", array_keys($objects)))]);',
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Failed to load ACF values.'), 'selector' => $selector, 'values' => [], 'available_fields' => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_ACF_VALUES:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse ACF values output.', 'selector' => $selector, 'values' => [], 'available_fields' => []];
        }

        return [
            'success' => (bool) ($payload['success'] ?? false),
            'message' => (string) ($payload['message'] ?? 'ACF values loaded.'),
            'selector' => (string) ($payload['selector'] ?? $selector),
            'values' => is_array($payload['values'] ?? null) ? $payload['values'] : [],
            'available_fields' => array_values(array_map('strval', (array) ($payload['available_fields'] ?? []))),
        ];
    }

    public function listAuthors(array $target, bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliListAdminUsers($target["server"], (int) $target["install_id"], $forceRefresh);
            $result["authors"] = array_values(array_filter((array) ($result["authors"] ?? []), static fn ($author) => is_array($author) && !empty($author["user_login"])));
            return $result;
        }

        $response = $this->restRequest($target, "get", "users", [], [
            "per_page" => 100,
            "context" => "edit",
            "_fields" => "id,name,slug,email,roles",
        ]);

        if (!($response["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($response["message"] ?? "Author lookup failed."),
                "authors" => [],
                "cache_hit" => null,
                "cached_at" => null,
                "expires_at" => null,
            ];
        }

        $authors = array_map(static function (array $author): array {
            return [
                "id" => (int) ($author["id"] ?? 0),
                "user_login" => (string) ($author["slug"] ?? ""),
                "display_name" => (string) ($author["name"] ?? $author["slug"] ?? ""),
                "email" => (string) ($author["email"] ?? ""),
                "roles" => array_values(array_map("strval", (array) ($author["roles"] ?? []))),
            ];
        }, array_values(array_filter((array) ($response["data"] ?? []), "is_array")));

        return [
            "success" => true,
            "message" => count($authors) . " author(s) loaded via REST.",
            "authors" => $authors,
            "cache_hit" => null,
            "cached_at" => null,
            "expires_at" => null,
        ];
    }

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
        if ($this->usesWpToolkit($target) && $postType === "posts") {
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

    public function uploadMedia(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
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

    public function deletePost(array $target, int $postId, bool $force = true, string $postType = "posts"): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target) && $postType === "posts") {
            return $this->wptoolkit->wpCliDeletePost($target["server"], (int) $target["install_id"], $postId, $force);
        }

        $response = $this->restRequest($target, "delete", trim($postType, "/") . "/" . $postId, ["force" => $force]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Post deleted via REST." : (string) ($response["message"] ?? "Post delete failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
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
        $standardKeys = ["title", "content", "status", "excerpt", "date", "featured_media", "featured_media_id", "author", "categories", "category_ids", "tags", "tag_ids", "taxonomies", "post_type"];
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
            "title" => (string) ($payload["title"] ?? ""),
            "content" => (string) ($payload["content"] ?? ""),
            "status" => (string) ($payload["status"] ?? "draft"),
            "post_type" => trim((string) ($payload["post_type"] ?? "post")) ?: "post",
            "excerpt" => array_key_exists("excerpt", $payload) ? (string) ($payload["excerpt"] ?? "") : null,
            "date" => array_key_exists("date", $payload) ? ($payload["date"] !== null ? (string) $payload["date"] : null) : null,
            "featured_media" => isset($payload["featured_media"]) ? (int) $payload["featured_media"] : (isset($payload["featured_media_id"]) ? (int) $payload["featured_media_id"] : null),
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
        if (!empty($payload["featured_media"])) {
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
        if (!empty($payload["featured_media"])) {
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
        return [
            "post_id" => (int) ($post["id"] ?? 0),
            "post_url" => (string) ($post["link"] ?? ""),
            "post_status" => (string) ($post["status"] ?? ""),
            "post_title" => (string) (($post["title"]["rendered"] ?? $post["title"] ?? "") ?: ""),
            "post_date" => isset($post["date"]) ? (string) $post["date"] : null,
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
        foreach (preg_split("/\r?\n/", $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === "" || !str_contains($line, $marker)) {
                continue;
            }

            $json = substr($line, strpos($line, $marker) + strlen($marker));
            $decoded = json_decode(trim($json), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
