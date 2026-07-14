<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
$url = __URL__;

if (!function_exists("hexa_avatar_payload_url")) {
    function hexa_avatar_payload_url($payload): string {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") {
                break;
            }
            $data = $decoded;
        }
        if (is_array($data)) {
            foreach (["full", 250, "250", 500, "500", 96, "96", 256, "256", "thumbnail"] as $key) {
                if (!empty($data[$key]) && is_string($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_URL)) {
                    return $data[$key];
                }
            }
        }
        return "";
    }
}

if (!function_exists("hexa_avatar_payload_media_id")) {
    function hexa_avatar_payload_media_id($payload): int {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") {
                break;
            }
            $data = $decoded;
        }
        return is_array($data) && !empty($data["media_id"]) ? (int) $data["media_id"] : 0;
    }
}

if (!function_exists("hexa_build_legacy_avatar_payload")) {
    function hexa_build_legacy_avatar_payload(int $mediaId, string $fullUrl): array {
        $payload = [
            "media_id" => $mediaId,
            "site_id" => get_current_blog_id(),
            "full" => $fullUrl,
        ];

        foreach ([96, 250, 256, 500] as $size) {
            $source = wp_get_attachment_image_src($mediaId, [$size, $size]);
            $payload[$size] = is_array($source) && !empty($source[0]) && filter_var($source[0], FILTER_VALIDATE_URL)
                ? $source[0]
                : $fullUrl;
        }

        return $payload;
    }
}

$simpleLocalAvatars = $GLOBALS["simple_local_avatars"] ?? null;
$simpleLocalAvatarsAvailable = is_object($simpleLocalAvatars)
    && method_exists($simpleLocalAvatars, "assign_new_user_avatar")
    && method_exists($simpleLocalAvatars, "avatar_delete");
$activePlugins = (array) get_option("active_plugins", []);
$networkActivePlugins = (array) get_site_option("active_sitewide_plugins", []);
$simpleLocalAvatarsActive = $simpleLocalAvatarsAvailable
    || in_array("simple-local-avatars/simple-local-avatars.php", $activePlugins, true)
    || array_key_exists("simple-local-avatars/simple-local-avatars.php", $networkActivePlugins);
$provider = $simpleLocalAvatarsActive ? "simple_local_avatars" : "wp_user_avatars";

if ($userId <= 0 || !get_userdata($userId)) {
    echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "WordPress user was not found."]);
    return;
}

if ($mediaId > 0) {
    if (!wp_attachment_is_image($mediaId)) {
        echo "HEXA_USER_AVATAR:" . wp_json_encode(["success" => false, "message" => "WordPress media #" . $mediaId . " is not an image attachment."]);
        return;
    }

    $fullUrl = wp_get_attachment_url($mediaId);
    if (empty($fullUrl) || !filter_var($fullUrl, FILTER_VALIDATE_URL)) {
        $fullUrl = $url;
    }

    $legacyPayload = hexa_build_legacy_avatar_payload($mediaId, $fullUrl);

    update_option("show_avatars", "1");

    if ($simpleLocalAvatarsAvailable) {
        $simpleLocalAvatars->assign_new_user_avatar($mediaId, $userId);
    }
    if ($simpleLocalAvatarsActive) {
        $simplePayload = get_user_meta($userId, "simple_local_avatar", true);
        if (!is_array($simplePayload)) {
            $simplePayload = [];
        }
        $simplePayload["media_id"] = $mediaId;
        $simplePayload["full"] = $fullUrl;
        $simplePayload["blog_id"] = get_current_blog_id();
        foreach ([24, 48, 96, 250, 256, 500] as $size) {
            $source = wp_get_attachment_image_src($mediaId, [$size, $size]);
            $simplePayload[$size] = is_array($source) && !empty($source[0]) && filter_var($source[0], FILTER_VALIDATE_URL)
                ? $source[0]
                : $fullUrl;
        }
        update_user_meta($userId, "simple_local_avatar", $simplePayload);
        update_user_meta($userId, "simple_local_avatar_rating", "G");
    }

    update_user_meta($userId, "wp_user_avatar", $mediaId);
    update_user_meta($userId, "wp_user_avatars", $legacyPayload);
    if (!get_user_meta($userId, "wp_user_avatars_rating", true)) {
        update_user_meta($userId, "wp_user_avatars_rating", "G");
    }
} else {
    if ($simpleLocalAvatarsAvailable) {
        $simpleLocalAvatars->avatar_delete($userId);
    }
    delete_user_meta($userId, "simple_local_avatar");
    delete_user_meta($userId, "simple_local_avatar_rating");
    delete_user_meta($userId, "wp_user_avatar");
    delete_user_meta($userId, "wp_user_avatars");
    delete_user_meta($userId, "wp_user_avatars_rating");
}

