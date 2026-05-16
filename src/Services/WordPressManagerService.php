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
    // ===== code-side unique methods (preserved during 3-way merge) =====

    // === createToolkitPost ===
private function createToolkitPost(array $target, array $payload): array
    {
        $php = <<<'PHP'
$payload = __PAYLOAD__ ;
$post = [
    "post_title" => (string) ($payload["title"] ?? ""),
    "post_content" => (string) ($payload["content"] ?? ""),
    "post_status" => (string) (($payload["status"] ?? "draft") ?: "draft"),
    "post_type" => (string) (($payload["post_type"] ?? "post") ?: "post"),
];
if (array_key_exists("excerpt", $payload) && $payload["excerpt"] !== null) {
    $post["post_excerpt"] = (string) $payload["excerpt"];

    // === isLocalWhmServerTarget ===
private function isLocalWhmServerTarget(array $target): bool
    {
        $target = $this->normalizeTarget($target);
        return $this->usesWpToolkit($target)
            && $target["server"] instanceof WhmServer
            && $this->wptoolkit->isSameHostServer($target["server"]);
    }

    // === normalizeUserRow ===
private function normalizeUserRow(array $user): array
    {
        return [
            "id" => (int) ($user["id"] ?? $user["ID"] ?? 0),
            "ID" => (int) ($user["ID"] ?? $user["id"] ?? 0),
            "user_login" => (string) ($user["user_login"] ?? $user["slug"] ?? ""),
            "display_name" => (string) ($user["display_name"] ?? $user["name"] ?? ""),
            "user_email" => (string) ($user["user_email"] ?? $user["email"] ?? ""),
            "roles" => array_values(array_map("strval", (array) ($user["roles"] ?? []))),
        ];
    }

    // === restPostEndpoint ===
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

    // === uploadToolkitLocalFile ===
private function uploadToolkitLocalFile(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->isLocalWhmServerTarget($target)) {
            return ["success" => false, "message" => "Toolkit local file uploads require a same-server WordPress target."];
        }
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

    // === createUser ===
public function createUser(array $target, array $payload): array
    {
        $target = $this->normalizeTarget($target);
        $login = trim((string) ($payload["username"] ?? $payload["user_login"] ?? ""));
        $email = trim((string) ($payload["email"] ?? $payload["user_email"] ?? ""));
        $displayName = trim((string) ($payload["display_name"] ?? $payload["name"] ?? ""));
        $role = trim((string) ($payload["role"] ?? ""));
        $password = (string) ($payload["password"] ?? $payload["user_pass"] ?? "");

        if ($login === "" || $email === "") {
            return ["success" => false, "message" => "Username and email are required.", "user" => null];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "user create " . escapeshellarg($login) . " " . escapeshellarg($email);
            if ($displayName !== "") {
                $command .= " --display_name=" . escapeshellarg($displayName);
            }
            if ($role !== "") {
                $command .= " --role=" . escapeshellarg($role);
            }
            $pass = $password !== "" ? $password : bin2hex(random_bytes(8));
            $command .= " --user_pass=" . escapeshellarg($pass) . " --porcelain";
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            if (preg_match("/^\d+$/", $stdout) !== 1) {
                return ["success" => false, "message" => $stdout !== "" ? $stdout : "User creation failed.", "user" => null];
            }
            $userId = (int) $stdout;
            $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1]);
            return [
                "success" => true,
                "message" => "User created via WP Toolkit.",
                "user" => !empty($users["users"][0]) ? $users["users"][0] : ["id" => $userId, "ID" => $userId, "user_login" => $login, "display_name" => $displayName, "user_email" => $email, "roles" => $role !== "" ? [$role] : []],
            ];
        }

        $response = $this->restRequest($target, "post", "users", array_filter([
            "username" => $login,
            "email" => $email,
            "name" => $displayName !== "" ? $displayName : null,
            "roles" => $role !== "" ? [$role] : null,
            "password" => $password !== "" ? $password : null,
        ], static fn ($value) => $value !== null && $value !== [] && $value !== ""));

        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "User creation failed."), "user" => null];
        }

        return ["success" => true, "message" => "User created via REST.", "user" => $this->normalizeUserRow((array) ($response["data"] ?? []))];
    }

    // === deleteUser ===
