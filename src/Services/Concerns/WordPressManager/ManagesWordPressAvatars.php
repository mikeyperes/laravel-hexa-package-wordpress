<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use Illuminate\Support\Facades\Cache;

trait ManagesWordPressAvatars
{
    private function writeUserAvatarPayload(array $target, int $userId, int $mediaId, string $url): array
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target) || $userId <= 0) {
            return ["success" => false, "message" => "A WP Toolkit target and user ID are required."];
        }

        $php = <<<'PHP'
$userId = __USER_ID__;
$mediaId = __MEDIA_ID__;

if (!function_exists("hexa_avatar_payload_url")) {
    function hexa_avatar_payload_url($payload): string {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") break;
            $data = $decoded;
        }
        if (!is_array($data)) return "";
        foreach (["full", 500, "500", 250, "250", 96, "96", "thumbnail"] as $key) {
            if (!empty($data[$key]) && is_string($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_URL)) return $data[$key];
        }
        return "";
    }
}
if (!function_exists("hexa_avatar_payload_media_id")) {
    function hexa_avatar_payload_media_id($payload): int {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") break;
            $data = $decoded;
        }
        return is_array($data) && !empty($data["media_id"]) ? (int) $data["media_id"] : 0;
    }
}
if (!function_exists("hexa_build_legacy_avatar_payload")) {
    function hexa_build_legacy_avatar_payload(int $mediaId, string $fullUrl): array {
        $payload = ["media_id" => $mediaId, "site_id" => get_current_blog_id(), "full" => $fullUrl];
        foreach ([24, 48, 96, 250, 256, 500] as $size) {
            $source = wp_get_attachment_image_src($mediaId, [$size, $size]);
            $payload[$size] = is_array($source) && !empty($source[0]) ? (string) $source[0] : $fullUrl;
        }
        return $payload;
    }
}

PHP;
        $php .= $this->simpleLocalAvatarRuntimePhp();
        $php .= <<<'PHP'

if ($userId <= 0 || !get_userdata($userId)) {
    echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "WordPress user was not found.", "provider" => $provider]);
    return;
}
if ($simpleConfigured && !$simpleAvailable) {
    echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "Simple Local Avatars is active but its assignment API is unavailable.", "provider" => $provider]);
    return;
}

$previousSimple = get_user_meta($userId, "simple_local_avatar", true);
$previousLegacy = get_user_meta($userId, "wp_user_avatars", true);

if ($mediaId > 0) {
    if (!wp_attachment_is_image($mediaId)) {
        echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "WordPress media #" . $mediaId . " is not an image attachment.", "provider" => $provider]);
        return;
    }

    $fullUrl = (string) wp_get_attachment_url($mediaId);
    if ($fullUrl === "" || !filter_var($fullUrl, FILTER_VALIDATE_URL)) {
        echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "WordPress attachment URL could not be resolved.", "provider" => $provider]);
        return;
    }

    update_option("show_avatars", "1");
    if ($simpleConfigured) {
        $simple->assign_new_user_avatar($mediaId, $userId);
        update_user_meta($userId, "simple_local_avatar_rating", "G");
    } else {
        update_user_meta($userId, "wp_user_avatar", $mediaId);
        update_user_meta($userId, "wp_user_avatars", hexa_build_legacy_avatar_payload($mediaId, $fullUrl));
        update_user_meta($userId, "wp_user_avatars_rating", "G");
    }
} else {
    if ($simpleAvailable) $simple->avatar_delete($userId);
    delete_user_meta($userId, "simple_local_avatar");
    delete_user_meta($userId, "simple_local_avatar_rating");
    delete_user_meta($userId, "wp_user_avatar");
    delete_user_meta($userId, "wp_user_avatars");
    delete_user_meta($userId, "wp_user_avatars_rating");
}

$simpleStored = get_user_meta($userId, "simple_local_avatar", true);
$legacyStored = get_user_meta($userId, "wp_user_avatars", true);
$authorUrl = (string) get_author_posts_url($userId);
$avatar96 = "";
$avatar250 = "";
$avatarHtml = "";
try {
    $avatar96 = $mediaId > 0 ? (string) get_avatar_url($userId, ["size" => 96]) : "";
    $avatar250 = $mediaId > 0 ? (string) get_avatar_url($userId, ["size" => 250]) : "";
    $html = $mediaId > 0 ? get_avatar($userId, 250) : "";
    $avatarHtml = is_string($html) ? $html : "";
} catch (Throwable $exception) {
    echo "HEXA_USER_AVATAR:" . wp_json_encode([
        "success" => false,
        "message" => "Avatar provider threw " . get_class($exception) . ": " . $exception->getMessage(),
        "provider" => $provider,
    ]);
    return;
}

