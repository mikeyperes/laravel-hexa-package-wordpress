<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

/**
 * Changes the address of one author page (/author/<slug>/, the user's
 * user_nicename) without touching the login, posts or profile, and keeps every
 * earlier address working with a permanent (301) redirect in Rank Math's
 * redirect manager. Rank Math's redirect module is switched on when it is off.
 * When the redirect cannot be saved the address change is undone, so a site
 * never loses its old author address. Only the old and new author pages are
 * cleared from LiteSpeed; the rest of the site cache is left alone.
 */
trait ChangesWordPressAuthorAddresses
{
    /**
     * @param  array<int, string>  $redirectFrom  earlier slugs (beyond the current one) that must also redirect
     * @return array{success: bool, message: string, data: array}
     */
    public function changeAuthorSlug(array $target, int $userId, string $newSlug, array $redirectFrom = []): array
    {
        $target = $this->normalizeTarget($target);
        if ($userId <= 0) {
            return ["success" => false, "message" => "A WordPress user ID is required.", "data" => []];
        }
        if (!$this->usesWpToolkit($target)) {
            return ["success" => false, "message" => "Changing an author address requires WP Toolkit PHP access.", "data" => []];
        }

        $php = strtr(<<<'PHP'
$userId = __USER_ID__;
$requested = __SLUG__;
$redirectFrom = __REDIRECT_FROM__;
$out = static function (array $payload) {
    echo "HEXA_AUTHOR_ADDRESS:" . wp_json_encode($payload);
};
$user = get_userdata($userId);
if (!$user instanceof WP_User) {
    $out(["success" => false, "message" => "WordPress user #" . $userId . " was not found."]);
    return;
}
$new = sanitize_title($requested);
if ($new === "" || $new !== strtolower(trim($requested))) {
    $out(["success" => false, "message" => "The new address may use only lowercase letters, numbers and hyphens."]);
    return;
}
$old = (string) $user->user_nicename;
$owner = get_user_by("slug", $new);
if ($owner instanceof WP_User && (int) $owner->ID !== $userId) {
    $out(["success" => false, "message" => "/author/" . $new . "/ already belongs to WordPress user #" . (int) $owner->ID . "."]);
    return;
}
if (!class_exists("\\RankMath\\Helper") || !class_exists("\\RankMath\\Redirections\\Redirection") || !class_exists("\\RankMath\\Redirections\\DB")) {
    $out(["success" => false, "message" => "Rank Math is not active, so the old address cannot redirect. Nothing was changed."]);
    return;
}

$moduleWasOn = in_array("redirections", (array) get_option("rank_math_modules", []), true);
if (!$moduleWasOn) {
    \RankMath\Helper::update_modules(["redirections" => "on"]);
    do_action("rank_math/module_changed", "redirections", "on");
}
$restoreModule = static function () use ($moduleWasOn) {
    if (!$moduleWasOn) {
        \RankMath\Helper::update_modules(["redirections" => "off"]);
        do_action("rank_math/module_changed", "redirections", "off");
    }
};
global $wpdb;
$table = $wpdb->prefix . "rank_math_redirections";
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
    $restoreModule();
    $out(["success" => false, "message" => "Rank Math's redirect table is missing. Nothing was changed."]);
    return;
}
$blocking = \RankMath\Redirections\DB::match_redirections("author/" . $new);
if (is_array($blocking) && !empty($blocking["id"])) {
    $restoreModule();
    $out(["success" => false, "message" => "Rank Math redirect #" . (int) $blocking["id"] . " already sends /author/" . $new . "/ elsewhere. Nothing was changed."]);
    return;
}

$changed = false;
if ($old !== $new) {
    $updated = wp_update_user(["ID" => $userId, "user_nicename" => $new]);
    clean_user_cache($userId);
    $check = get_userdata($userId);
    if (is_wp_error($updated) || !$check instanceof WP_User || (string) $check->user_nicename !== $new) {
        if ($check instanceof WP_User && (string) $check->user_nicename !== $old) {
            wp_update_user(["ID" => $userId, "user_nicename" => $old]);
            clean_user_cache($userId);
        }
        $restoreModule();
        $out(["success" => false, "message" => is_wp_error($updated) ? $updated->get_error_message() : "WordPress did not keep the new address. Nothing was changed."]);
        return;
    }
    $changed = true;
}

$newUrl = (string) get_author_posts_url($userId, $new);
$sources = [];
foreach (array_merge([$old], (array) $redirectFrom) as $slug) {
    $slug = sanitize_title((string) $slug);
    if ($slug !== "" && $slug !== $new) {
        $sources[$slug] = true;
    }
}
$redirects = [];
$failure = "";
foreach (array_keys($sources) as $slug) {
    $existing = \RankMath\Redirections\DB::match_redirections("author/" . $slug);
    if (is_array($existing) && !empty($existing["id"])) {
        if (untrailingslashit((string) $existing["url_to"]) === untrailingslashit($newUrl) && (string) $existing["header_code"] === "301") {
            $redirects[] = ["from" => $slug, "id" => (int) $existing["id"], "created" => false];
            continue;
        }
        $failure = "Rank Math redirect #" . (int) $existing["id"] . " already sends /author/" . $slug . "/ to " . (string) $existing["url_to"] . ".";
        break;
    }
    $id = \RankMath\Redirections\Redirection::from([
        "url_to" => $newUrl,
        "header_code" => "301",
        "status" => "active",
        "sources" => [["pattern" => "author/" . $slug, "comparison" => "exact"]],
    ])->save();
    if (!$id) {
        $failure = "Rank Math did not save the redirect from /author/" . $slug . "/.";
        break;
    }
    $redirects[] = ["from" => $slug, "id" => (int) $id, "created" => true];
}
if ($failure !== "") {
    foreach ($redirects as $redirect) {
        if ($redirect["created"]) {
            \RankMath\Redirections\DB::delete([(int) $redirect["id"]]);
        }
    }
    if ($changed) {
        wp_update_user(["ID" => $userId, "user_nicename" => $old]);
        clean_user_cache($userId);
    }
    $restoreModule();
    $out(["success" => false, "message" => $failure . " The address change was undone."]);
    return;
}

$purged = [];
$oldUrls = array_map(static fn ($slug) => (string) get_author_posts_url($userId, $slug), array_keys($sources));
if (has_action("litespeed_purge_url")) {
    foreach (array_merge($oldUrls, [$newUrl]) as $url) {
        ob_start();
        do_action("litespeed_purge_url", $url);
        ob_end_clean();
        $purged[] = $url;
    }
}
$verified = get_userdata($userId);
$out([
    "success" => true,
    "message" => $changed ? "Author address changed to /author/" . $new . "/." : "Author address was already /author/" . $new . "/.",
    "data" => [
        "changed" => $changed,
        "old_slug" => $old,
        "new_slug" => $new,
        "author_url" => $newUrl,
        "old_urls" => $oldUrls,
        "redirects" => $redirects,
        "rank_math_redirections_enabled" => !$moduleWasOn,
        "litespeed_purged" => $purged,
        "user" => [
            "id" => (int) $verified->ID,
            "ID" => (int) $verified->ID,
            "user_login" => (string) $verified->user_login,
            "user_nicename" => (string) $verified->user_nicename,
            "display_name" => (string) $verified->display_name,
            "author_url" => $newUrl,
        ],
    ],
]);
PHP, [
            "__USER_ID__" => (string) $userId,
            "__SLUG__" => var_export($newSlug, true),
            "__REDIRECT_FROM__" => var_export(array_values(array_map("strval", $redirectFrom)), true),
        ]);

        $result = $this->evaluatePhp($target, $php);
        if (!($result["success"] ?? false)) {
            return ["success" => false, "message" => (string) ($result["message"] ?? "Author address change failed."), "data" => []];
        }

        $payload = $this->decodeMarkedPayload((string) ($result["stdout"] ?? ""), "HEXA_AUTHOR_ADDRESS:");
        if (!is_array($payload)) {
            return ["success" => false, "message" => "Failed to read the author address result.", "data" => []];
        }

        return [
            "success" => (bool) ($payload["success"] ?? false),
            "message" => (string) ($payload["message"] ?? ""),
            "data" => is_array($payload["data"] ?? null) ? $payload["data"] : [],
        ];
    }
}
