<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressAcf
{
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

}