public function deleteUser(array $target, int $userId, ?int $reassignUserId = null): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required."];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "user delete " . $userId . " --yes";
            if ($reassignUserId !== null && $reassignUserId > 0) {
                $command .= " --reassign=" . $reassignUserId;
            }
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = strtolower(trim((string) ($result["stdout"] ?? "")));
            return [
                "success" => !str_contains($stdout, "error") && !str_contains($stdout, "fatal"),
                "message" => trim((string) ($result["stdout"] ?? "")) ?: "User deleted via WP Toolkit.",
            ];
        }

        $response = $this->restRequest($target, "delete", "users/" . $userId, ["force" => true, "reassign" => $reassignUserId]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "User deleted via REST." : (string) ($response["message"] ?? "User delete failed."),
        ];
    }

    // === generateLoginUrl ===
public function generateLoginUrl(array $target, string $wpUser): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Single-use login URLs are only available through WP Toolkit."];
        }
        if ($wpUser === "") {
            return ["success" => false, "message" => "Missing WordPress username."];
        }

        return $this->wptoolkit->generateWordPressLoginUrl(
            $target["server"],
            (string) $target["wp_path"],
            (string) $target["cpanel_user"],
            $wpUser,
            (string) $target["url"]
        );
    }

    // === getCredentials ===
public function getCredentials(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Stored credentials are only available through WP Toolkit."];
        }

        return $this->wptoolkit->getCredentials(
            $target["server"],
            (int) $target["install_id"],
            (string) $target["wp_path"],
            (string) $target["cpanel_user"],
            (string) $target["login_url"]
        );
    }

    // === getInstallInfo ===
public function getInstallInfo(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Install info is only available through WP Toolkit.", "install" => null];
        }

        $ssh = $this->wptoolkit->getConnection($target["server"]);
        if (empty($ssh["success"]) || empty($ssh["connection"])) {
            return ["success" => false, "message" => (string) ($ssh["error"] ?? "WP Toolkit connection failed."), "install" => null];
        }

        $connection = $ssh["connection"];
        try {
            $command = $this->wptoolkit->shellBinary($connection, $target["server"])
                . " --info -instance-id " . escapeshellarg((string) $target["install_id"])
                . " -format json 2>&1";
            $output = trim((string) $connection->exec($command));
        } catch (\Throwable $e) {
            $this->wptoolkit->disconnectCachedConnection($target["server"]);
            return ["success" => false, "message" => $e->getMessage(), "install" => null];
        }

        $this->wptoolkit->disconnectCachedConnection($target["server"]);
        $jsonStart = null;
        for ($i = 0; $i < strlen($output); $i++) {
            if ($output[$i] === "{" || $output[$i] === "[") {
                $jsonStart = $i;
                break;
            }
        }
        if ($jsonStart === null) {
            return ["success" => false, "message" => "WP Toolkit did not return install JSON.", "install" => null];
        }

        $decoded = json_decode(substr($output, $jsonStart), true);
        if (!is_array($decoded)) {
            return ["success" => false, "message" => "Failed to decode WP Toolkit install info.", "install" => null];
        }
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $decoded = $decoded[0];
        }

        $path = rtrim((string) ($decoded["fullPath"] ?? $decoded["path"] ?? $decoded["documentRoot"] ?? ""), "/");
        $url = rtrim((string) ($decoded["siteUrl"] ?? $decoded["url"] ?? ""), "/");
        if ($path === "" || $url === "") {
            return ["success" => false, "message" => "Install info is missing path or url.", "install" => null];
        }

        $install = [
            "id" => (string) ($decoded["id"] ?? $target["install_id"] ?? ""),
            "name" => (string) ($decoded["name"] ?? ""),
            "path" => $path,
            "url" => $url,
            "login_url" => (string) ($decoded["loginUrl"] ?? ""),
            "version" => (string) ($decoded["version"] ?? $decoded["wpVersion"] ?? ""),
            "admin_user" => (string) ($decoded["adminLogin"] ?? $decoded["adminUser"] ?? ""),
        ];

        return ["success" => true, "message" => "Install info loaded via WP Toolkit.", "install" => $install];
    }

    // === getPostDetailsByIds ===
