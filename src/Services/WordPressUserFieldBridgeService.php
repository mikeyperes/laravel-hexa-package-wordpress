<?php

namespace hexa_package_wordpress\Services;

use hexa_package_notion\Services\NotionService;

class WordPressUserFieldBridgeService
{
    public function __construct(
        protected NotionService $notion,
        protected WordPressManagerService $wordpress,
    ) {
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function payload(array $target, int $userId, string $notionPageId, array $fields, array $context = []): array
    {
        $notionPage = $this->notion->getPage($notionPageId);
        if (!($notionPage["success"] ?? false)) {
            return ["success" => false, "message" => $notionPage["error"] ?? "Failed to load Notion page.", "rows" => []];
        }

        $wp = $this->wordpress->getUserProfile($target, $userId, true);
        if (!($wp["success"] ?? false)) {
            return ["success" => false, "message" => $wp["message"] ?? "Failed to load WordPress user.", "rows" => []];
        }

        $properties = is_array($notionPage["page"]["properties"] ?? null) ? $notionPage["page"]["properties"] : [];
        $wpData = is_array($wp["data"] ?? null) ? $wp["data"] : [];
        $rows = [];

        foreach ($fields as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $key = trim((string) ($definition["key"] ?? $definition["wp_field"] ?? ""));
            $wpField = trim((string) ($definition["wp_field"] ?? ""));
            if ($key === "" || $wpField === "") {
                continue;
            }

            foreach ($this->rowsForDefinition($properties, $wpData, $definition, $key, $wpField, $context) as $row) {
                $rows[] = $row;
            }
        }

        return [
            "success" => true,
            "message" => "Field bridge loaded.",
            "rows" => $rows,
            "context" => $context,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function push(array $target, int $userId, string $notionPageId, array $fields, string $key, string $direction, ?string $overrideValue = null, array $context = []): array
    {
        $payload = $this->payload($target, $userId, $notionPageId, $fields, $context);
        if (!($payload["success"] ?? false)) {
            return $payload;
        }

        $row = null;
        foreach ((array) ($payload["rows"] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate["key"] ?? "") === $key) {
                $row = $candidate;
                break;
            }
        }

        if (!$row) {
            return ["success" => false, "message" => "Unknown user field bridge row."];
        }

        $verifiedWpKey = "";
        $verifiedWpLabel = "";
        $verifiedWpValue = null;

        if ($direction === "notion_to_wp") {
            if (!($row["can_write_wp"] ?? false)) {
                return ["success" => false, "message" => (string) ($row["wp_disabled_reason"] ?? "This WordPress field cannot be written from Notion.")];
            }

            $value = $overrideValue !== null ? $overrideValue : (string) ($row["notion_value"] ?? "");
            $value = $this->transformValueForWordPress($value, (string) ($row["source_transform"] ?? ""));
            $result = $this->writeWordPressValue($target, $userId, $row, $value);
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => $result["message"] ?? "WordPress field update failed."];
            }

            $verifiedWpKey = (string) ($row["key"] ?? $key);
            $verifiedWpLabel = (string) ($row["label"] ?? $key);
            $verifiedWpValue = $value;
        } elseif ($direction === "wp_to_notion") {
            if (!($row["can_write_notion"] ?? false)) {
                return ["success" => false, "message" => (string) ($row["notion_disabled_reason"] ?? "This Notion field cannot be written from WordPress.")];
            }

            $value = $overrideValue !== null ? $overrideValue : (string) ($row["wp_value"] ?? "");
            $result = $this->notion->updatePageProperty($notionPageId, (string) ($row["notion_field"] ?? ""), $value);
            if (!($result["success"] ?? false)) {
                return ["success" => false, "message" => $result["error"] ?? "Notion field update failed."];
            }
        } else {
            return ["success" => false, "message" => "Unsupported bridge direction."];
        }

        $fresh = $this->payload($target, $userId, $notionPageId, $fields, $context);
        if ($verifiedWpKey !== "" && ($fresh["success"] ?? false)) {
            $verifiedRow = null;
            foreach ((array) ($fresh["rows"] ?? []) as $candidate) {
                if (is_array($candidate) && (string) ($candidate["key"] ?? "") === $verifiedWpKey) {
                    $verifiedRow = $candidate;
                    break;
                }
            }

            $actualValue = is_array($verifiedRow) ? (string) ($verifiedRow["wp_value"] ?? "") : "";
            $expectedValue = (string) $verifiedWpValue;
            if (!$verifiedRow || $this->normalizeComparableValue($actualValue) !== $this->normalizeComparableValue($expectedValue)) {
                $fresh["success"] = false;
                $fresh["message"] = "WordPress write did not verify for " . $verifiedWpLabel . ". Expected " . $this->valuePreview($expectedValue) . "; read back " . $this->valuePreview($actualValue) . ".";
                return $fresh;
            }
        }

        $fresh["message"] = "Field value pushed.";

        return $fresh;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $wpData
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    protected function rowsForDefinition(array $properties, array $wpData, array $definition, string $key, string $wpField, array $context = []): array
    {
        $wpValue = $this->readWordPressValue($wpData, $definition);
        $targets = $this->expandedNotionTargets($properties, $definition);
        $rows = [];
        $isExpanded = (bool) ($definition["expand_notion_fields"] ?? false);
        $isPhotoBridge = (bool) ($definition["photo_bridge"] ?? false) || (string) ($definition["wp_type"] ?? "native") === "profile_photo";

        foreach ($targets as $index => $target) {
            $notionField = (string) ($target["field"] ?? "");
            $notionLabel = (string) ($target["label"] ?? "");
            $rowKey = $isExpanded ? $key . ":" . $this->rowKeySegment($notionLabel !== "" ? $notionLabel : ($notionField !== "" ? $notionField : (string) $index)) : $key;
            $notionValue = $notionField !== "" ? $this->stringValue($properties[$notionField] ?? "") : "";
            $canWriteWp = $this->canWriteWordPress($definition, $notionField);
            $canWriteNotion = $this->canWriteNotion($definition, $notionField);
            $baseLabel = (string) ($definition["label"] ?? $key);
            $sourceTransform = (string) ($definition["source_transform"] ?? "");
            $wpProposedValue = "";
            $wpProposalReason = "";

            if ($canWriteWp && $wpField === "user_email" && trim($wpValue) === "") {
                $candidate = $this->suggestedPublicationEmail($properties, $context);
                if ($candidate !== "") {
                    $wpProposedValue = $candidate;
                    $wpProposalReason = "Suggested from the journalist name and publication domain because the WordPress email is empty.";
                }
            }

            if ($canWriteWp && $wpProposedValue === "" && $notionValue !== "") {
                $candidate = $this->transformValueForWordPress($notionValue, $sourceTransform);
                if ($candidate !== "" && $this->normalizeComparableValue($candidate) !== $this->normalizeComparableValue($wpValue)) {
                    $wpProposedValue = $candidate;
                    $sourceLabel = $notionField !== "" ? $notionField : ($notionLabel !== "" ? $notionLabel : "Notion");
                    $wpProposalReason = $sourceTransform !== ""
                        ? "Derived from " . $sourceLabel . " using the " . str_replace("_", " ", $sourceTransform) . " transform."
                        : "Notion and WordPress values differ.";
                }
            }

            $rows[] = [
                "key" => $rowKey,
                "base_key" => $key,
                "label" => $isExpanded && $notionLabel !== "" ? $baseLabel . " - " . $notionLabel : $baseLabel,
                "notion_label" => $notionLabel,
                "notion_field" => $notionField,
                "notion_fields" => array_values(array_map("strval", (array) ($target["fields"] ?? ($definition["notion_fields"] ?? [])))),
                "notion_value" => $notionValue,
                "wp_field" => $wpField,
                "wp_type" => (string) ($definition["wp_type"] ?? "native"),
                "wp_page" => (string) ($definition["wp_page"] ?? "profile.php"),
                "wp_value" => $wpValue,
                "wp_proposed_value" => $wpProposedValue,
                "wp_proposal_reason" => $wpProposalReason,
                "wp_proposal_target" => $wpProposedValue !== "" ? "wordpress" : "",
                "can_write_wp" => $canWriteWp,
                "can_write_notion" => $canWriteNotion,
                "wp_disabled_reason" => $canWriteWp ? "" : $this->disabledReason($definition, "notion_to_wp", $notionField),
                "notion_disabled_reason" => $canWriteNotion ? "" : $this->disabledReason($definition, "wp_to_notion", $notionField),
                "source_transform" => $sourceTransform,
                "is_photo_bridge" => $isPhotoBridge,
                "photo_upload" => $isPhotoBridge && (bool) ($definition["photo_upload"] ?? true),
                "photo_role" => $notionLabel,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $definition
     * @return array<int, array{field: string, fields: array<int, string>, label: string}>
     */
    protected function expandedNotionTargets(array $properties, array $definition): array
    {
        $showMissing = (bool) ($definition["show_missing_notion_fields"] ?? false);
        if (!($definition["expand_notion_fields"] ?? false)) {
            $fields = array_values(array_map("strval", (array) ($definition["notion_fields"] ?? [])));
            $field = $this->firstExistingField($properties, $fields);
            return [["field" => $field, "fields" => $fields, "label" => ""]];
        }

        $groups = is_array($definition["notion_field_groups"] ?? null) ? $definition["notion_field_groups"] : [];
        if ($groups === []) {
            foreach ((array) ($definition["notion_fields"] ?? []) as $field) {
                $field = (string) $field;
                if ($field !== "") {
                    $groups[] = ["label" => $field, "fields" => [$field]];
                }
            }
        }

        $targets = [];
        foreach ($groups as $index => $group) {
            if (is_array($group)) {
                $label = trim((string) ($group["label"] ?? ""));
                $fields = array_values(array_map("strval", (array) ($group["fields"] ?? [])));
            } else {
                $label = trim((string) $group);
                $fields = [$label];
            }

            $fields = array_values(array_filter($fields, static fn (string $field): bool => trim($field) !== ""));
            $field = $this->firstExistingField($properties, $fields);
            if ($field === "" && !$showMissing) {
                continue;
            }

            $targets[] = [
                "field" => $field,
                "fields" => $fields,
                "label" => $label !== "" ? $label : ($field !== "" ? $field : "Field " . ((int) $index + 1)),
            ];
        }

        return $targets;
    }

    protected function rowKeySegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace("/[^a-z0-9]+/", "_", $value) ?? $value;
        return trim($value, "_") ?: "field";
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function writeWordPressValue(array $target, int $userId, array $row, string $value): array
    {
        $wpType = (string) ($row["wp_type"] ?? "native");
        $wpField = (string) ($row["wp_field"] ?? "");

        if ($wpType === "native") {
            return $this->wordpress->updateNativeField($target, "user", $userId, $wpField, $value);
        }

        if ($wpType === "usermeta") {
            return $this->wordpress->updateUserMeta($target, $userId, $wpField, $value);
        }

        return ["success" => false, "message" => "Unsupported WordPress user field type for direct bridge write: " . $wpType];
    }

    /**
     * @param array<string, mixed> $wpData
     * @param array<string, mixed> $definition
     */
    protected function readWordPressValue(array $wpData, array $definition): string
    {
        $field = (string) ($definition["wp_field"] ?? "");
        $type = (string) ($definition["wp_type"] ?? "native");

        if ($type === "profile_photo") {
            return $this->stringValue($wpData["avatar_url"] ?? $wpData["wp_user_avatars"] ?? "");
        }

        if ($type === "profile_photo_meta") {
            return $this->stringValue($wpData["avatar_media_id"] ?? $wpData["wp_user_avatar"] ?? "");
        }

        return $this->stringValue($wpData[$field] ?? ($field === "user_email" ? ($wpData["email"] ?? "") : ""));
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function canWriteWordPress(array $definition, string $notionField): bool
    {
        if ($notionField === "") {
            return false;
        }

        if (array_key_exists("notion_to_wp", $definition) && !$definition["notion_to_wp"]) {
            return false;
        }

        return in_array((string) ($definition["wp_type"] ?? "native"), ["native", "usermeta"], true);
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function canWriteNotion(array $definition, string $notionField): bool
    {
        if ($notionField === "") {
            return false;
        }

        if (array_key_exists("wp_to_notion", $definition) && !$definition["wp_to_notion"]) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function disabledReason(array $definition, string $direction, string $notionField): string
    {
        if ($direction === "notion_to_wp" && isset($definition["notion_to_wp_reason"])) {
            return (string) $definition["notion_to_wp_reason"];
        }

        if ($direction === "wp_to_notion" && isset($definition["wp_to_notion_reason"])) {
            return (string) $definition["wp_to_notion_reason"];
        }

        if ($notionField === "") {
            return "No matching Notion field is configured for this WordPress field.";
        }

        $type = (string) ($definition["wp_type"] ?? "native");
        if ($direction === "notion_to_wp" && !in_array($type, ["native", "usermeta"], true)) {
            return "This WordPress field type requires a dedicated tool instead of a direct text write.";
        }

        return "This field direction is not available.";
    }


    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     */
    protected function suggestedPublicationEmail(array $properties, array $context): string
    {
        $domain = trim((string) ($context["publication_domain"] ?? ""));
        if ($domain === "") {
            $url = trim((string) ($context["publication_url"] ?? ""));
            $domain = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        $domain = preg_replace("/^www\./i", "", trim($domain)) ?: "";
        $domain = mb_strtolower($domain);
        $domain = preg_replace("/[^a-z0-9.-]+/", "", $domain) ?: "";
        if ($domain === "" || !str_contains($domain, ".")) {
            return "";
        }

        $nameField = $this->firstExistingField($properties, ["Full Name", "Name"]);
        $name = $nameField !== "" ? $this->stringValue($properties[$nameField] ?? "") : "";
        if ($name === "") {
            $name = trim((string) ($context["profile_name"] ?? ""));
        }

        [$first, $last] = $this->splitName($name);
        $first = $this->emailNameSegment($first);
        $last = $this->emailNameSegment($last);
        if ($first === "") {
            return "";
        }

        return ($last !== "" ? $first . "." . $last : $first) . "@" . $domain;
    }

    protected function emailNameSegment(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $ascii = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);
        if (is_string($ascii) && $ascii !== "") {
            $value = $ascii;
        }

        $value = preg_replace("/[^a-z0-9]+/", ".", $value) ?: "";
        return trim($value, ".");
    }

    protected function normalizeComparableValue(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace("/\r\n/u", "\n", $value) ?? $value;
        $value = preg_replace("/[ \t]+/u", " ", $value) ?? $value;

        return trim($value);
    }

    protected function valuePreview(string $value): string
    {
        $value = trim((string) (preg_replace("/\s+/u", " ", $value) ?? $value));
        if ($value === "") {
            return "Empty";
        }

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 90) . "..." : $value;
    }

    protected function transformValueForWordPress(string $value, string $transform): string
    {
        $value = trim($value);
        if ($transform === "first_name") {
            return $this->splitName($value)[0];
        }

        if ($transform === "last_name") {
            return $this->splitName($value)[1];
        }

        if ($transform === "verified_boolean") {
            return $this->verifiedBooleanValue($value);
        }

        return $value;
    }

    protected function verifiedBooleanValue(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === "") {
            return "0";
        }

        $negativeValues = ["0", "false", "no", "none", "n/a", "na", "not verified", "unverified"];
        if (in_array($normalized, $negativeValues, true)) {
            return "0";
        }

        if (preg_match("/\b(unverified|not\s+verified|false|none|n\/?a)\b/u", $normalized)) {
            return "0";
        }

        return "1";
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split("/\s+/u", trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ""));
        if ($parts === []) {
            return ["", ""];
        }

        if (count($parts) === 1) {
            return [$parts[0], ""];
        }

        return [$parts[0], implode(" ", array_slice($parts, 1))];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, mixed> $fields
     */
    protected function firstExistingField(array $properties, array $fields): string
    {
        foreach ($fields as $field) {
            $field = (string) $field;
            if ($field !== "" && array_key_exists($field, $properties)) {
                return $field;
            }
        }

        $normalized = [];
        foreach (array_keys($properties) as $name) {
            $normalized[$this->normalizeFieldName((string) $name)] = (string) $name;
        }

        foreach ($fields as $field) {
            $key = $this->normalizeFieldName((string) $field);
            if ($key !== "" && isset($normalized[$key])) {
                return $normalized[$key];
            }
        }

        return "";
    }

    protected function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(", ", array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_SLASHES), $value));
        }

        return trim((string) ($value ?? ""));
    }

    protected function normalizeFieldName(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = preg_replace("/[^\p{L}\p{N}]+/u", " ", $value) ?? $value;
        $value = preg_replace("/\s+/u", " ", $value) ?? $value;

        return trim($value);
    }
}
