<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressUsersAndMeta
{
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
            if (empty($data["avatar_url"]) && !empty($data["simple_local_avatar"])) {
                $payloadUrl = $this->extractUserAvatarUrl($data["simple_local_avatar"]);
                if ($payloadUrl !== "") {
                    $data["avatar_url"] = $payloadUrl;
                }
            }
            if (empty($data["avatar_url"]) && !empty($data["wp_user_avatars"])) {
                $payloadUrl = $this->extractUserAvatarUrl($data["wp_user_avatars"]);
                if ($payloadUrl !== "") {
                    $data["avatar_url"] = $payloadUrl;
                }
            }
            $data["avatar_media_id"] = (string) (
                $this->extractUserAvatarMediaId($data["simple_local_avatar"] ?? "")
                ?: ($data["wp_user_avatar"] ?? "")
            );
            if (empty($data["avatar_url"]) && !empty($data["wp_user_avatar"])) {
                $url = $this->wpCliAttachmentUrl($target, (int) $data["wp_user_avatar"]);
                if ($url !== "") {
                    $data["avatar_url"] = $url;
                }
            }
        }
        $avatarPayload = ($data["simple_local_avatar"] ?? null)
            ?: ($data["wp_user_avatars"] ?? null)
            ?: ($data["avatar_urls"] ?? []);
        $resolvedAvatar = $this->resolveUserAvatarPayload($avatarPayload, 224);
        $data["avatar_thumbnail_url"] = (string) (
            $resolvedAvatar["thumbnail_url"]
            ?: ($data["avatar_thumbnail_url"] ?? $data["avatar_url"] ?? "")
        );
        $data["avatar_full_url"] = (string) (
            $resolvedAvatar["full_url"]
            ?: ($data["avatar_full_url"] ?? $data["avatar_url"] ?? "")
        );
        $resolvedSizes = (array) ($resolvedAvatar["sizes"] ?? []);
        $data["avatar_sizes"] = $resolvedSizes !== [] ? $resolvedSizes : (array) ($data["avatar_sizes"] ?? []);
        if ($data["avatar_thumbnail_url"] !== "") {
            $data["avatar_url"] = $data["avatar_thumbnail_url"];
        }
        $data = $this->normalizeUserAvatarForProvider(
            $data,
            $this->activeUserAvatarProvider($target, $forceRefresh),
        );
        $data["ID"] = (string) $userId;
        if (empty($data["wp_admin_url"])) {
            $data["wp_admin_url"] = "/wp-admin/user-edit.php?user_id=" . $userId;
        }
        $data["profile_admin_url"] = $data["wp_admin_url"];
        return ["success" => true, "message" => "User profile loaded.", "data" => $data];
    }


    public function setUserAvatar(array $target, int $userId, ?int $mediaId, bool $deletePreviousMedia = false): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) return ["success" => false, "message" => "User ID is required.", "media" => null];
        if (!$this->usesWpToolkit($target)) return ["success" => false, "message" => "Profile avatar writes require WP Toolkit.", "media" => null];
        $this->activeUserAvatarProvider($target, true);
        $before = $this->getUserProfile($target, $userId, true);
        $previous = (int) (($before["data"]["wp_user_avatar"] ?? $before["data"]["avatar_media_id"] ?? 0));
        $mediaId = $mediaId !== null && $mediaId > 0 ? (int) $mediaId : 0;
        if ($mediaId > 0) {
            $url = $this->wpCliAttachmentUrl($target, $mediaId);
            if ($url === "") {
                return ["success" => false, "message" => "WordPress attachment URL was not found for media #" . $mediaId . ".", "media" => null];
            }
            $avatarMetaResult = $this->writeUserAvatarPayload($target, $userId, $mediaId, $url);
        } else {
            $avatarMetaResult = $this->writeUserAvatarPayload($target, $userId, 0, "");
        }
        if (!($avatarMetaResult["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($avatarMetaResult["message"] ?? "WordPress avatar payload update failed."),
                "media" => null,
                "avatar_result" => $avatarMetaResult,
            ];
        }
        if ($deletePreviousMedia && $previous > 0 && $previous !== $mediaId) $this->deleteMedia($target, $previous, true);
        $profile = $this->getUserProfile($target, $userId, true);
        $profileData = (array) ($profile["data"] ?? []);
        if ($mediaId > 0) {
            $savedMediaId = (int) ($profileData["avatar_media_id"] ?? $profileData["wp_user_avatar"] ?? 0);
            $avatarUrl = (string) ($profileData["avatar_url"] ?? "");
            if ($savedMediaId !== $mediaId || $avatarUrl === "") {
                return ["success" => false, "message" => "WordPress avatar write did not verify after save.", "media" => ["media_id" => $mediaId, "avatar_url" => $avatarUrl]];
            }
        }
        return ["success" => true, "message" => $mediaId > 0 ? "Profile avatar updated via WP Toolkit." : "Profile avatar cleared via WP Toolkit.", "media" => [
            "media_id" => $mediaId,
            "avatar_url" => (string) ($profileData["avatar_full_url"] ?? $profileData["avatar_url"] ?? ""),
            "thumbnail_url" => (string) ($profileData["avatar_thumbnail_url"] ?? $profileData["avatar_url"] ?? ""),
            "full_url" => (string) ($profileData["avatar_full_url"] ?? $profileData["avatar_url"] ?? ""),
            "avatar_sizes" => (array) ($profileData["avatar_sizes"] ?? []),
            "frontend_avatar_url" => (string) ($avatarMetaResult["frontend_avatar_url"] ?? ""),
            "provider" => (string) ($avatarMetaResult["provider"] ?? $profileData["avatar_provider"] ?? ""),
        ], "avatar_result" => $avatarMetaResult];
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