public function getPostDetailsByIds(array $target, array $postIds): array
    {
        $target = $this->normalizeTarget($target);
        $postIds = array_values(array_unique(array_filter(array_map("intval", $postIds))));
        if ($postIds === []) {
            return ["success" => true, "message" => "No post IDs requested.", "posts" => []];
        }

        if ($this->usesWpToolkit($target)) {
            $posts = [];
            foreach ($postIds as $postId) {
                $php = <<<'PHP'
$postId=__POST_ID__;
$post=get_post((int) $postId);
if (!$post) {
    echo "HEXA_POST_DETAIL:null";
    return;

    // === getUserRole ===
public function getUserRole(array $target, int $userId): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required.", "role" => null, "roles" => []];
        }

        $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1]);
        if (!($users["success"] ?? false) || empty($users["users"][0])) {
            return ["success" => false, "message" => (string) ($users["message"] ?? "User not found."), "role" => null, "roles" => []];
        }

        $roles = array_values(array_map("strval", (array) ($users["users"][0]["roles"] ?? [])));
        return ["success" => true, "message" => "User role loaded.", "role" => $roles[0] ?? null, "roles" => $roles, "user" => $users["users"][0]];
    }

    // === listUsers ===
public function listUsers(array $target, array $filters = []): array
    {
        $target = $this->normalizeTarget($target);
        $filters = [
            "role" => trim((string) ($filters["role"] ?? "")),
            "search" => trim((string) ($filters["search"] ?? "")),
            "include" => array_values(array_unique(array_filter(array_map("intval", (array) ($filters["include"] ?? []))))),
            "per_page" => max(1, (int) ($filters["per_page"] ?? 100)),
        ];

        if ($this->usesWpToolkit($target)) {
            $parts = [
                '$args=["fields"=>["ID","display_name","user_login","user_email","roles"]];',
                'if (' . var_export($filters["role"] !== "", true) . ') { $args["role"]=' . var_export($filters["role"], true) . '; }',
                'if (' . var_export($filters["search"] !== "", true) . ') { $args["search"]=' . var_export($filters["search"] !== "" ? ("*" . $filters["search"] . "*") : "", true) . '; $args["search_columns"]=["user_login","user_email","display_name"]; }',
                'if (' . var_export($filters["include"] !== [], true) . ') { $args["include"]=' . var_export($filters["include"], true) . '; }',
                '$users=get_users($args);',
                '$rows=[];',
                'foreach ($users as $user) { $rows[]=["id"=>(int) $user->ID,"ID"=>(int) $user->ID,"user_login"=>(string) $user->user_login,"display_name"=>(string) $user->display_name,"user_email"=>(string) $user->user_email,"roles"=>array_values(array_map("strval", (array) $user->roles))]; }',
                'echo "HEXA_USER_LIST:" . wp_json_encode($rows);',
            ];
            $eval = $this->evaluatePhp($target, implode("", $parts));
            if (!($eval["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($eval["message"] ?? "User lookup failed."), "users" => []];
            }
            $payload = $this->decodeMarkedPayload((string) ($eval["stdout"] ?? ""), "HEXA_USER_LIST:");
            if (!is_array($payload)) {
                return ["success" => false, "message" => "Failed to parse WP Toolkit user list output.", "users" => []];
            }
            $users = array_values(array_map([$this, "normalizeUserRow"], array_filter($payload, "is_array")));
            return ["success" => true, "message" => count($users) . " user(s) loaded via WP Toolkit.", "users" => $users];
        }

        $query = [
            "per_page" => $filters["per_page"],
            "context" => "edit",
            "_fields" => "id,name,slug,email,roles",
        ];
        if ($filters["role"] !== "") {
            $query["roles"] = $filters["role"];
        }
        if ($filters["search"] !== "") {
            $query["search"] = $filters["search"];
        }
        if ($filters["include"] !== []) {
            $query["include"] = implode(",", $filters["include"]);
        }

        $response = $this->restRequest($target, "get", "users", [], $query);
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "User lookup failed."), "users" => []];
        }

        $users = array_values(array_map([$this, "normalizeUserRow"], array_filter((array) ($response["data"] ?? []), "is_array")));
        return ["success" => true, "message" => count($users) . " user(s) loaded via REST.", "users" => $users];
    }

    // === setUserRole ===