$simpleStored = get_user_meta($userId, "simple_local_avatar", true);
$legacyStored = get_user_meta($userId, "wp_user_avatars", true);
$stored = $simpleLocalAvatarsActive ? $simpleStored : $legacyStored;
$storedMediaId = hexa_avatar_payload_media_id($simpleStored) ?: (int) get_user_meta($userId, "wp_user_avatar", true);
$storedUrl = hexa_avatar_payload_url($stored);

$avatar96 = "";
$avatar250 = "";
$avatar250Error = "";
$avatar250Html = "";
$avatar250HtmlOk = false;
try {
    $avatar96 = $mediaId > 0 ? (string) get_avatar_url($userId, ["size" => 96]) : "";
} catch (Throwable $exception) {
    $avatar96 = "";
}
try {
    $avatar250 = $mediaId > 0 ? (string) get_avatar_url($userId, ["size" => 250]) : "";
    $avatar250HtmlRaw = $mediaId > 0 ? get_avatar($userId, 250) : "";
    $avatar250Html = is_string($avatar250HtmlRaw) ? $avatar250HtmlRaw : "";
    $avatar250HtmlOk = $avatar250Html !== "" && str_contains($avatar250Html, "<img") && str_contains($avatar250Html, "wp-content/uploads/");
} catch (Throwable $exception) {
    $avatar250Error = get_class($exception) . ": " . $exception->getMessage();
}

$simpleStoredOk = $mediaId <= 0 || !$simpleLocalAvatarsActive || (
    is_array($simpleStored)
    && !empty($simpleStored["full"])
    && !empty($simpleStored["media_id"])
    && (int) $simpleStored["media_id"] === $mediaId
);
$legacyStoredOk = $mediaId <= 0 || (
    is_array($legacyStored)
    && !empty($legacyStored["full"])
    && !empty($legacyStored[96])
    && !empty($legacyStored[250])
    && !empty($legacyStored[256])
    && !empty($legacyStored[500])
);
$clearedOk = $mediaId > 0 || (
    $storedMediaId === 0
    && empty($simpleStored)
    && empty($legacyStored)
    && get_user_meta($userId, "wp_user_avatar", true) === ""
);
$hasRequiredSizes = $mediaId <= 0 || (
    is_array($stored)
    && !empty($stored["full"])
);
$frontendAvatarCheckOk = !$simpleLocalAvatarsAvailable || (
    $avatar250 !== ""
    && $avatar250Error === ""
    && $avatar250HtmlOk
);

echo "HEXA_USER_AVATAR:" . wp_json_encode([
    "success" => $mediaId > 0
        ? ($storedMediaId === $mediaId && $simpleStoredOk && $legacyStoredOk && $hasRequiredSizes && $storedUrl !== "" && $frontendAvatarCheckOk)
        : $clearedOk,
    "message" => "WordPress user avatar meta updated.",
    "provider" => $provider,
    "stored_media_id" => $storedMediaId,
    "stored_type" => gettype($stored),
    "stored_avatar_url" => $storedUrl,
    "stored_keys" => is_array($stored) ? array_keys($stored) : [],
    "simple_avatar_keys" => is_array($simpleStored) ? array_keys($simpleStored) : [],
    "legacy_avatar_keys" => is_array($legacyStored) ? array_keys($legacyStored) : [],
    "frontend_avatar_url" => $avatar96,
    "admin_avatar_url" => $avatar250,
    "show_avatars" => get_option("show_avatars"),
    "admin_avatar_html_ok" => $avatar250HtmlOk,
    "admin_avatar_error" => $avatar250Error,
]);
PHP;

        $php = str_replace(
            ["__USER_ID__", "__MEDIA_ID__", "__URL__"],
            [(string) $userId, (string) $mediaId, var_export($url, true)],
            $php
        );
        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "WordPress avatar payload update failed.")];
        }
        $parsed = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_AVATAR:");
        if (!is_array($parsed)) {
            return ["success" => false, "message" => "Failed to parse WordPress avatar payload update output."];
        }

        return $parsed;
    }

    private function extractUserAvatarUrl(mixed $payload): string
    {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") {
                break;
            }
            $data = $decoded;
        }

        if (is_array($data)) {
            foreach (["full", 96, "96", "thumbnail"] as $key) {
                if (!empty($data[$key]) && is_string($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_URL)) {
                    return $data[$key];
                }
            }
        }

        if (is_string($payload)) {
            foreach (explode(chr(34), $payload) as $part) {
                if (str_starts_with($part, "http") && filter_var($part, FILTER_VALIDATE_URL)) {
                    return $part;
                }
            }
        }

        return "";
    }

    private function extractUserAvatarMediaId(mixed $payload): int
    {
        $data = $payload;
        for ($i = 0; $i < 3 && is_string($data) && $data !== ""; $i++) {
            $decoded = @unserialize($data);
            if ($decoded === false && $data !== "b:0;") {
                break;
            }
            $data = $decoded;
        }

        return is_array($data) && !empty($data["media_id"]) ? (int) $data["media_id"] : 0;
    }

}
