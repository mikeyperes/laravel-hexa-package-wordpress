<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use Illuminate\Support\Facades\Cache;

/**
 * Author profile reads and avatar writes on all three connection transports:
 * WP Toolkit runs one WordPress bootstrap; REST and HWS Base Tools call HexaWP
 * Core's `hexa-plugin-core/v1/users/{id}/profile` route, falling back to plain
 * REST reads on sites that do not have it yet.
 */
trait ManagesWordPressUserProfiles
{
    public function getUserProfile(array $target, int $userId, bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) return ["success" => false, "message" => "User ID is required.", "data" => []];

        $provider = null;
        // One WordPress bootstrap (WP Toolkit) or one HexaWP Core profile route
        // call (REST and HWS Base Tools) returns the user row, its meta, the
        // legacy avatar URL and the avatar provider. See BUGLOG.md
        // JOURNALIST-BUG-001: this used to reload every user on the site plus
        // three more wp-cli round trips, taking 30-45 seconds per journalist.
        $loaded = $this->usesWpToolkit($target)
            ? $this->loadToolkitUserProfile($target, $userId)
            : $this->loadBridgeUserProfile($target, $userId);
        if (!($loaded["unavailable"] ?? false)) {
            if (!($loaded["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($loaded["message"] ?? "User lookup failed."), "data" => []];
            }
            $data = (array) ($loaded["user"] ?? []);
            if ($data === []) {
                return ["success" => false, "message" => "WordPress user #" . $userId . " was not found.", "data" => []];
            }
            foreach ((array) ($loaded["meta"] ?? []) as $row) {
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
            $legacyAvatarUrl = (string) ($loaded["legacy_avatar_url"] ?? "");
            if (empty($data["avatar_url"]) && !empty($data["wp_user_avatar"]) && filter_var($legacyAvatarUrl, FILTER_VALIDATE_URL)) {
                $data["avatar_url"] = $legacyAvatarUrl;
            }
            $provider = (string) ($loaded["avatar_provider"] ?? "");
        } else {
            $users = $this->listUsers($target, ["include" => [$userId], "per_page" => 1, "force_refresh" => $forceRefresh]);
            if (!($users["success"] ?? false)) {
                return ["success" => false, "message" => (string) ($users["message"] ?? "User lookup failed."), "data" => []];
            }

            $data = is_array($users["users"][0] ?? null) ? $users["users"][0] : [];
            if ($data === []) {
                return ["success" => false, "message" => "WordPress user #" . $userId . " was not found.", "data" => []];
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
            $provider !== null && $provider !== "" ? $provider : $this->activeUserAvatarProvider($target, $forceRefresh),
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
        $toolkit = $this->usesWpToolkit($target);
        if ($toolkit) $this->activeUserAvatarProvider($target, true);
        $before = $this->getUserProfile($target, $userId, true);
        $previous = (int) (($before["data"]["wp_user_avatar"] ?? $before["data"]["avatar_media_id"] ?? 0));
        $mediaId = $mediaId !== null && $mediaId > 0 ? (int) $mediaId : 0;
        if (!$toolkit) {
            $avatarMetaResult = $this->userProfileBridge($target, $userId, ["avatar" => ["media_id" => $mediaId]]);
            if ($avatarMetaResult["unavailable"]) {
                $avatarMetaResult["message"] = "Profile avatar writes need WP Toolkit or HexaWP Core's profile route on the site (update any Hexa plugin, or HWS Base Tools for signed connections).";
            }
        } elseif ($mediaId > 0) {
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
        $via = $toolkit ? "WP Toolkit" : "the HexaWP Core profile route";
        return ["success" => true, "message" => ($mediaId > 0 ? "Profile avatar updated via " : "Profile avatar cleared via ") . $via . ".", "media" => [
            "media_id" => $mediaId,
            "avatar_url" => (string) ($profileData["avatar_full_url"] ?? $profileData["avatar_url"] ?? ""),
            "thumbnail_url" => (string) ($profileData["avatar_thumbnail_url"] ?? $profileData["avatar_url"] ?? ""),
            "full_url" => (string) ($profileData["avatar_full_url"] ?? $profileData["avatar_url"] ?? ""),
            "avatar_sizes" => (array) ($profileData["avatar_sizes"] ?? []),
            "frontend_avatar_url" => (string) ($avatarMetaResult["frontend_avatar_url"] ?? ""),
            "provider" => (string) ($avatarMetaResult["provider"] ?? $profileData["avatar_provider"] ?? ""),
        ], "avatar_result" => $avatarMetaResult];
    }

    /**
     * Load one WordPress user through a single WP Toolkit bootstrap.
     *
     * @return array{success: bool, message?: string, user?: array<string, mixed>, meta?: array<int, array{meta_key: string, meta_value: string}>, legacy_avatar_url?: string, avatar_provider?: string}
     */
    private function loadToolkitUserProfile(array $target, int $userId): array
    {
        $result = $this->evaluatePhp($target, $this->toolkitUserProfilePhp($userId));
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "User lookup failed.")];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_USER_PROFILE:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to parse the WordPress user profile output."];
        }

        $provider = (string) ($payload["avatar_provider"] ?? "") ?: "legacy_avatar_meta";
        try {
            // Reused by activeUserAvatarProvider() so later writes skip a probe.
            Cache::put($this->toolkitCacheBase($target, "avatar-provider"), $provider, 600);
        } catch (\Throwable) {
            // Caching the provider is an optimisation only.
        }

        return $this->userProfileFromPayload($payload);
    }

    /**
     * REST and HWS Base Tools connections read the same payload from HexaWP
     * Core's `hexa-plugin-core/v1/users/{id}/profile` route. `unavailable`
     * means the site has no such route yet, so callers use plain REST.
     */
    private function loadBridgeUserProfile(array $target, int $userId): array
    {
        $result = $this->userProfileBridge($target, $userId);
        if ($result["unavailable"]) {
            return ["success" => false, "unavailable" => true, "message" => (string) ($result["message"] ?? "")];
        }
        if (!$result["success"] || !is_array($result["data"] ?? null)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "User lookup failed.")];
        }

        return $this->userProfileFromPayload($result["data"]);
    }