public function setUserRole(array $target, int $userId, string $role): array
    {
        $target = $this->normalizeTarget($target);
        $role = trim($role);
        if ($userId <= 0 || $role === "") {
            return ["success" => false, "message" => "User ID and role are required."];
        }

        if ($this->usesWpToolkit($target)) {
            $command = "user set-role " . $userId . " " . escapeshellarg($role);
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = strtolower(trim((string) ($result["stdout"] ?? "")));
            return [
                "success" => !str_contains($stdout, "error") && !str_contains($stdout, "fatal"),
                "message" => trim((string) ($result["stdout"] ?? "")) ?: "User role updated via WP Toolkit.",
            ];
        }

        return $this->updateUser($target, $userId, ["role" => $role]);
    }

    // === updatePostMeta ===
public function updatePostMeta(array $target, int $postId, array $meta): array
    {
        $target = $this->normalizeTarget($target);
        $meta = array_filter($meta, static fn ($value, $key) => is_string($key) && trim($key) !== "", ARRAY_FILTER_USE_BOTH);
        if ($postId <= 0 || $meta === []) {
            return ["success" => true, "message" => "No post meta changes were needed."];
        }

        if ($this->usesWpToolkit($target)) {
            foreach ($meta as $key => $value) {
                $php = "update_post_meta(" . $postId . ", " . var_export((string) $key, true) . ", " . var_export($value, true) . "); echo \"HEXA_POST_META_OK\";";
                $result = $this->evaluatePhp($target, $php);
                $stdout = trim((string) ($result["stdout"] ?? ""));
                if (!($result["success"] ?? false) || !str_contains($stdout, "HEXA_POST_META_OK")) {
                    return ["success" => false, "message" => trim($stdout) !== "" ? trim($stdout) : ((string) ($result["message"] ?? "Post meta update failed."))];
                }
            }
            return ["success" => true, "message" => count($meta) . " post meta field(s) updated via WP Toolkit."];
        }

        $response = $this->restRequest($target, "post", "posts/" . $postId, ["meta" => $meta]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Post meta updated via REST." : (string) ($response["message"] ?? "Post meta update failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

    // === updateUser ===
public function updateUser(array $target, int $userId, array $payload): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required.", "user" => null];
        }

        $pieces = [];
        $displayName = array_key_exists("display_name", $payload) ? trim((string) ($payload["display_name"] ?? "")) : "";
        $email = array_key_exists("email", $payload) ? trim((string) ($payload["email"] ?? "")) : (array_key_exists("user_email", $payload) ? trim((string) ($payload["user_email"] ?? "")) : "");
        $role = trim((string) ($payload["role"] ?? ""));

        if ($displayName !== "") {
            $pieces[] = "--display_name=" . escapeshellarg($displayName);
        }
        if ($email !== "") {
            $pieces[] = "--user_email=" . escapeshellarg($email);
        }

        if ($this->usesWpToolkit($target)) {
            if ($pieces !== []) {
                $command = "user update " . $userId . " " . implode(" ", $pieces);
                $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
                $stdout = strtolower(trim((string) ($result["stdout"] ?? "")));
                if (str_contains($stdout, "error") || str_contains($stdout, "fatal")) {
                    return ["success" => false, "message" => trim((string) ($result["stdout"] ?? "")) ?: "User update failed.", "user" => null];
                }
            }
            if ($role !== "") {
                $roleResult = $this->setUserRole($target, $userId, $role);
                if (!($roleResult["success"] ?? false)) {
                    return ["success" => false, "message" => (string) ($roleResult["message"] ?? "User role update failed."), "user" => null];
                }
            }
            $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1]);
            return ["success" => true, "message" => "User updated via WP Toolkit.", "user" => $users["users"][0] ?? null];
        }

        $restPayload = [];
        if ($displayName !== "") {
            $restPayload["name"] = $displayName;
        }
        if ($email !== "") {
            $restPayload["email"] = $email;
        }
        if ($role !== "") {
            $restPayload["roles"] = [$role];
        }
        $response = $this->restRequest($target, "post", "users/" . $userId, $restPayload);
        if (!($response["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "User update failed."), "user" => null];
        }

        return ["success" => true, "message" => "User updated via REST.", "user" => $this->normalizeUserRow((array) ($response["data"] ?? []))];
    }


}
