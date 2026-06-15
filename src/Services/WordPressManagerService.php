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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "test_write"]);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
            }

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
            '$plugins=$found ? (array) get_plugins("/" . $slug) : [];',
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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->listToolkitAuthorsDirect($target);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
            }

            $result = $this->wptoolkit->wpCliListAdminUsers($target["server"], (int) $target["install_id"], $forceRefresh);
            $result["authors"] = array_values(array_filter((array) ($result["authors"] ?? []), static fn ($author) => is_array($author) && !empty($author["user_login"])));
            return $result;
        }

        $response = $this->restRequest($target, "get", "users", [], [
            "per_page" => 100,
            "context" => "edit",
            "_fields" => "id,name,slug,email,url,roles",
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
            if ($this->isLocalWhmServerTarget($target)) {
                return $this->ensureToolkitTerms($target, $names, $taxonomy);
            }
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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, array_merge($payload, [
                    "action" => "create",
                ]));
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    if (($direct["success"] ?? false) === true) {
                        $direct = $this->attachCachePurgeResult($direct, $this->purgeLocalPostCaches(
                            $target,
                            (int) ($direct["data"]["post_id"] ?? 0),
                            (string) ($direct["data"]["post_url"] ?? "")
                        ));
                    }
                    return $direct;
                }
            }

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
            if (($result["success"] ?? false) && !empty($result["data"]["post_id"])) {
                $result = $this->attachCachePurgeResult($result, $this->purgeLocalPostCaches(
                    $target,
                    (int) ($result["data"]["post_id"] ?? 0),
                    (string) ($result["data"]["post_url"] ?? "")
                ));
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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, array_merge($payload, [
                    "action" => "update",
                    "post_id" => $postId,
                ]));
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    if (($direct["success"] ?? false) === true) {
                        $direct = $this->attachCachePurgeResult($direct, $this->purgeLocalPostCaches(
                            $target,
                            $postId,
                            (string) ($direct["data"]["post_url"] ?? "")
                        ));
                    }
                    return $direct;
                }
            }

            $result = $this->wptoolkit->wpCliUpdatePost($target["server"], (int) $target["install_id"], $postId, $this->buildToolkitPostData($payload));
            if (($result["success"] ?? false) && !empty($payload["taxonomies"])) {
                foreach ((array) $payload["taxonomies"] as $taxonomy => $termIds) {
                    $this->setPostTerms($target, $postId, (string) $taxonomy, (array) $termIds);
                }
            }
            if (($result["success"] ?? false)) {
                $result = $this->attachCachePurgeResult($result, $this->purgeLocalPostCaches(
                    $target,
                    $postId,
                    (string) ($result["data"]["post_url"] ?? "")
                ));
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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->getToolkitPostDirect($target, $postId);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
            }

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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalMediaImport($target, $filePath, $fileName, $altText, $caption, $description);
                $helperMissing = str_contains((string) ($direct["message"] ?? ""), "helper is not installed");
                if (($direct["success"] ?? false) === true || (!$helperMissing && !$this->shouldFallbackFromDirectLocalTransportResult($direct))) {
                    return $direct;
                }
            }

            if (is_file($filePath) && is_readable($filePath)) {
                return $this->wpCliUploadReadableLocalMedia($target, $filePath, $fileName, $altText, $caption, $description);
            }

            return $this->wptoolkit->wpCliUploadMedia($target["server"], (int) $target["install_id"], $filePath, $fileName, $altText, $caption, $description);
        }

        return $this->rest->uploadMedia($target["url"], $target["username"], $target["application_password"], $filePath, $fileName, $altText);
    }

    public function updateMedia(array $target, int $mediaId, array $attributes): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalMediaUpdate($target, $mediaId, $attributes);
                if (($direct['success'] ?? false) === true || !str_contains((string) ($direct['message'] ?? ''), 'helper is not installed')) {
                    return $direct;
                }
            }

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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, [
                    "action" => "delete",
                    "post_id" => $postId,
                    "force" => $force,
                ]);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
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

    public function deleteMedia(array $target, int $mediaId, bool $force = true): array
    {
        $target = $this->normalizeTarget($target);
        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalMediaDelete($target, $mediaId, $force);
                $helperMissing = str_contains((string) ($direct["message"] ?? ""), "helper is not installed");
                if (($direct["success"] ?? false) === true || (!$helperMissing && !$this->shouldFallbackFromDirectLocalTransportResult($direct))) {
                    return $direct;
                }
            }

            return $this->wptoolkit->wpCliDeleteMedia($target["server"], (int) $target["install_id"], $mediaId, $force);
        }

        $response = $this->restRequest($target, "delete", "media/" . $mediaId, ["force" => $force]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Media deleted via REST." : (string) ($response["message"] ?? "Media delete failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

    public function getUserProfile(array $target, int $userId, bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required.", "data" => []];
        }

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
                if (is_array($row)) {
                    $data[(string) ($row["meta_key"] ?? "")] = (string) ($row["meta_value"] ?? "");
                }
            }
            if (empty($data["avatar_url"]) && !empty($data["wp_user_avatars"])) {
                foreach (explode(chr(34), $data["wp_user_avatars"]) as $part) {
                    if (str_starts_with($part, "http")) {
                        $data["avatar_url"] = $part;
                        break;
                    }
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
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required.", "media" => null];
        }
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Profile avatar writes require WP Toolkit.", "media" => null];
        }

        $before = $this->getUserProfile($target, $userId);
        $previous = (int) (($before["data"]["wp_user_avatar"] ?? $before["data"]["avatar_media_id"] ?? 0));
        $mediaId = $mediaId !== null && $mediaId > 0 ? (int) $mediaId : 0;
        $command = $mediaId > 0 ? "user meta update " . $userId . " wp_user_avatar " . $mediaId : "user meta delete " . $userId . " wp_user_avatar";
        $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command);
        $output = strtolower(trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")));
        $failed = !($result["success"] ?? false) || str_contains($output, "error") || str_contains($output, "fatal");

        if ($failed) {
            return [
                "success" => false,
                "message" => trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")) ?: "Profile avatar update failed.",
                "media" => null,
            ];
        }

        if ($mediaId > 0) {
            $url = $this->wpCliAttachmentUrl($target, $mediaId);
            $payload = serialize(["media_id" => $mediaId, "site_id" => 1, "full" => $url, 96 => $url]);
            $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "user meta update " . $userId . " wp_user_avatars " . escapeshellarg($payload));
        } else {
            $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "user meta delete " . $userId . " wp_user_avatars");
        }

        if ($deletePreviousMedia && $previous > 0 && $previous !== $mediaId) {
            $this->deleteMedia($target, $previous, true);
        }

        $profile = $this->getUserProfile($target, $userId);

        return [
            "success" => true,
            "message" => $mediaId > 0 ? "Profile avatar updated via WP Toolkit." : "Profile avatar cleared via WP Toolkit.",
            "media" => ["media_id" => $mediaId, "avatar_url" => (string) ($profile["data"]["avatar_url"] ?? "")],
        ];
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
            return [
                "success" => (bool) ($result["success"] ?? false),
                "message" => (string) ($result["message"] ?? "Post field update finished."),
                "data" => $result["data"] ?? null,
            ];
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
            $output = strtolower(trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")));
            $failed = !($result["success"] ?? false) || str_contains($output, "error") || str_contains($output, "fatal");

            return [
                "success" => !$failed,
                "message" => $failed ? (trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")) ?: "User field update failed.") : "User field updated via WP Toolkit.",
                "data" => null,
            ];
        }

        $payload = $allowed[$field] === "user_email" ? ["email" => $value] : ["meta" => [$field => $value]];
        if ($field === "display_name") {
            $payload = ["name" => $value];
        }

        $response = $this->restRequest($target, "post", "users/" . $objectId, $payload);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "User field updated via REST." : (string) ($response["message"] ?? "User field update failed."),
            "data" => $response["data"] ?? null,
        ];
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
            $output = strtolower(trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")));
            $failed = !($result["success"] ?? false) || str_contains($output, "error") || str_contains($output, "fatal");

            return [
                "success" => !$failed,
                "message" => $failed ? (trim((string) ($result["stdout"] ?? "") . "\n" . (string) ($result["stderr"] ?? "")) ?: "User meta update failed.") : "User meta updated via WP Toolkit.",
            ];
        }

        $response = $this->restRequest($target, "post", "users/" . $userId, ["meta" => [$key => $value]]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "User meta updated via REST." : (string) ($response["message"] ?? "User meta update failed."),
        ];
    }

    public function setPostTerms(array $target, int $postId, string $taxonomy, array $termIds): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";
        $termIds = array_values(array_unique(array_filter(array_map("intval", $termIds))));

        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "set_terms", "post_id" => $postId, "taxonomy" => $taxonomy, "term_ids" => $termIds]);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
            }

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

        if ($this->isLocalWhmServerTarget($target)) {
            $direct = $this->directLocalPhpEval($target, $php);
            $unsupported = str_contains((string) ($direct["message"] ?? ""), "Unsupported direct post action");
            if (($direct["success"] ?? false) === true || (!$unsupported && !$this->shouldFallbackFromDirectLocalTransportResult($direct))) {
                return $direct;
            }
        }

        return $this->wptoolkit->wpCliEval($target["server"], (int) $target["install_id"], $php);
    }

    private function purgeLocalPostCaches(array $target, int $postId = 0, string $postUrl = ""): array
    {
        $target = $this->normalizeTarget($target);
        $postId = max(0, $postId);
        $postUrl = trim($postUrl);

        if (!$this->usesWpToolkit($target)) {
            return ["success" => true, "message" => "No WordPress cache purge needed for REST target."];
        }

        if (!$this->isLocalWhmServerTarget($target)) {
            return ["success" => true, "message" => "No direct WordPress cache purge available for remote WP Toolkit target."];
        }

        if ($postId <= 0 && $postUrl === "") {
            return ["success" => true, "message" => "No WordPress post cache key was available to purge."];
        }

        $php = implode("", [
            '$postId=' . $postId . ';',
            '$url=' . var_export($postUrl, true) . ';',
            '$actions=[];',
            'if ($postId > 0 && function_exists("clean_post_cache")) { clean_post_cache($postId); $actions[]="clean_post_cache"; }',
            'if ($postId > 0) { do_action("litespeed_purge_post", $postId); $actions[]="litespeed_purge_post"; }',
            'if ($url !== "") { do_action("litespeed_purge_url", $url); $actions[]="litespeed_purge_url"; }',
            'do_action("litespeed_purge_all"); $actions[]="litespeed_purge_all";',
            'if (function_exists("rocket_clean_post") && $postId > 0) { rocket_clean_post($postId); $actions[]="rocket_clean_post"; }',
            'if (function_exists("w3tc_flush_post") && $postId > 0) { w3tc_flush_post($postId); $actions[]="w3tc_flush_post"; }',
            'if (function_exists("wp_cache_flush")) { wp_cache_flush(); $actions[]="wp_cache_flush"; }',
            '$cacheRoot=defined("ABSPATH") ? dirname(rtrim((string) ABSPATH, "/")) . "/lscache" : "";',
            '$removed=0;',
            'if ($cacheRoot !== "" && is_dir($cacheRoot) && is_writable($cacheRoot)) {',
            '  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);',
            '  foreach ($it as $item) {',
            '    $path=(string) $item->getPathname();',
            '    if ($item->isFile() || $item->isLink()) { if (@unlink($path)) { $removed++; } continue; }',
            '    if ($item->isDir()) { @rmdir($path); }',
            '  }',
            '  $actions[]="filesystem_lscache_files=" . $removed;',
            '}',
            'echo "HEXA_CACHE_PURGE:" . wp_json_encode(["success"=>true,"post_id"=>$postId,"url"=>$url,"actions"=>$actions]);',
        ]);

        $result = $this->evaluatePhp($target, $php);
        if (($result["success"] ?? false) !== true) {
            return [
                "success" => false,
                "message" => (string) ($result["message"] ?? "WordPress cache purge was not confirmed."),
            ];
        }

        return [
            "success" => true,
            "message" => "WordPress cache purge requested.",
            "stdout" => (string) ($result["stdout"] ?? ""),
        ];
    }

    private function attachCachePurgeResult(array $result, array $purge): array
    {
        if (!is_array($result["data"] ?? null)) {
            $result["data"] = [];
        }

        $result["data"]["cache_purge"] = [
            "success" => (bool) ($purge["success"] ?? false),
            "message" => (string) ($purge["message"] ?? ""),
        ];

        if (($purge["success"] ?? false) !== true) {
            $result["message"] = trim((string) ($result["message"] ?? "Post written.") . " Warning: " . (string) ($purge["message"] ?? "WordPress cache purge was not confirmed."));
        }

        return $result;
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
        $standardKeys = ["title", "content", "status", "excerpt", "date", "featured_media", "featured_media_id", "author", "categories", "category_ids", "tags", "tag_ids", "taxonomies", "post_type", "preserve_content"];
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
            "content" => array_key_exists("content", $payload) ? (string) ($payload["content"] ?? "") : null,
            "preserve_content" => !empty($payload["preserve_content"]),
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
            "post_content" => (string) ($post["content"]["raw"] ?? $post["content"]["rendered"] ?? $post["content"] ?? ""),
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
}
if (!empty($payload["date"])) {
    $post["post_date"] = (string) $payload["date"];
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
echo "HEXA_TOOLKIT_CREATE:" . wp_json_encode([
    "success" => true,
    "data" => [
        "post_id" => (int) $postId,
        "post_url" => (string) (get_permalink($postId) ?: ""),
        "post_status" => (string) get_post_status($postId),
        "post_title" => (string) get_the_title($postId),
        "post_date" => (string) get_post_field("post_date", $postId),
    ],
]);
PHP;
        $php = str_replace("__PAYLOAD__", var_export($payload, true), $php);
        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WP Toolkit post create failed."), "data" => null];
        }
        $parsed = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_TOOLKIT_CREATE:");
        if (!is_array($parsed) || !($parsed["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($parsed["message"] ?? "Failed to parse WP Toolkit post create output."), "data" => null];
        }
        return ["success" => true, "message" => "Post created via WP Toolkit.", "data" => is_array($parsed["data"] ?? null) ? $parsed["data"] : null];
    }

    private function listToolkitAuthorsDirect(array $target): array
    {
        $parts = [
            "\$roles = [\"administrator\", \"editor\", \"author\", \"contributor\"];",
            "\$users = get_users([\"role__in\" => \$roles, \"number\" => 2000, \"orderby\" => \"display_name\", \"order\" => \"ASC\"]);",
            "\$rows = [];",
            "foreach ((array) \$users as \$user) {",
            "if (!\$user instanceof WP_User) { continue; }",
            "\$rows[] = [\"id\" => (int) \$user->ID, \"ID\" => (int) \$user->ID, \"user_login\" => (string) \$user->user_login, \"display_name\" => (string) \$user->display_name, \"email\" => (string) \$user->user_email, \"user_email\" => (string) \$user->user_email, \"slug\" => (string) \$user->user_nicename, \"user_nicename\" => (string) \$user->user_nicename, \"roles\" => array_values(array_map(\"strval\", (array) \$user->roles))];",
            "}",
            "echo \"HEXA_AUTHORS:\" . wp_json_encode([\"success\" => true, \"authors\" => \$rows]);",
        ];
        $php = str_replace("$", "$", implode("", $parts));
        $eval = $this->evaluatePhp($target, $php);
        if (!($eval["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($eval["message"] ?? "Direct author lookup failed."), "authors" => [], "cache_hit" => null, "cached_at" => null, "expires_at" => null];
        }
        $payload = $this->decodeMarkedPayload((string) ($eval["stdout"] ?? ""), "HEXA_AUTHORS:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse direct author lookup output.", "authors" => [], "cache_hit" => null, "cached_at" => null, "expires_at" => null];
        }
        $authors = array_values(array_filter((array) ($payload["authors"] ?? []), static fn ($author) => is_array($author) && !empty($author["user_login"])));
        return ["success" => true, "message" => count($authors) . " author(s) loaded directly from the local WordPress install.", "authors" => $authors, "cache_hit" => false, "cached_at" => null, "expires_at" => null, "mode" => "local"];
    }

    private function getToolkitPostDirect(array $target, int $postId): array
    {
        $parts = [
            "\$postId = __POST_ID__;",
            "\$post = get_post(\$postId);",
            "if (!\$post) { echo \"HEXA_POST_GET:\" . wp_json_encode([\"success\" => false, \"message\" => \"WordPress post not found.\"]); return; }",
            "\$authorId = (int) \$post->post_author;",
            "\$author = \$authorId > 0 ? get_user_by(\"id\", \$authorId) : null;",
            "\$payload = [\"post_id\" => (int) \$post->ID, \"post_url\" => (string) (get_permalink(\$post) ?: \"\"), \"post_status\" => (string) get_post_status(\$post), \"post_title\" => (string) get_the_title(\$post), \"post_date\" => (string) get_post_field(\"post_date\", \$post), \"post_content\" => (string) \$post->post_content, \"author_id\" => \$authorId, \"author_login\" => \$author ? (string) \$author->user_login : \"\", \"author_name\" => \$author ? (string) \$author->display_name : \"\", \"author_url\" => \$author ? (string) get_author_posts_url(\$authorId) : \"\"];",
            "echo \"HEXA_POST_GET:\" . wp_json_encode([\"success\" => true, \"data\" => \$payload]);",
        ];
        $php = str_replace("__POST_ID__", (string) $postId, implode("", $parts));
        $php = str_replace("$", "$", $php);
        $eval = $this->evaluatePhp($target, $php);
        if (!($eval["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($eval["message"] ?? "Direct post fetch failed."), "data" => null];
        }
        $payload = $this->decodeMarkedPayload((string) ($eval["stdout"] ?? ""), "HEXA_POST_GET:");
        if (!is_array($payload) || !($payload["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($payload["message"] ?? "Failed to parse direct post fetch output."), "data" => null];
        }
        return ["success" => true, "message" => "Post fetched directly from the local WordPress install.", "data" => is_array($payload["data"] ?? null) ? $payload["data"] : null];
    }

    private function directLocalMediaUpdate(array $target, int $mediaId, array $attributes): array
    {
        $wrapper = base_path("storage/app/server-tools/hexa-wp-direct-media-local.sh");
        if (!is_file($wrapper) || !is_executable($wrapper)) { return ["success" => false, "message" => "Direct media update helper is not installed or executable."]; }
        $cmd = escapeshellarg($wrapper) . " --instance-id=" . escapeshellarg((string) ($target["install_id"] ?? 0)) . " --media-id=" . escapeshellarg((string) $mediaId) . " --filename=" . escapeshellarg((string) ($attributes["title"] ?? "")) . " --alt=" . escapeshellarg((string) ($attributes["alt_text"] ?? "")) . " --caption=" . escapeshellarg((string) ($attributes["caption"] ?? "")) . " --description=" . escapeshellarg((string) ($attributes["description"] ?? "")) . " 2>&1";
        return $this->runDirectMediaCommand($cmd, "Direct media update");
    }

    private function directLocalMediaImport(array $target, string $source, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $wrapper = base_path("storage/app/server-tools/hexa-wp-direct-media-local.sh");
        if (!is_file($wrapper) || !is_executable($wrapper)) { return ["success" => false, "message" => "Direct media import helper is not installed or executable."]; }
        $source = trim($source);
        if ($source === "") { return ["success" => false, "message" => "Media source is required."]; }
        $cmd = escapeshellarg($wrapper) . " --instance-id=" . escapeshellarg((string) ($target["install_id"] ?? 0));
        $cmd .= filter_var($source, FILTER_VALIDATE_URL) ? " --url=" . escapeshellarg($source) : " --local-path=" . escapeshellarg($source);
        $cmd .= " --filename=" . escapeshellarg($fileName) . " --alt=" . escapeshellarg($altText) . " --caption=" . escapeshellarg($caption) . " --description=" . escapeshellarg($description) . " 2>&1";
        return $this->runDirectMediaCommand($cmd, "Direct media import");
    }

    private function directLocalMediaDelete(array $target, int $mediaId, bool $force = true): array
    {
        $wrapper = base_path("storage/app/server-tools/hexa-wp-direct-media-local.sh");
        if (!is_file($wrapper) || !is_executable($wrapper)) { return ["success" => false, "message" => "Direct media delete helper is not installed or executable."]; }
        $cmd = escapeshellarg($wrapper) . " --instance-id=" . escapeshellarg((string) ($target["install_id"] ?? 0)) . " --delete-media-id=" . escapeshellarg((string) $mediaId) . " --force=" . escapeshellarg($force ? "true" : "false") . " 2>&1";
        return $this->runDirectMediaCommand($cmd, "Direct media delete");
    }

    private function runDirectMediaCommand(string $cmd, string $label): array
    {
        $lines = [];
        $exitCode = 0;
        exec($cmd, $lines, $exitCode);
        $raw = implode("
", $lines);
        $payload = $this->decodeMarkedPayload($raw, "HEXA_MEDIA_IMPORT:");
        if (!is_array($payload)) { return ["success" => false, "message" => $label . " did not return a parseable response: " . substr(trim($raw) ?: "empty output", 0, 500)]; }
        if (($payload["success"] ?? false) !== true) { return ["success" => false, "message" => (string) ($payload["message"] ?? ($label . " failed.")), "data" => $payload]; }
        return ["success" => true, "message" => (string) ($payload["message"] ?? ($label . " completed.")), "data" => $payload];
    }


    public function createOneClickLoginUrl(array $target, string $wpUser, string $siteUrl = "", int $ttl = 300): array
    {
        $target = $this->normalizeTarget($target);
        $wpUser = trim($wpUser);
        if ($wpUser === "") {
            return ["success" => false, "message" => "WordPress user is required for one-click login."];
        }

        if (!$this->usesWpToolkit($target) || !$this->isLocalWhmServerTarget($target)) {
            return ["success" => false, "message" => "One-click WordPress login requires a same-server WP Toolkit target."];
        }

        $direct = $this->directLocalPostWrite($target, [
            "action" => "one_click_login",
            "wp_user" => $wpUser,
            "site_url" => $siteUrl,
            "ttl" => max(60, min(900, $ttl)),
        ]);

        if (!($direct["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($direct["message"] ?? "WordPress login URL could not be generated."),
                "data" => is_array($direct["data"] ?? null) ? $direct["data"] : [],
            ];
        }

        $data = is_array($direct["data"] ?? null) ? $direct["data"] : [];
        return [
            "success" => true,
            "message" => (string) ($direct["message"] ?? "WordPress login URL generated."),
            "url" => (string) ($data["url"] ?? ""),
            "expires_in" => (int) ($data["expires_in"] ?? 300),
            "wp_user" => (string) ($data["wp_user"] ?? $wpUser),
            "data" => $data,
        ];
    }

    private function directLocalPhpEval(array $target, string $php): array
    {
        $direct = $this->directLocalPostWrite($target, [
            "action" => "eval_php",
            "code" => $php,
        ]);

        if (!($direct["success"] ?? false)) {
            $data = is_array($direct["data"] ?? null) ? $direct["data"] : [];
            return [
                "success" => false,
                "message" => (string) ($direct["message"] ?? "Direct PHP evaluation failed."),
                "stdout" => (string) ($data["stdout"] ?? ($direct["stdout"] ?? "")),
            ];
        }

        $data = is_array($direct["data"] ?? null) ? $direct["data"] : [];
        return [
            "success" => true,
            "message" => (string) ($direct["message"] ?? "PHP evaluated directly."),
            "stdout" => (string) ($data["stdout"] ?? ($direct["stdout"] ?? "")),
            "mode" => "local",
        ];
    }

    private function directLocalPostWrite(array $target, array $payload): array
    {
        $wrapper = base_path("storage/app/server-tools/hexa-wp-direct-post-local.sh");
        if (!is_file($wrapper) || !is_executable($wrapper)) {
            return ["success" => false, "message" => "Direct post write helper is not installed or executable."];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ["success" => false, "message" => "Direct post write payload could not be encoded."];
        }

        $cmd = escapeshellarg($wrapper)
            . " --instance-id=" . escapeshellarg((string) ($target["install_id"] ?? 0))
            . " --payload-b64=" . escapeshellarg(base64_encode($json))
            . " 2>&1";

        $lines = [];
        $exitCode = 0;
        exec($cmd, $lines, $exitCode);
        $raw = implode("\n", $lines);
        $response = $this->decodeMarkedPayload($raw, "HEXA_POST_WRITE:");
        if (!is_array($response)) {
            return ["success" => false, "message" => "Direct post write did not return a parseable response: " . substr(trim($raw) ?: "empty output", 0, 500)];
        }

        if (($response["success"] ?? false) !== true) {
            return ["success" => false, "message" => (string) ($response["message"] ?? "Direct post write failed."), "data" => $response];
        }

        return [
            "success" => true,
            "message" => (string) ($response["message"] ?? "Post written directly."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : $response,
        ];
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
        return [
            "id" => (int) ($user["id"] ?? $user["ID"] ?? 0),
            "ID" => (int) ($user["ID"] ?? $user["id"] ?? 0),
            "user_login" => (string) ($user["user_login"] ?? $user["slug"] ?? ""),
            "display_name" => (string) ($user["display_name"] ?? $user["name"] ?? ""),
            "user_email" => (string) ($user["user_email"] ?? $user["email"] ?? ""),
            "user_url" => (string) ($user["user_url"] ?? $user["url"] ?? ""),
            "url" => (string) ($user["url"] ?? $user["user_url"] ?? ""),
            "roles" => array_values(array_map("strval", (array) ($user["roles"] ?? []))),
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
        if (!$this->isLocalWhmServerTarget($target)) {
            return ["success" => false, "message" => "Toolkit local file uploads require a same-server WordPress target."];
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ["success" => false, "message" => "Local media file does not exist or is not readable."];
        }

        return $this->directLocalMediaImport($target, $filePath, $fileName, $altText, $caption, $description);
    }

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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "create_user", "username" => $login, "email" => $email, "display_name" => $displayName, "role" => $role, "password" => $password]);
                if (($direct["success"] ?? false) === true || !$this->shouldFallbackFromDirectLocalUserResult($direct)) {
                    return ["success" => (bool) ($direct["success"] ?? false), "message" => (string) ($direct["message"] ?? "User create finished."), "user" => is_array($direct["data"]["user"] ?? null) ? $direct["data"]["user"] : ($direct["user"] ?? null)];
                }
            }

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

    public function deleteUser(array $target, int $userId, ?int $reassignUserId = null): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "User ID is required."];
        }

        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "delete_user", "user_id" => $userId, "reassign_user_id" => $reassignUserId]);
                if (($direct["success"] ?? false) === true || !$this->shouldFallbackFromDirectLocalUserResult($direct)) {
                    return ["success" => (bool) ($direct["success"] ?? false), "message" => (string) ($direct["message"] ?? "User delete finished.")];
                }
            }

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

    public function getPostDetailsByIds(array $target, array $postIds): array
    {
        $target = $this->normalizeTarget($target);
        $postIds = array_values(array_unique(array_filter(array_map("intval", $postIds))));
        if ($postIds === []) {
            return ["success" => true, "message" => "No post IDs requested.", "posts" => []];
        }

        if ($this->usesWpToolkit($target)) {
            $php = <<<'PHP'
$postIds = __POST_IDS__;
$posts = [];
$imageSizes = array_values(array_unique(array_merge(["full", "large", "medium", "medium_large", "thumbnail"], get_intermediate_image_sizes())));
foreach ((array) $postIds as $rawPostId) {
    $postId = (int) $rawPostId;
    if ($postId <= 0) {
        continue;
    }
    $post = get_post($postId);
    if (!$post) {
        continue;
    }
    $author = get_userdata((int) $post->post_author);
    $featuredId = (int) get_post_thumbnail_id($postId);
    $meta = get_post_meta($postId);
    $flatMeta = [];
    foreach ((array) $meta as $key => $value) {
        $flatMeta[(string) $key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
    }
    $sizes = [];
    if ($featuredId > 0) {
        foreach ($imageSizes as $size) {
            $src = wp_get_attachment_image_src($featuredId, $size);
            if (is_array($src) && !empty($src[0])) {
                $sizes[(string) $size] = [
                    "url" => (string) $src[0],
                    "width" => (int) ($src[1] ?? 0),
                    "height" => (int) ($src[2] ?? 0),
                ];
            }
        }
    }
    $categories = wp_get_post_terms($postId, "category", ["fields" => "names"]);
    if (is_wp_error($categories)) {
        $categories = [];
    }
    $posts[$postId] = [
        "id" => $postId,
        "post_id" => $postId,
        "post_title" => (string) get_the_title($postId),
        "post_name" => (string) $post->post_name,
        "post_status" => (string) $post->post_status,
        "post_date" => (string) $post->post_date,
        "post_modified" => (string) $post->post_modified,
        "permalink" => (string) get_permalink($postId),
        "edit_url" => (string) get_edit_post_link($postId, ""),
        "author_id" => (int) $post->post_author,
        "author_name" => (string) ($author->display_name ?? ""),
        "featured_image_id" => $featuredId > 0 ? $featuredId : null,
        "featured_image_url" => $featuredId > 0 ? (string) wp_get_attachment_url($featuredId) : null,
        "image_sizes" => $sizes,
        "categories" => array_values(array_filter(array_map("strval", (array) $categories))),
        "meta" => $flatMeta,
    ];
}
echo "HEXA_POST_DETAILS:" . wp_json_encode([
    "success" => true,
    "posts" => $posts,
]);
PHP;
            $php = str_replace("__POST_IDS__", var_export($postIds, true), $php);
            $result = $this->evaluatePhp($target, $php);
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($result["message"] ?? "Post detail lookup failed."), "posts" => []];
            }
            $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_POST_DETAILS:");
            if (!is_array($payload) || !($payload["success"] ?? false)) {
                return ["success" => false, "message" => "Failed to parse WP Toolkit post detail output.", "posts" => []];
            }
            $posts = is_array($payload["posts"] ?? null) ? $payload["posts"] : [];
            return ["success" => true, "message" => count($posts) . " post detail row(s) loaded via WP Toolkit.", "posts" => $posts];
        }

        $posts = [];
        foreach ($postIds as $postId) {
            $response = $this->restRequest($target, "get", "posts/" . $postId, [], ["context" => "edit"]);
            if (!($response["success"] ?? false) || !is_array($response["data"] ?? null)) {
                continue;
            }
            $data = (array) $response["data"];
            $posts[$postId] = [
                "id" => $postId,
                "post_id" => $postId,
                "post_title" => (string) (($data["title"]["rendered"] ?? $data["title"] ?? "") ?: ""),
                "post_name" => (string) ($data["slug"] ?? ""),
                "post_status" => (string) ($data["status"] ?? ""),
                "post_date" => (string) ($data["date"] ?? ""),
                "post_modified" => (string) ($data["modified"] ?? ""),
                "permalink" => (string) ($data["link"] ?? ""),
                "edit_url" => "",
                "author_id" => (int) ($data["author"] ?? 0),
                "author_name" => "",
                "featured_image_id" => isset($data["featured_media"]) ? (int) $data["featured_media"] : null,
                "featured_image_url" => "",
                "image_sizes" => [],
                "meta" => (array) ($data["meta"] ?? []),
            ];
        }
        return ["success" => true, "message" => count($posts) . " post detail row(s) loaded via REST.", "posts" => $posts];
    }

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
                '$args=["fields"=>["ID","display_name","user_login","user_email","user_url","roles"]];',
                'if (' . var_export($filters["role"] !== "", true) . ') { $args["role"]=' . var_export($filters["role"], true) . '; }',
                'if (' . var_export($filters["search"] !== "", true) . ') { $args["search"]=' . var_export($filters["search"] !== "" ? ("*" . $filters["search"] . "*") : "", true) . '; $args["search_columns"]=["user_login","user_email","display_name"]; }',
                'if (' . var_export($filters["include"] !== [], true) . ') { $args["include"]=' . var_export($filters["include"], true) . '; }',
                '$users=get_users($args);',
                '$rows=[];',
                'foreach ($users as $user) { $rows[]=["id"=>(int) $user->ID,"ID"=>(int) $user->ID,"user_login"=>(string) $user->user_login,"display_name"=>(string) $user->display_name,"user_email"=>(string) $user->user_email,"user_url"=>(string) $user->user_url,"url"=>(string) $user->user_url,"roles"=>array_values(array_map("strval", (array) $user->roles))]; }',
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
            "_fields" => "id,name,slug,email,url,roles",
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

    public function setUserRole(array $target, int $userId, string $role): array
    {
        $target = $this->normalizeTarget($target);
        $role = trim($role);
        if ($userId <= 0 || $role === "") {
            return ["success" => false, "message" => "User ID and role are required."];
        }

        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "set_user_role", "user_id" => $userId, "role" => $role]);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return ["success" => (bool) ($direct["success"] ?? false), "message" => (string) ($direct["message"] ?? "User role update finished.")];
                }
            }

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

    public function updatePostMeta(array $target, int $postId, array $meta): array
    {
        $target = $this->normalizeTarget($target);
        $meta = array_filter($meta, static fn ($value, $key) => is_string($key) && trim($key) !== "", ARRAY_FILTER_USE_BOTH);
        if ($postId <= 0 || $meta === []) {
            return ["success" => true, "message" => "No post meta changes were needed."];
        }

        if ($this->usesWpToolkit($target)) {
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "update_meta", "post_id" => $postId, "meta" => $meta]);
                if (($direct["success"] ?? false) === true || !str_contains((string) ($direct["message"] ?? ""), "helper is not installed")) {
                    return $direct;
                }
            }

            $php = '$meta = ' . var_export($meta, true) . '; foreach ($meta as $key => $value) { update_post_meta(' . $postId . ', (string) $key, $value); } echo "HEXA_POST_META_OK";';
            $result = $this->evaluatePhp($target, $php);
            $stdout = trim((string) ($result["stdout"] ?? ""));
            if (!($result["success"] ?? false) || !str_contains($stdout, "HEXA_POST_META_OK")) {
                return ["success" => false, "message" => trim($stdout) !== "" ? trim($stdout) : ((string) ($result["message"] ?? "Post meta update failed."))];
            }

            return ["success" => true, "message" => count($meta) . " post meta field(s) updated via one WP Toolkit batch."];
        }

        $response = $this->restRequest($target, "post", "posts/" . $postId, ["meta" => $meta]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "Post meta updated via REST." : (string) ($response["message"] ?? "Post meta update failed."),
            "data" => is_array($response["data"] ?? null) ? $response["data"] : null,
        ];
    }

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
            if ($this->isLocalWhmServerTarget($target)) {
                $direct = $this->directLocalPostWrite($target, ["action" => "update_user", "user_id" => $userId, "display_name" => $displayName, "email" => $email, "role" => $role]);
                if (($direct["success"] ?? false) === true || !$this->shouldFallbackFromDirectLocalUserResult($direct)) {
                    return ["success" => (bool) ($direct["success"] ?? false), "message" => (string) ($direct["message"] ?? "User update finished."), "user" => is_array($direct["data"]["user"] ?? null) ? $direct["data"]["user"] : ($direct["user"] ?? null)];
                }
            }

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

    private function shouldFallbackFromDirectLocalUserResult(array $result): bool
    {
        if (($result["success"] ?? false) === true) {
            return false;
        }

        $message = strtolower((string) ($result["message"] ?? ""));
        if ($message === "") {
            return false;
        }

        if (str_contains($message, "helper is not installed")) {
            return true;
        }

        return $this->shouldFallbackFromDirectLocalTransportResult($result);
    }

    private function shouldFallbackFromDirectLocalTransportResult(array $result): bool
    {
        if (($result["success"] ?? false) === true) {
            return false;
        }

        $message = strtolower((string) ($result["message"] ?? ""));
        if ($message === "") {
            return false;
        }

        foreach ([
            "did not return a parseable response",
            "503 service unavailable",
            "502 bad gateway",
            "504 gateway timeout",
            "maintenance",
            "connection refused",
            "operation timed out",
            "<!doctype html",
            "<html",
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function wpCliUploadReadableLocalMedia(array $target, string $filePath, string $fileName = "", string $altText = "", string $caption = "", string $description = ""): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Local media file uploads require WP Toolkit.", "data" => null];
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            return ["success" => false, "message" => "Local media file does not exist or is not readable.", "data" => null];
        }

        $payload = [
            "filename" => $fileName !== "" ? $fileName : basename($filePath),
            "contents" => base64_encode((string) file_get_contents($filePath)),
            "alt" => $altText,
            "caption" => $caption,
            "description" => $description,
        ];
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $code = implode("", [
            'require_once ABSPATH . "wp-admin/includes/file.php";',
            'require_once ABSPATH . "wp-admin/includes/image.php";',
            '$payload=json_decode(base64_decode(' . var_export($encoded, true) . '), true);',
            'if (!is_array($payload)) { echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>"Invalid local media payload."]); return; }',
            '$bytes=base64_decode((string)($payload["contents"] ?? ""), true);',
            'if ($bytes === false || $bytes === "") { echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>"Local media payload is empty."]); return; }',
            '$uploads=wp_upload_dir();',
            'if (!empty($uploads["error"])) { echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>(string)$uploads["error"]]); return; }',
            '$filename=sanitize_file_name((string)($payload["filename"] ?? ""));',
            'if ($filename === "") { $filename="hexa-upload-" . uniqid() . ".jpg"; }',
            '$filename=wp_unique_filename($uploads["path"], $filename);',
            '$path=trailingslashit($uploads["path"]) . $filename;',
            'if (file_put_contents($path, $bytes) === false) { echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>"Failed to write uploaded file into WordPress uploads."]); return; }',
            '$filetype=wp_check_filetype($filename, null);',
            '$mime=(string)($filetype["type"] ?? "");',
            'if ($mime === "") { @unlink($path); echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>"Unsupported uploaded file type."]); return; }',
            '$attachment=["post_mime_type"=>$mime,"post_title"=>sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),"post_content"=>(string)($payload["description"] ?? ""),"post_excerpt"=>(string)($payload["caption"] ?? ""),"post_status"=>"inherit"];',
            '$mediaId=wp_insert_attachment($attachment, $path);',
            'if (is_wp_error($mediaId)) { @unlink($path); echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>false,"message"=>$mediaId->get_error_message()]); return; }',
            '$metadata=wp_generate_attachment_metadata((int)$mediaId, $path);',
            'if (is_array($metadata)) { wp_update_attachment_metadata((int)$mediaId, $metadata); }',
            '$alt=(string)($payload["alt"] ?? "");',
            'if ($alt !== "") { update_post_meta((int)$mediaId, "_wp_attachment_image_alt", $alt); }',
            '$url=wp_get_attachment_url((int)$mediaId);',
            'echo "HEXA_LOCAL_MEDIA:" . wp_json_encode(["success"=>true,"message"=>"Media uploaded from local file.","media_id"=>(int)$mediaId,"media_url"=>(string)$url,"url"=>(string)$url,"file"=>$path]);',
        ]);

        $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], "eval " . escapeshellarg($code), 120);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WP-CLI local media upload failed."), "data" => null];
        }

        $stdout = (string) ($result["stdout"] ?? "");
        $stderr = (string) ($result["stderr"] ?? "");
        $parsed = $this->decodeMarkedPayload($stdout, "HEXA_LOCAL_MEDIA:");
        if (!is_array($parsed)) {
            $raw = trim($stdout . "\n" . $stderr);
            return [
                "success" => false,
                "message" => "WP-CLI local media upload did not return a parseable response: " . substr($raw !== "" ? $raw : "empty output", 0, 700),
                "data" => ["stdout" => substr($stdout, 0, 2000), "stderr" => substr($stderr, 0, 2000)],
            ];
        }

        if (($parsed["success"] ?? false) !== true) {
            return ["success" => false, "message" => (string) ($parsed["message"] ?? "WP-CLI local media upload failed."), "data" => $parsed];
        }

        $mediaId = (int) ($parsed["media_id"] ?? 0);
        $url = (string) ($parsed["media_url"] ?? $parsed["url"] ?? "");

        return [
            "success" => true,
            "message" => (string) ($parsed["message"] ?? "Media uploaded from local file via WP-CLI."),
            "media_id" => $mediaId,
            "data" => [
                "media_id" => $mediaId,
                "ID" => $mediaId,
                "id" => $mediaId,
                "media_url" => $url,
                "url" => $url,
            ],
        ];
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
