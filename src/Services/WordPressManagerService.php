<?php

namespace hexa_package_wordpress\Services;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
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
        if (is_array($server) && isset($server["id"])) {
            $server = WhmServer::query()->find((int) $server["id"]);
        } elseif (is_object($server) && !($server instanceof WhmServer) && isset($server->id)) {
            $server = WhmServer::query()->find((int) $server->id);
        }
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
            "cpanel_user" => (string) ($target["cpanel_user"] ?? $target["cpanel_username"] ?? ""),
            "wp_path" => trim((string) ($target["wp_path"] ?? $target["wordpress_path"] ?? "public_html"), "/"),
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

    private function toolkitCacheBase(array $target, string $bucket): string
    {
        $target = $this->normalizeTarget($target);
        $server = $target["server"] instanceof WhmServer ? $target["server"] : null;
        $serverKey = $server ? ((string) ($server->id ?: $server->hostname)) : "rest";
        $installKey = (string) ($target["install_id"] ?: "0");

        return "wordpress-manager:" . $bucket . ":server:" . $serverKey . ":install:" . $installKey;
    }

    private function toolkitCacheVersion(array $target, string $bucket): string
    {
        return (string) Cache::get($this->toolkitCacheBase($target, $bucket) . ":version", "1");
    }

    private function toolkitCacheKey(array $target, string $bucket, string $suffix = ""): string
    {
        return $this->toolkitCacheBase($target, $bucket) . ":v" . $this->toolkitCacheVersion($target, $bucket) . ($suffix !== "" ? (":" . $suffix) : "");
    }

    private function bumpToolkitCacheVersion(array $target, string $bucket): void
    {
        Cache::forever($this->toolkitCacheBase($target, $bucket) . ":version", (string) microtime(true));
    }

    private function filterUserRows(array $users, array $filters): array
    {
        $rows = array_values(array_filter($users, "is_array"));
        if ($filters["include"] !== []) {
            $include = array_flip(array_map("intval", $filters["include"]));
            $rows = array_values(array_filter($rows, static fn (array $user): bool => isset($include[(int) ($user["id"] ?? $user["ID"] ?? 0)])));
        }
        if ($filters["role"] !== "") {
            $role = strtolower($filters["role"]);
            $rows = array_values(array_filter($rows, static function (array $user) use ($role): bool {
                $roles = array_map(static fn ($item): string => strtolower((string) $item), (array) ($user["roles"] ?? []));
                return in_array($role, $roles, true);
            }));
        }
        if ($filters["search"] !== "") {
            $needle = strtolower($filters["search"]);
            $rows = array_values(array_filter($rows, static function (array $user) use ($needle): bool {
                $haystack = strtolower(trim(implode(" ", [
                    (string) ($user["user_login"] ?? ""),
                    (string) ($user["user_email"] ?? ""),
                    (string) ($user["display_name"] ?? ""),
                ])));
                return $needle === "" || str_contains($haystack, $needle);
            }));
        }

        return array_slice($rows, 0, $filters["per_page"]);
    }

    private function findExistingUser(array $target, string $login, string $email = "", bool $forceRefresh = false): array|null
    {
        $needles = array_values(array_unique(array_filter([strtolower(trim($login)), strtolower(trim($email))])));
        if ($needles === []) {
            return null;
        }

        foreach ($needles as $needle) {
            $result = $this->listUsers($target, ["search" => $needle, "per_page" => 200, "force_refresh" => $forceRefresh]);
            foreach ((array) ($result["users"] ?? []) as $user) {
                $userLogin = strtolower(trim((string) ($user["user_login"] ?? "")));
                $userEmail = strtolower(trim((string) ($user["user_email"] ?? "")));
                if (($login !== "" && $userLogin === strtolower($login)) || ($email !== "" && $userEmail === strtolower($email))) {
                    return $this->normalizeUserRow((array) $user);
                }
            }
        }

        return null;
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


    public function syncPluginFromGitHub(array $target, array $plugin): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return ['success' => false, 'message' => 'Plugin GitHub sync is only available on WP Toolkit targets.'];
        }

        $slug = trim((string) ($plugin['slug'] ?? $plugin['plugin_directory'] ?? ''), " \t\n\r\0\x0B/");
        $githubUrl = rtrim(trim((string) ($plugin['github_url'] ?? '')), '/');
        $bootstrap = trim((string) ($plugin['bootstrap'] ?? $plugin['bootstrap_file'] ?? 'initialization.php'), " \t\n\r\0\x0B/");
        $cpanelUser = trim((string) ($plugin['cpanel_user'] ?? $target['cpanel_user'] ?? ''));
        $wpPath = trim((string) ($plugin['wp_path'] ?? $plugin['wordpress_path'] ?? $target['wp_path'] ?? 'public_html'), '/');

        if ($cpanelUser === '') {
            return ['success' => false, 'message' => 'cPanel username is required for plugin GitHub sync.'];
        }

        return $this->wptoolkit->syncPluginFromGitHub(
            $target['server'],
            $cpanelUser,
            $wpPath !== '' ? $wpPath : 'public_html',
            $slug,
            $githubUrl,
            $bootstrap !== '' ? $bootstrap : 'initialization.php'
        );
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
                'structures' => $this->acfSmartTypes()->structures(),
                'smart_types' => $this->acfSmartTypes()->smartTypes(),
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
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Failed to inspect ACF field inventory.'), 'groups' => [], 'fields_flat' => [], 'structures' => $this->acfSmartTypes()->structures(), 'smart_types' => $this->acfSmartTypes()->smartTypes()];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_ACF_INVENTORY:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse ACF inventory output.', 'groups' => [], 'fields_flat' => [], 'structures' => $this->acfSmartTypes()->structures(), 'smart_types' => $this->acfSmartTypes()->smartTypes()];
        }

        $groups = $this->annotateAcfGroups(array_values(array_filter((array) ($payload['groups'] ?? []), 'is_array')));
        $fields = $this->annotateAcfFields(array_values(array_filter((array) ($payload['fields_flat'] ?? []), 'is_array')));

        return [
            'success' => (bool) ($payload['success'] ?? false),
            'message' => (string) ($payload['message'] ?? 'ACF field inventory loaded.'),
            'groups' => $groups,
            'fields_flat' => $fields,
            'structures' => $this->acfSmartTypes()->structures(),
            'smart_types' => $this->acfSmartTypes()->smartTypes(),
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
                'field_meta' => [],
                'typed_values' => [],
                'structures' => $this->acfSmartTypes()->structures(),
                'smart_types' => $this->acfSmartTypes()->smartTypes(),
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
                'field_meta' => [],
                'typed_values' => [],
                'structures' => $this->acfSmartTypes()->structures(),
                'smart_types' => $this->acfSmartTypes()->smartTypes(),
            ];
        }

        $parts = [
            '$selector=' . var_export($selector, true) . ';',
            '$fieldNames=' . var_export($fieldNames, true) . ';',
            'if (!function_exists("get_field") || !function_exists("get_field_objects")) { echo "HEXA_ACF_VALUES:" . wp_json_encode(["success"=>false,"message"=>"ACF value APIs are unavailable.","selector"=>$selector,"values"=>[],"available_fields"=>[]]); return; }',
            '$objects=get_field_objects($selector, false, true, false); if (!is_array($objects)) { $objects=[]; }',
            '$fieldMeta=[]; $metaFromField=function(string $fieldName, array $field): array { $subs=[]; if (!empty($field["sub_fields"]) && is_array($field["sub_fields"])) { foreach ($field["sub_fields"] as $sub) { if (!is_array($sub)) { continue; } $subs[]=["field_key"=>(string)($sub["key"] ?? ""),"field_name"=>(string)($sub["name"] ?? ""),"field_label"=>(string)($sub["label"] ?? ""),"field_type"=>(string)($sub["type"] ?? "")]; } } return ["field_key"=>(string)($field["key"] ?? ""),"field_name"=>(string)($field["name"] ?? $fieldName),"field_label"=>(string)($field["label"] ?? ""),"field_type"=>(string)($field["type"] ?? ""),"sub_fields"=>$subs]; };',
            'foreach ($objects as $fieldName => $field) { if (is_array($field)) { $fieldMeta[(string)$fieldName]=$metaFromField((string)$fieldName, $field); } }',
            '$readValue=function(string $fieldName) use ($selector, $objects) { $fieldName=(string)$fieldName; if (str_contains($fieldName,".")) { $nodes=array_values(array_filter(explode(".",$fieldName),"strlen")); if ($nodes===[]) { return null; } $root=array_shift($nodes); $value=get_field($root,$selector,false); foreach ($nodes as $node) { if (!is_array($value) || !array_key_exists($node,$value)) { return null; } $value=$value[$node]; } return $value; } $field=$objects[$fieldName] ?? null; $type=is_array($field) ? (string)($field["type"] ?? "") : ""; $value=is_array($field) && array_key_exists("value", $field) ? $field["value"] : get_field($fieldName, $selector, false); if (($type==="repeater" || $type==="group" || $type==="flexible_content") && !is_array($value)) { $formatted=get_field($fieldName, $selector, true); if (is_array($formatted)) { $value=$formatted; } } return $value; };',
            '$values=[];',
            'if ($fieldNames !== []) { foreach ($fieldNames as $fieldName) { $fieldName=(string)$fieldName; if (!isset($fieldMeta[$fieldName]) && function_exists("get_field_object")) { $single=get_field_object($fieldName, $selector, false, false); if (is_array($single)) { $fieldMeta[$fieldName]=$metaFromField($fieldName, $single); } } $values[$fieldName]=$readValue($fieldName); } } else { foreach ($objects as $fieldName => $field) { $values[(string) $fieldName]=$readValue((string)$fieldName); } }',
            'echo "HEXA_ACF_VALUES:" . wp_json_encode(["success"=>true,"message"=>count($values) . " ACF value(s) loaded.","selector"=>$selector,"values"=>$values,"available_fields"=>array_values(array_map("strval", array_keys($objects))),"field_meta"=>$fieldMeta]);',
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Failed to load ACF values.'), 'selector' => $selector, 'values' => [], 'available_fields' => [], 'field_meta' => [], 'typed_values' => [], 'structures' => $this->acfSmartTypes()->structures(), 'smart_types' => $this->acfSmartTypes()->smartTypes()];
        }

        $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_ACF_VALUES:');
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Failed to parse ACF values output.', 'selector' => $selector, 'values' => [], 'available_fields' => [], 'field_meta' => [], 'typed_values' => [], 'structures' => $this->acfSmartTypes()->structures(), 'smart_types' => $this->acfSmartTypes()->smartTypes()];
        }

        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $fieldMeta = $this->annotateAcfFieldMap(is_array($payload['field_meta'] ?? null) ? $payload['field_meta'] : []);

        return [
            'success' => (bool) ($payload['success'] ?? false),
            'message' => (string) ($payload['message'] ?? 'ACF values loaded.'),
            'selector' => (string) ($payload['selector'] ?? $selector),
            'values' => $values,
            'available_fields' => array_values(array_map('strval', (array) ($payload['available_fields'] ?? []))),
            'field_meta' => $fieldMeta,
            'typed_values' => $this->buildAcfTypedValues($values, $fieldMeta),
            'structures' => $this->acfSmartTypes()->structures(),
            'smart_types' => $this->acfSmartTypes()->smartTypes(),
        ];
    }

    private function acfSmartTypes(): AcfSmartTypeResolver
    {
        return app(AcfSmartTypeResolver::class);
    }

    private function annotateAcfGroups(array $groups): array
    {
        foreach ($groups as $index => $group) {
            if (!is_array($group)) {
                continue;
            }

            $group['fields'] = $this->annotateAcfFields(array_values(array_filter((array) ($group['fields'] ?? []), 'is_array')));
            $groups[$index] = $group;
        }

        return $groups;
    }

    private function annotateAcfFields(array $fields): array
    {
        $resolver = $this->acfSmartTypes();

        return array_map(static function (array $field) use ($resolver): array {
            return $resolver->annotateField($field);
        }, $fields);
    }

    private function annotateAcfFieldMap(array $fieldMeta): array
    {
        $resolver = $this->acfSmartTypes();
        $annotated = [];

        foreach ($fieldMeta as $fieldName => $field) {
            if (!is_array($field)) {
                continue;
            }

            $annotated[(string) $fieldName] = $resolver->annotateField($field);
        }

        return $annotated;
    }

    private function buildAcfTypedValues(array $values, array $fieldMeta): array
    {
        $resolver = $this->acfSmartTypes();
        $typed = [];

        foreach ($values as $fieldName => $value) {
            $fieldName = (string) $fieldName;
            $field = is_array($fieldMeta[$fieldName] ?? null) ? $fieldMeta[$fieldName] : ['field_name' => $fieldName, 'name' => $fieldName];
            $typed[$fieldName] = $resolver->typedValue($fieldName, $value, $field);
        }

        return $typed;
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
            $existing = $this->findExistingUser($target, $login, $email);
            if ($existing) {
                return [
                    "success" => true,
                    "message" => "Existing WordPress user found; assigned it instead of creating a duplicate.",
                    "user" => $existing,
                    "existing" => true,
                    "created" => false,
                ];
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
                $lower = strtolower($stdout);
                if (str_contains($lower, "already registered") || str_contains($lower, "already exists") || str_contains($lower, "existing user")) {
                    $this->bumpToolkitCacheVersion($target, "users");
                    $existing = $this->findExistingUser($target, $login, $email, true);
                    if ($existing) {
                        return [
                            "success" => true,
                            "message" => "Existing WordPress user found after WordPress rejected duplicate creation; assigned it instead.",
                            "user" => $existing,
                            "existing" => true,
                            "created" => false,
                        ];
                    }
                }

                return ["success" => false, "message" => $stdout !== "" ? $stdout : "User creation failed.", "user" => null];
            }
            $userId = (int) $stdout;
            $this->bumpToolkitCacheVersion($target, "users");
            $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1, "force_refresh" => true]);
            return [
                "success" => true,
                "message" => "User created via WP Toolkit.",
                "user" => !empty($users["users"][0]) ? $users["users"][0] : ["id" => $userId, "ID" => $userId, "user_login" => $login, "display_name" => $displayName, "user_email" => $email, "roles" => $role !== "" ? [$role] : []],
                "existing" => false,
                "created" => true,
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
            $command = "user delete " . $userId . " --yes";
            if ($reassignUserId !== null && $reassignUserId > 0) {
                $command .= " --reassign=" . $reassignUserId;
            }
            $result = $this->wptoolkit->wpCliRaw($target["server"], (int) $target["install_id"], $command, 120);
            $stdout = strtolower(trim((string) ($result["stdout"] ?? "")));
            $success = !str_contains($stdout, "error") && !str_contains($stdout, "fatal");
            if ($success) {
                $this->bumpToolkitCacheVersion($target, "users");
            }

            return [
                "success" => $success,
                "message" => trim((string) ($result["stdout"] ?? "")) ?: "User deleted via WP Toolkit.",
            ];
        }

        $response = $this->restRequest($target, "delete", "users/" . $userId, ["force" => true, "reassign" => $reassignUserId]);
        return [
            "success" => (bool) ($response["success"] ?? false),
            "message" => ($response["success"] ?? false) ? "User deleted via REST." : (string) ($response["message"] ?? "User delete failed."),
        ];
    }


    public function recreateUserWithUsername(array $target, int $userId, string $newUsername, array $options = []): array
    {
        $target = $this->normalizeTarget($target);
        $newUsername = trim($newUsername);
        $deleteOld = (bool) ($options["delete_old"] ?? true);
        $acfPaths = array_values(array_filter(array_map("strval", (array) ($options["acf_option_user_fields"] ?? []))));

        if ($userId <= 0) {
            return ["success" => false, "message" => "Current user ID is required.", "user" => null];
        }
        if ($newUsername === "") {
            return ["success" => false, "message" => "New username is required.", "user" => null];
        }
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Username replacement requires WP Toolkit.", "user" => null];
        }

        $parts = [
            "require_once ABSPATH . \"wp-admin/includes/user.php\";",
            "\$oldUserId=" . $userId . ";",
            "\$newUsername=" . var_export($newUsername, true) . ";",
            "\$deleteOld=" . ($deleteOld ? "true" : "false") . ";",
            "\$acfPaths=" . var_export($acfPaths, true) . ";",
            "\$payload=[\"success\"=>false,\"message\"=>\"\",\"old_user_id\"=>\$oldUserId,\"new_user_id\"=>0,\"deleted_old\"=>false,\"acf_updates\"=>[]];",
            "\$old=get_userdata(\$oldUserId); if (!\$old) { \$payload[\"message\"]=\"Current WordPress user was not found.\"; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; }",
            "\$sanitized=sanitize_user(\$newUsername, true); if (\$sanitized === \"\" || \$sanitized !== \$newUsername) { \$payload[\"message\"]=\"Username is not valid for WordPress. Suggested sanitized value: \" . \$sanitized; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; }",
            "if (\$sanitized === (string) \$old->user_login) { \$payload[\"success\"]=true; \$payload[\"message\"]=\"Username is unchanged.\"; \$payload[\"new_user_id\"]=\$oldUserId; \$payload[\"user\"]=[\"id\"=>\$oldUserId,\"ID\"=>\$oldUserId,\"user_login\"=>(string) \$old->user_login,\"display_name\"=>(string) \$old->display_name,\"user_email\"=>(string) \$old->user_email,\"roles\"=>array_values(array_map(\"strval\", (array) \$old->roles))]; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; }",
            "\$existing=username_exists(\$sanitized); if (\$existing && (int) \$existing !== \$oldUserId) { \$payload[\"message\"]=\"Username already exists on the WordPress site.\"; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; }",
            "\$originalEmail=(string) \$old->user_email; if (!is_email(\$originalEmail)) { \$originalEmail=\"user\" . \$oldUserId . \"@example.invalid\"; }",
            "\$emailHolder=email_exists(\$originalEmail); if (\$emailHolder && (int) \$emailHolder !== \$oldUserId) { \$payload[\"message\"]=\"Email address belongs to another WordPress user.\"; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; }",
            "\$archivedEmail=\"\"; if (\$emailHolder && (int) \$emailHolder === \$oldUserId) { \$emailParts=explode(\"@\", \$originalEmail, 2); \$local=preg_replace(\"/[^A-Za-z0-9._+-]/\", \"\", (string) (\$emailParts[0] ?? \"user\")); if (\$local === \"\") { \$local=\"user\" . \$oldUserId; } \$domain=(string) (\$emailParts[1] ?? \"example.invalid\"); \$archivedEmail=\$local . \"+archived-\" . time() . \"-\" . \$oldUserId . \"@\" . \$domain; \$archiveResult=wp_update_user([\"ID\"=>\$oldUserId,\"user_email\"=>\$archivedEmail]); if (is_wp_error(\$archiveResult)) { \$payload[\"message\"]=\$archiveResult->get_error_message(); echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; } }",
            "\$roles=array_values(array_map(\"strval\", (array) \$old->roles)); \$primaryRole=\$roles[0] ?? \"subscriber\";",
            "\$userdata=[\"user_login\"=>\$sanitized,\"user_pass\"=>wp_generate_password(24, true, true),\"user_email\"=>\$originalEmail,\"display_name\"=>(string) \$old->display_name,\"user_url\"=>(string) \$old->user_url,\"first_name\"=>(string) get_user_meta(\$oldUserId, \"first_name\", true),\"last_name\"=>(string) get_user_meta(\$oldUserId, \"last_name\", true),\"description\"=>(string) get_user_meta(\$oldUserId, \"description\", true),\"nickname\"=>(string) get_user_meta(\$oldUserId, \"nickname\", true),\"role\"=>\$primaryRole];",
            "\$newUserId=wp_insert_user(\$userdata); if (is_wp_error(\$newUserId)) { if (\$archivedEmail !== \"\") { wp_update_user([\"ID\"=>\$oldUserId,\"user_email\"=>\$originalEmail]); } \$payload[\"message\"]=\$newUserId->get_error_message(); echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload); return; } \$newUserId=(int) \$newUserId;",
            "\$newWpUser=new WP_User(\$newUserId); foreach (\$roles as \$role) { if (\$role !== \"\") { \$newWpUser->add_role(\$role); } }",
            "\$skip=[\"session_tokens\"=>true,\"wp_capabilities\"=>true,\"wp_user_level\"=>true,\"_application_passwords\"=>true]; \$allMeta=get_user_meta(\$oldUserId); foreach (\$allMeta as \$metaKey=>\$values) { \$metaKey=(string) \$metaKey; if (isset(\$skip[\$metaKey])) { continue; } delete_user_meta(\$newUserId, \$metaKey); foreach ((array) \$values as \$rawValue) { add_user_meta(\$newUserId, \$metaKey, maybe_unserialize(\$rawValue)); } }",
            "\$setAcfPath=function(\$path) use (\$newUserId, &\$payload) { if (!function_exists(\"update_field\")) { \$payload[\"acf_updates\"][]=[\"path\"=>\$path,\"updated\"=>false,\"message\"=>\"ACF unavailable\"]; return; } \$nodes=array_values(array_filter(explode(\".\", (string) \$path), \"strlen\")); if (empty(\$nodes)) { return; } if (count(\$nodes) === 1) { \$updated=update_field(\$nodes[0], \$newUserId, \"option\"); \$payload[\"acf_updates\"][]=[\"path\"=>\$path,\"updated\"=>\$updated !== false]; return; } \$root=array_shift(\$nodes); \$group=get_field(\$root, \"option\"); if (!is_array(\$group)) { \$group=[]; } \$cursor=&\$group; while (count(\$nodes) > 1) { \$node=array_shift(\$nodes); if (!isset(\$cursor[\$node]) || !is_array(\$cursor[\$node])) { \$cursor[\$node]=[]; } \$cursor=&\$cursor[\$node]; } \$cursor[\$nodes[0]]=\$newUserId; \$updated=update_field(\$root, \$group, \"option\"); \$payload[\"acf_updates\"][]=[\"path\"=>\$path,\"updated\"=>\$updated !== false]; };",
            "foreach (\$acfPaths as \$acfPath) { \$setAcfPath(\$acfPath); }",
            "\$deleteOk=true; if (\$deleteOld) { \$deleteOk=wp_delete_user(\$oldUserId, \$newUserId); }",
            "\$newUser=get_userdata(\$newUserId); \$payload[\"success\"]=\$deleteOk !== false; \$payload[\"message\"]=\$deleteOk !== false ? \"Replacement user created and founder reference updated.\" : \"Replacement user was created, but old user deletion failed.\"; \$payload[\"new_user_id\"]=\$newUserId; \$payload[\"deleted_old\"]=\$deleteOld && \$deleteOk !== false; \$payload[\"archived_old_email\"]=\$archivedEmail; \$payload[\"user\"]=[\"id\"=>\$newUserId,\"ID\"=>\$newUserId,\"user_login\"=>(string) \$newUser->user_login,\"display_name\"=>(string) \$newUser->display_name,\"user_email\"=>(string) \$newUser->user_email,\"roles\"=>array_values(array_map(\"strval\", (array) \$newUser->roles))]; echo \"HEXA_USER_RECREATE:\" . wp_json_encode(\$payload);",
        ];

        $result = $this->evaluatePhp($target, implode("", $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "User replacement failed."), "user" => null];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_RECREATE:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse WordPress user replacement output.", "user" => null];
        }

        return [
            "success" => (bool) ($payload["success"] ?? false),
            "message" => (string) ($payload["message"] ?? "User replacement finished."),
            "old_user_id" => (int) ($payload["old_user_id"] ?? $userId),
            "new_user_id" => (int) ($payload["new_user_id"] ?? 0),
            "deleted_old" => (bool) ($payload["deleted_old"] ?? false),
            "acf_updates" => array_values(array_filter((array) ($payload["acf_updates"] ?? []), "is_array")),
            "user" => is_array($payload["user"] ?? null) ? $this->normalizeUserRow((array) $payload["user"]) : null,
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
            "force_refresh" => (bool) ($filters["force_refresh"] ?? false),
        ];

        if ($this->usesWpToolkit($target)) {
            $loader = function () use ($target): array {
                $parts = [
                    '$args=["fields"=>["ID","display_name","user_login","user_email","roles"],"number"=>9999];',
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
                return ["success" => true, "message" => count($users) . " user(s) loaded via WP Toolkit cache source.", "users" => $users];
            };

            $cacheKey = $this->toolkitCacheKey($target, "users");
            if ($filters["force_refresh"]) {
                $all = $loader();
                if ($all["success"] ?? false) {
                    Cache::put($cacheKey, $all, now()->addMinutes(10));
                } else {
                    $cached = Cache::get($cacheKey);
                    if (($cached["success"] ?? false) && is_array($cached["users"] ?? null)) {
                        $cached["stale"] = true;
                        $cached["cached"] = true;
                        $cached["fresh_error"] = (string) ($all["message"] ?? "Fresh user lookup failed.");
                        $cached["message"] = "Fresh WordPress user inventory failed; using the last cached inventory. " . $cached["fresh_error"];
                        $all = $cached;
                    }
                }
            } else {
                $all = Cache::remember($cacheKey, now()->addMinutes(10), $loader);
            }
            if (!($all["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($all["message"] ?? "User lookup failed."), "users" => []];
            }

            $users = $this->filterUserRows((array) ($all["users"] ?? []), $filters);
            return [
                "success" => true,
                "message" => count($users) . " user(s) loaded via WP Toolkit cached inventory.",
                "users" => $users,
                "cached" => !$filters["force_refresh"] || (bool) ($all["stale"] ?? false),
                "stale" => (bool) ($all["stale"] ?? false),
                "fresh_error" => (string) ($all["fresh_error"] ?? ""),
            ];
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
            $success = !str_contains($stdout, "error") && !str_contains($stdout, "fatal");
            if ($success) {
                $this->bumpToolkitCacheVersion($target, "users");
            }

            return [
                "success" => $success,
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
            $this->bumpToolkitCacheVersion($target, "users");
            $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1, "force_refresh" => true]);
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
