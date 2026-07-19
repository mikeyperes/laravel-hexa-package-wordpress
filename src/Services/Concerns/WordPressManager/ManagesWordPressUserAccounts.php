<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressUserAccounts
{
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
                    '$args=["fields"=>["ID","display_name","user_login","user_nicename","user_email","user_url","roles"],"number"=>9999];',
                    '$users=get_users($args);',
                    '$rows=[];',
                    'foreach ($users as $user) {',
                    '$simpleAvatarPayload=get_user_meta($user->ID,"simple_local_avatar",true);',
                    '$legacyAvatarPayload=get_user_meta($user->ID,"wp_user_avatars",true);',
                    '$avatarId=0;',
                    'if (is_array($simpleAvatarPayload) && !empty($simpleAvatarPayload["media_id"])) { $avatarId=(int) $simpleAvatarPayload["media_id"]; }',
                    'if ($avatarId<=0) { $avatarId=(int) get_user_meta($user->ID,"wp_user_avatar",true); }',
                    '$avatarPayload=$simpleAvatarPayload ?: $legacyAvatarPayload;',
                    '$avatarUrls=[];',
                    'if (is_array($avatarPayload)) { foreach ($avatarPayload as $key=>$value) { if (is_string($value) && filter_var($value,FILTER_VALIDATE_URL)) { $avatarUrls[(string) $key]=$value; } } }',
                    '$avatarFullUrl=(string) ($avatarUrls["full"] ?? $avatarUrls["original"] ?? "");',
                    '$numericAvatarUrls=[];',
                    'foreach ($avatarUrls as $key=>$value) { if (ctype_digit((string) $key)) { $numericAvatarUrls[(int) $key]=$value; } }',
                    'ksort($numericAvatarUrls,SORT_NUMERIC);',
                    '$avatarUrl="";',
                    'foreach ($numericAvatarUrls as $size=>$value) { if ($size>=224) { $avatarUrl=$value; break; } }',
                    'if ($avatarUrl==="" && $numericAvatarUrls!==[]) { $avatarUrl=(string) end($numericAvatarUrls); }',
                    'if ($avatarUrl==="" && !empty($avatarUrls["thumbnail"])) { $avatarUrl=(string) $avatarUrls["thumbnail"]; }',
                    'if ($avatarUrl==="" && $avatarId>0) { $avatarUrl=(string) wp_get_attachment_image_url($avatarId,"medium"); }',
                    'if ($avatarUrl==="" && $avatarId>0) { $maybeAvatar=(string) get_avatar_url($user->ID, ["size"=>96]); if (strpos($maybeAvatar, "wp-content/uploads/") !== false) { $avatarUrl=$maybeAvatar; } }',
                    'if ($avatarFullUrl==="" && $avatarId>0) { $avatarFullUrl=(string) wp_get_attachment_url($avatarId); }',
                    'if ($avatarFullUrl==="") { $avatarFullUrl=$avatarUrl; }',
                    '$authorUrl=(string) get_author_posts_url($user->ID,(string) $user->user_nicename);',
                    '$adminUrl=(string) get_edit_user_link($user->ID);',
                    'if ($adminUrl==="") { $adminUrl=(string) admin_url("user-edit.php?user_id=" . (int) $user->ID); }',
                    '$postCount=(int) count_user_posts((int) $user->ID,"post",false);',
                    '$rows[]=["id"=>(int) $user->ID,"ID"=>(int) $user->ID,"user_login"=>(string) $user->user_login,"user_nicename"=>(string) $user->user_nicename,"display_name"=>(string) $user->display_name,"user_email"=>(string) $user->user_email,"user_url"=>(string) $user->user_url,"roles"=>array_values(array_map("strval", (array) $user->roles)),"wp_user_avatar"=>$avatarId>0 ? (string) $avatarId : "","avatar_media_id"=>$avatarId>0 ? (string) $avatarId : "","wp_user_avatars"=>is_scalar($legacyAvatarPayload) ? (string) $legacyAvatarPayload : maybe_serialize($legacyAvatarPayload),"simple_local_avatar"=>is_scalar($simpleAvatarPayload) ? (string) $simpleAvatarPayload : maybe_serialize($simpleAvatarPayload),"avatar_url"=>$avatarUrl,"avatar_thumbnail_url"=>$avatarUrl,"avatar_full_url"=>$avatarFullUrl,"avatar_sizes"=>$numericAvatarUrls,"author_url"=>$authorUrl,"wp_admin_url"=>$adminUrl,"post_count"=>$postCount,"post_count_known"=>true];',
                    '}',
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
            "_fields" => "id,name,slug,email,url,link,roles,avatar_urls,post_count,post_count_known",
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