    /**
     * One call to HexaWP Core's user profile route (GET reads, POST writes
     * native fields, meta, fields and the avatar; role, login and password are
     * never touched). WP Toolkit targets run it in-process through the site's
     * REST router; HWS Base Tools targets go through its signed bridge.
     */
    private function userProfileBridge(array $target, int $userId, array $body = []): array
    {
        $result = $this->requestRestRoute($target, $body === [] ? "GET" : "POST", "hexa-plugin-core/v1/users/" . $userId . "/profile", $body);
        $status = (int) ($result["status"] ?? 0);
        $code = is_array($result["data"] ?? null) ? (string) ($result["data"]["code"] ?? "") : "";
        $result["success"] = (bool) ($result["success"] ?? false);
        $result["unavailable"] = !$result["success"]
            && ($code === "rest_no_route" || (($status === 404 || $status === 422) && !str_starts_with($code, "hexa_user_profile_")));

        return $result;
    }

    private function userProfileFromPayload(array $payload): array
    {
        $provider = (string) ($payload["avatar_provider"] ?? "") ?: "legacy_avatar_meta";
        $row = is_array($payload["rows"][0] ?? null) ? $payload["rows"][0] : null;
        $user = $row === null ? [] : $this->normalizeUserAvatarForProvider($this->normalizeUserRow($row), $provider);

        return [
            "success" => true,
            "user" => $user,
            "meta" => array_values(array_filter((array) ($payload["meta"] ?? []), "is_array")),
            "legacy_avatar_url" => (string) ($payload["legacy_avatar_url"] ?? ""),
            "avatar_provider" => $provider,
        ];
    }

    private function toolkitUserProfilePhp(int $userId): string
    {
        $afterRows = implode("", [
            '$meta=[];',
            '$protectedMeta=' . var_export($this->protectedUserMetaKeys(), true) . ';',
            'foreach ((array) get_user_meta(' . $userId . ') as $metaKey=>$metaValues) { if (in_array((string) $metaKey, $protectedMeta, true)) { continue; } foreach ((array) $metaValues as $metaValue) { $meta[]=["meta_key"=>(string) $metaKey,"meta_value"=>is_scalar($metaValue) ? (string) $metaValue : maybe_serialize($metaValue)]; } }',
            '$legacyAvatarId=(int) get_user_meta(' . $userId . ',"wp_user_avatar",true);',
            '$legacyAvatarUrl=$legacyAvatarId>0 ? (string) wp_get_attachment_url($legacyAvatarId) : "";',
            'if ($legacyAvatarUrl==="" && $legacyAvatarId>0) { $legacyAvatarUrl=(string) get_post_field("guid",$legacyAvatarId); }',
            $this->simpleLocalAvatarRuntimePhp(),
            'echo "HEXA_USER_PROFILE:" . wp_json_encode(["rows"=>$rows,"meta"=>$meta,"legacy_avatar_url"=>$legacyAvatarUrl,"avatar_provider"=>$provider]);',
        ]);

        return $this->toolkitUserRowsPhp([$userId], $afterRows);
    }
}
