<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressAcfMutations
{
    public function updateAcfField(array $target, string $field, mixed $value, string|int $targetRef): array
    {
        $target = $this->normalizeTarget($target);
        $field = trim($field);
        $targetRef = trim((string) $targetRef);

        if ($field === "" || $targetRef === "") {
            return ["success" => false, "message" => "An ACF field and target reference are required."];
        }

        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "ACF field writes require WP Toolkit.", "stored" => null];
        }

        $encodedValue = base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: "null");
        $parts = [
            '$field=' . var_export($field, true) . ';',
            '$targetRef=' . var_export($targetRef, true) . ';',
            '$value=json_decode(base64_decode(' . var_export($encodedValue, true) . '), true);',
            "\$acfBootstrapFiles=[WP_PLUGIN_DIR . '/advanced-custom-fields-pro/acf.php', WP_PLUGIN_DIR . '/advanced-custom-fields/acf.php'];",
            "if (!function_exists('update_field')) { foreach (\$acfBootstrapFiles as \$acfBootstrapFile) { if (file_exists(\$acfBootstrapFile)) { require_once \$acfBootstrapFile; } } }",
            "if (function_exists('acf')) { \$acfApp = acf(); if (is_object(\$acfApp) && method_exists(\$acfApp, 'initialize')) { \$acfApp->initialize(); } }",
            "if (!function_exists('update_field')) { echo 'HEXA_ACF_FIELD_WRITE:' . wp_json_encode(['success'=>false,'message'=>'ACF update_field is unavailable in the WP CLI runtime.','stored'=>null]); return; }",
            "\$updated = update_field(\$field, \$value, \$targetRef);",
            "\$stored = function_exists('get_field') ? get_field(\$field, \$targetRef, false) : null;",
            "\$success = \$updated !== false;",
            "if (!\$success) { if (is_array(\$value)) { \$success = \$stored == \$value; } else { \$success = (string) \$stored === (string) \$value; } }",
            "echo 'HEXA_ACF_FIELD_WRITE:' . wp_json_encode(['success'=>\$success,'message'=>\$success ? 'ACF field updated.' : 'ACF update_field returned false.','field'=>\$field,'target'=>\$targetRef,'stored'=>\$stored]);",
        ];

        $result = $this->evaluatePhp($target, implode('', $parts));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "ACF field write failed."), "stored" => null];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_ACF_FIELD_WRITE:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse ACF field write output.", "stored" => null];
        }

        return $payload;
    }

    public function normalizeAcfMediaIdList(mixed $value): array
    {
        $ids = [];
        $add = static function (mixed $candidate) use (&$ids): void {
            $id = (int) $candidate;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        };
        $collect = function (mixed $item) use (&$collect, $add): void {
            if (is_array($item)) {
                foreach ($item as $value) {
                    $collect($value);
                }
                return;
            }
            if (is_object($item)) {
                foreach (get_object_vars($item) as $value) {
                    $collect($value);
                }
                return;
            }
            if (is_string($item) && preg_match_all('/\d+/', $item, $matches)) {
                foreach ($matches[0] ?? [] as $match) {
                    $add($match);
                }
                return;
            }
            $add($item);
        };

        $collect($value);

        return $ids;
    }

    public function updateAcfGallery(array $target, string $field, string|int $targetRef, array $mediaIds): array
    {
        $ids = $this->normalizeAcfMediaIdList($mediaIds);
        $write = $this->updateAcfField($target, $field, $ids, $targetRef);
        if (!($write["success"] ?? false)) {
            $write["media_ids"] = $ids;
            return $write;
        }

        $storedIds = $this->normalizeAcfMediaIdList($write["stored"] ?? $ids);
        $write["media_ids"] = $storedIds;
        $write["stored"] = $storedIds;
        $write["message"] = "ACF gallery updated.";

        return $write;
    }
}