$stored = $simpleConfigured ? $simpleStored : $legacyStored;
$storedMediaId = hexa_avatar_payload_media_id($stored);
if (!$simpleConfigured && $storedMediaId <= 0) $storedMediaId = (int) get_user_meta($userId, "wp_user_avatar", true);
$storedUrl = hexa_avatar_payload_url($stored);
$publicAvatarOk = $mediaId <= 0 || (
    filter_var($avatar96, FILTER_VALIDATE_URL)
    && filter_var($avatar250, FILTER_VALIDATE_URL)
    && str_contains($avatar96, "wp-content/uploads/")
    && str_contains($avatar250, "wp-content/uploads/")
    && str_contains($avatarHtml, "<img")
    && str_contains($avatarHtml, "wp-content/uploads/")
);
$storedOk = $mediaId > 0
    ? ($storedMediaId === $mediaId && filter_var($storedUrl, FILTER_VALIDATE_URL))
    : ($storedMediaId === 0 && empty($stored));
$success = $storedOk && $publicAvatarOk;

echo "HEXA_USER_AVATAR:" . wp_json_encode([
    "success" => $success,
    "message" => $success
        ? ($mediaId > 0 ? "Avatar stored and verified through " . $provider . "." : "Avatar cleared and verified.")
        : "Avatar write completed, but the active provider did not return the expected public image.",
    "provider" => $provider,
    "plugin_api_available" => $simpleAvailable,
    "stored_media_id" => $storedMediaId,
    "stored_avatar_url" => $storedUrl,
    "stored_keys" => is_array($stored) ? array_keys($stored) : [],
    "frontend_avatar_url" => $avatar96,
    "admin_avatar_url" => $avatar250,
    "author_url" => $authorUrl,
    "show_avatars" => get_option("show_avatars"),
    "public_avatar_verified" => $publicAvatarOk,
    "previous_simple_present" => !empty($previousSimple),
    "previous_legacy_present" => !empty($previousLegacy),
]);
PHP;

        $php = str_replace(
            ["__USER_ID__", "__MEDIA_ID__"],
            [(string) $userId, (string) $mediaId],
            $php,
        );
        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WordPress avatar provider update failed.")];
        }

        $parsed = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_AVATAR:");

        return is_array($parsed)
            ? $parsed
            : ["success" => false, "message" => "Failed to parse WordPress avatar provider output."];
    }

    private function simpleLocalAvatarRuntimePhp(): string
    {
        return <<<'PHP'
if (!function_exists("hexa_simple_local_avatars_instance")) {
    function hexa_simple_local_avatars_instance(): ?object {
        $candidate = $GLOBALS["simple_local_avatars"] ?? null;
        if (
            is_object($candidate)
            && method_exists($candidate, "assign_new_user_avatar")
            && method_exists($candidate, "avatar_delete")
        ) {
            return $candidate;
        }

        $pluginFile = defined("WP_PLUGIN_DIR")
            ? WP_PLUGIN_DIR . "/simple-local-avatars/simple-local-avatars.php"
            : "";
        if (!class_exists("Simple_Local_Avatars") && $pluginFile !== "" && is_readable($pluginFile)) {
            require_once $pluginFile;
        }

        $candidate = $GLOBALS["simple_local_avatars"] ?? null;
        if (
            is_object($candidate)
            && method_exists($candidate, "assign_new_user_avatar")
            && method_exists($candidate, "avatar_delete")
        ) {
            return $candidate;
        }

        if (class_exists("Simple_Local_Avatars")) {
            try {
                $candidate = new Simple_Local_Avatars();
                if (
                    method_exists($candidate, "assign_new_user_avatar")
                    && method_exists($candidate, "avatar_delete")
                ) {
                    $GLOBALS["simple_local_avatars"] = $candidate;

                    return $candidate;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}

$simple = hexa_simple_local_avatars_instance();
$simpleAvailable = is_object($simple);
$activePlugins = (array) get_option("active_plugins", []);
$networkPlugins = (array) get_site_option("active_sitewide_plugins", []);
$simpleConfigured = $simpleAvailable
    || in_array("simple-local-avatars/simple-local-avatars.php", $activePlugins, true)
    || array_key_exists("simple-local-avatars/simple-local-avatars.php", $networkPlugins);
$provider = $simpleConfigured ? "simple_local_avatars" : "legacy_avatar_meta";
PHP;
    }

    public function activeUserAvatarProvider(array $target, bool $forceRefresh = false): string
    {
        $target = $this->normalizeTarget($target);
        if (!$this->usesWpToolkit($target)) {
            return "wordpress_rest";
        }

        $cacheKey = $this->toolkitCacheBase($target, "avatar-provider");
        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== "") {
                return $cached;
            }
        }

        $php = $this->simpleLocalAvatarRuntimePhp()
            . 'echo "HEXA_AVATAR_PROVIDER:" . wp_json_encode(["provider"=>$provider,"plugin_api_available"=>$simpleAvailable]);';
        $result = $this->evaluatePhp($target, $php);
        $payload = ($result["success"] ?? false)
            ? $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_AVATAR_PROVIDER:")
            : null;
        $provider = is_array($payload) ? (string) ($payload["provider"] ?? "") : "";
        if ($provider === "") {
            $provider = "legacy_avatar_meta";
        }
        Cache::put($cacheKey, $provider, now()->addMinutes(10));

        return $provider;
    }

    public function normalizeUserAvatarForProvider(array $user, string $provider): array
    {
        $payload = $provider === "simple_local_avatars"
            ? ($user["simple_local_avatar"] ?? "")
            : (($user["wp_user_avatars"] ?? "") ?: ($user["avatar_urls"] ?? []));
        $resolved = $this->resolveUserAvatarPayload($payload, 224);
        $url = (string) ($resolved["thumbnail_url"] ?? "");
        $fullUrl = (string) ($resolved["full_url"] ?? "");

        $user["avatar_provider"] = $provider;
        $user["avatar_media_id"] = (string) ((int) ($resolved["media_id"] ?? 0));
        $user["avatar_url"] = $url;
        $user["avatar_thumbnail_url"] = $url;
        $user["avatar_full_url"] = $fullUrl;
        $user["avatar_sizes"] = (array) ($resolved["sizes"] ?? []);

        return $user;
    }

    public function resolveUserAvatarPayload(mixed $payload, int $minimumSize = 224): array
    {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            if (filter_var($data, FILTER_VALIDATE_URL)) {
                return [
                    "url" => $data,
                    "thumbnail_url" => $data,
                    "full_url" => $data,
                    "selected_size" => null,
                    "media_id" => 0,
                    "sizes" => [],
                ];
            }

            $decoded = @unserialize($data, ["allowed_classes" => false]);
            if ($decoded === false && $data !== "b:0;") {
                break;
            }
            $data = $decoded;
        }

        $minimumSize = max(1, $minimumSize);
        $sizes = [];
        $fullUrl = "";
        $mediaId = 0;

        if (is_array($data)) {
            $mediaId = (int) ($data["media_id"] ?? 0);
            foreach ($data as $key => $value) {
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $normalizedKey = strtolower(trim((string) $key));
                if (in_array($normalizedKey, ["full", "original"], true)) {
                    $fullUrl = $value;
                } elseif (ctype_digit($normalizedKey)) {
                    $sizes[(int) $normalizedKey] = $value;
                } elseif ($normalizedKey === "thumbnail") {
                    $sizes[150] = $value;
                }
            }
        }

        ksort($sizes, SORT_NUMERIC);
        $selectedUrl = "";
        $selectedSize = null;
        foreach ($sizes as $size => $url) {
            if ($size >= $minimumSize) {
                $selectedSize = $size;
                $selectedUrl = $url;
                break;
            }
        }
        if ($selectedUrl === "" && $sizes !== []) {
            $selectedSize = (int) array_key_last($sizes);
            $selectedUrl = (string) $sizes[$selectedSize];
        }
        if ($selectedUrl === "") {
            $selectedUrl = $fullUrl;
        }
        if ($fullUrl === "") {
            $fullUrl = $selectedUrl;
        }

        return [
            "url" => $selectedUrl,
            "thumbnail_url" => $selectedUrl,
            "full_url" => $fullUrl,
            "selected_size" => $selectedSize,
            "media_id" => $mediaId,
            "sizes" => $sizes,
        ];
    }

    private function extractUserAvatarUrl(mixed $payload): string
    {
        return (string) ($this->resolveUserAvatarPayload($payload, 96)["url"] ?? "");
    }

    private function extractUserAvatarMediaId(mixed $payload): int
    {
        return (int) ($this->resolveUserAvatarPayload($payload)["media_id"] ?? 0);
    }
}
