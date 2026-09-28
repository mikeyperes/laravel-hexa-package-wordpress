<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

/**
 * Copies one WordPress user onto another: core fields, every user meta value
 * and roles. WordPress cannot rename a login, so a username change creates a
 * new user and copies the old one onto it. Login sessions and application
 * passwords are never copied.
 *
 * CRITICAL — see BUGLOG.md JOURNALIST-BUG-005: journalist username changes call
 * both methods; a merge on 2026-06-20 dropped them and every change failed.
 */
trait ClonesWordPressUsers
{
    /**
     * @return array{success: bool, message: string, snapshot: array{core: array, meta: array, skipped_meta_keys: array}|null}
     */
    public function exportUserCloneSnapshot(array $target, int $userId): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "A source WordPress user ID is required.", "snapshot" => null];
        }
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Full WordPress user cloning requires WP Toolkit PHP access.", "snapshot" => null];
        }

        $php = str_replace("__USER_ID__", (string) $userId, <<<'PHP'
$userId = __USER_ID__;
$user = get_userdata($userId);
if (!$user instanceof WP_User) {
    echo "HEXA_USER_CLONE_EXPORT:" . wp_json_encode(["success" => false, "message" => "Source WordPress user not found."]);
    return;
}
$skipPatterns = ["/(^|_)session_tokens$/", "/(^|_)application_passwords$/"];
$meta = [];
$skipped = [];
foreach ((array) get_user_meta($userId) as $key => $values) {
    $key = (string) $key;
    foreach ($skipPatterns as $pattern) {
        if (preg_match($pattern, $key)) {
            $skipped[] = $key;
            continue 2;
        }
    }
    $meta[$key] = array_map(static fn ($value) => maybe_unserialize($value), (array) $values);
}
$postCounts = [];
foreach ((array) get_post_types(["public" => true], "names") as $type) {
    $postCounts[(string) $type] = (int) count_user_posts($userId, (string) $type, true);
}
$core = [
    "ID" => (int) $user->ID,
    "user_login" => (string) $user->user_login,
    "user_nicename" => (string) $user->user_nicename,
    "user_email" => (string) $user->user_email,
    "user_url" => (string) $user->user_url,
    "display_name" => (string) $user->display_name,
    "user_registered" => (string) $user->user_registered,
    "user_status" => (int) $user->user_status,
    "first_name" => (string) get_user_meta($userId, "first_name", true),
    "last_name" => (string) get_user_meta($userId, "last_name", true),
    "nickname" => (string) get_user_meta($userId, "nickname", true),
    "description" => (string) get_user_meta($userId, "description", true),
    "roles" => array_values(array_map("strval", (array) $user->roles)),
    "caps" => (array) $user->caps,
    "post_counts" => $postCounts,
];
echo "HEXA_USER_CLONE_EXPORT:" . wp_json_encode(["success" => true, "snapshot" => ["core" => $core, "meta" => $meta, "skipped_meta_keys" => array_values(array_unique($skipped))]]);
PHP);

        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "User clone export failed."), "snapshot" => null];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_CLONE_EXPORT:");
        if (!is_array($payload) || !($payload["success"] ?? false) || !is_array($payload["snapshot"] ?? null)) {
            return ["success" => false, "message" => (string) ($payload["message"] ?? "Failed to parse WordPress user clone export."), "snapshot" => null];
        }

        return ["success" => true, "message" => "WordPress user clone snapshot exported.", "snapshot" => $payload["snapshot"]];
    }

    /**
     * Write a snapshot onto a user. `$overrides` replaces core fields (for
     * example `user_email`); `preserve_user_nicename` keeps the old author URL.
     *
     * @return array{success: bool, message: string, data: array{user?: array, copied_meta_keys?: array, skipped_meta_keys?: array}}
     */
    public function applyUserCloneSnapshot(array $target, int $userId, array $snapshot, array $overrides = []): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "A target WordPress user ID is required.", "data" => []];
        }
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Full WordPress user cloning requires WP Toolkit PHP access.", "data" => []];
        }

        $php = strtr(<<<'PHP'
$targetUserId = __USER_ID__;
$snapshot = __SNAPSHOT__;
$overrides = __OVERRIDES__;
$user = get_userdata($targetUserId);
if (!$user instanceof WP_User) {
    echo "HEXA_USER_CLONE_APPLY:" . wp_json_encode(["success" => false, "message" => "Target WordPress user not found."]);
    return;
}
$core = is_array($snapshot["core"] ?? null) ? $snapshot["core"] : [];
$meta = is_array($snapshot["meta"] ?? null) ? $snapshot["meta"] : [];
$userdata = ["ID" => $targetUserId];
foreach (["user_email", "display_name", "user_url", "first_name", "last_name", "nickname", "description"] as $field) {
    if (array_key_exists($field, $overrides)) {
        $value = $overrides[$field];
    } elseif (array_key_exists($field, $core)) {
        $value = $core[$field];
    } else {
        continue;
    }
    if ($value !== null) {
        $userdata[$field] = is_scalar($value) ? (string) $value : "";
    }
}
if (!empty($overrides["preserve_user_nicename"]) && array_key_exists("user_nicename", $core)) {
    $userdata["user_nicename"] = (string) $core["user_nicename"];
}
if (array_key_exists("user_status", $core)) {
    $userdata["user_status"] = (int) $core["user_status"];
}
$updated = wp_update_user($userdata);
if (is_wp_error($updated)) {
    echo "HEXA_USER_CLONE_APPLY:" . wp_json_encode(["success" => false, "message" => $updated->get_error_message()]);
    return;
}
$copied = [];
foreach ($meta as $key => $values) {
    $key = (string) $key;
    if ($key === "" || preg_match("/(^|_)(session_tokens|application_passwords)$/", $key)) {
        continue;
    }
    delete_user_meta($targetUserId, $key);
    foreach ((array) $values as $value) {
        add_user_meta($targetUserId, $key, $value);
    }
    $copied[] = $key;
}
if (!isset($meta[$GLOBALS["wpdb"]->prefix . "capabilities"]) && !empty($core["roles"]) && is_array($core["roles"])) {
    $wpUser = new WP_User($targetUserId);
    $first = true;
    foreach ($core["roles"] as $role) {
        $role = (string) $role;
        if ($role === "") {
            continue;
        }
        $first ? $wpUser->set_role($role) : $wpUser->add_role($role);
        $first = false;
    }
}
clean_user_cache($targetUserId);
$verified = get_userdata($targetUserId);
echo "HEXA_USER_CLONE_APPLY:" . wp_json_encode([
    "success" => true,
    "data" => [
        "user" => $verified instanceof WP_User ? [
            "id" => (int) $verified->ID,
            "ID" => (int) $verified->ID,
            "user_login" => (string) $verified->user_login,
            "user_nicename" => (string) $verified->user_nicename,
            "display_name" => (string) $verified->display_name,
            "user_email" => (string) $verified->user_email,
            "user_url" => (string) $verified->user_url,
            "roles" => array_values(array_map("strval", (array) $verified->roles)),
        ] : [],
        "copied_meta_keys" => array_values(array_unique($copied)),
        "skipped_meta_keys" => array_values(array_unique((array) ($snapshot["skipped_meta_keys"] ?? []))),
    ],
]);
PHP, [
            "__USER_ID__" => (string) $userId,
            "__SNAPSHOT__" => var_export($snapshot, true),
            "__OVERRIDES__" => var_export($overrides, true),
        ]);

        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "User clone apply failed."), "data" => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_CLONE_APPLY:");
        if (!is_array($payload) || !($payload["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($payload["message"] ?? "Failed to parse WordPress user clone apply."), "data" => []];
        }

        return ["success" => true, "message" => "WordPress user clone snapshot applied.", "data" => is_array($payload["data"] ?? null) ? $payload["data"] : []];
    }
}
