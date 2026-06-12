<?php

namespace hexa_package_wordpress\Services;

class WordPressUserFieldMap
{
    /**
     * Generic WordPress user fields for journalist/editorial profile sync.
     *
     * Context packages may filter, append, or override these rows, but the
     * baseline WordPress-side field vocabulary belongs here.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function journalistFields(array $overrides = []): array
    {
        return self::applyOverrides([
            [
                "key" => "display_name",
                "label" => "Display Name",
                "wp_field" => "display_name",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Full Name", "Name"],
            ],
            [
                "key" => "user_email",
                "label" => "Email",
                "wp_field" => "user_email",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Primary Email", "Public Email", "Email"],
            ],
            [
                "key" => "description",
                "label" => "Biography",
                "wp_field" => "description",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Description", "Biography (Short)", "Biography (Full)", "Biography", "Bio"],
            ],
            [
                "key" => "user_url",
                "label" => "Website URL",
                "wp_field" => "user_url",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Official Website", "Company Website URL", "Website", "Personal Website", "URL"],
            ],
            [
                "key" => "first_name",
                "label" => "First Name",
                "wp_field" => "first_name",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Full Name", "Name"],
                "source_transform" => "first_name",
                "wp_to_notion" => false,
                "wp_to_notion_reason" => "First Name is derived from the full Notion name and should not overwrite the full-name field by itself.",
            ],
            [
                "key" => "last_name",
                "label" => "Last Name",
                "wp_field" => "last_name",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Full Name", "Name"],
                "source_transform" => "last_name",
                "wp_to_notion" => false,
                "wp_to_notion_reason" => "Last Name is derived from the full Notion name and should not overwrite the full-name field by itself.",
            ],
            [
                "key" => "nickname",
                "label" => "Nickname",
                "wp_field" => "nickname",
                "wp_type" => "native",
                "wp_page" => "profile.php",
                "notion_fields" => ["Nicknames"],
            ],
            [
                "key" => "user_login",
                "label" => "Username",
                "wp_field" => "user_login",
                "wp_type" => "read_only",
                "wp_page" => "profile.php",
                "notion_fields" => ["Podcast Profile Slug"],
                "notion_to_wp" => false,
                "notion_to_wp_reason" => "WordPress usernames are treated as read-only after account creation.",
            ],
            [
                "key" => "user_nicename",
                "label" => "Author Slug",
                "wp_field" => "user_nicename",
                "wp_type" => "read_only",
                "wp_page" => "profile.php",
                "notion_fields" => ["WordPress Permalink", "Podcast Profile Slug"],
                "notion_to_wp" => false,
                "notion_to_wp_reason" => "Author slug changes can affect public URLs and should be handled by a dedicated slug action.",
            ],
            [
                "key" => "avatar_url",
                "label" => "Profile Photo",
                "wp_field" => "avatar_url",
                "wp_type" => "profile_photo",
                "wp_page" => "profile.php",
                "notion_fields" => ["Headshot", "Old Headshot", "Headshot PNG", "Personal Photos", "Gallery"],
                "notion_to_wp" => false,
                "notion_to_wp_reason" => "Profile photos require media import and avatar assignment; use the photo tool for this row.",
            ],
            [
                "key" => "avatar_media_id",
                "label" => "Profile Photo Media ID",
                "wp_field" => "avatar_media_id",
                "wp_type" => "profile_photo_meta",
                "wp_page" => "profile.php",
                "notion_fields" => [],
                "notion_to_wp" => false,
                "wp_to_notion" => false,
                "notion_to_wp_reason" => "The avatar media ID is WordPress-only control data.",
                "wp_to_notion_reason" => "The avatar media ID is WordPress-only control data.",
            ],
            [
                "key" => "website_meta",
                "label" => "Website Meta",
                "wp_field" => "website",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Official Website", "Company Website URL"],
            ],
            [
                "key" => "facebook",
                "label" => "Facebook",
                "wp_field" => "facebook",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Facebook URL"],
            ],
            [
                "key" => "instagram",
                "label" => "Instagram",
                "wp_field" => "instagram",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Instagram URL"],
            ],
            [
                "key" => "linkedin",
                "label" => "LinkedIn",
                "wp_field" => "linkedin",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal LinkedIn URL"],
            ],
            [
                "key" => "twitter",
                "label" => "Twitter / X",
                "wp_field" => "twitter",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Twitter URL"],
            ],
            [
                "key" => "youtube",
                "label" => "YouTube",
                "wp_field" => "youtube",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["YouTube URL"],
            ],
            [
                "key" => "tiktok",
                "label" => "TikTok",
                "wp_field" => "tiktok",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["TikTok URL"],
            ],
            [
                "key" => "medium",
                "label" => "Medium",
                "wp_field" => "medium",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Medium URL"],
            ],
            [
                "key" => "github",
                "label" => "GitHub",
                "wp_field" => "github",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["GitHub URL"],
            ],
            [
                "key" => "soundcloud",
                "label" => "SoundCloud",
                "wp_field" => "soundcloud",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Soundcloud URL"],
            ],
            [
                "key" => "flickr",
                "label" => "Flickr",
                "wp_field" => "flickr",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Flickr URL"],
            ],
            [
                "key" => "behance",
                "label" => "Behance",
                "wp_field" => "behance",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Behance URL"],
            ],
            [
                "key" => "whatsapp",
                "label" => "WhatsApp",
                "wp_field" => "whatsapp",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["WhatsApp"],
            ],
            [
                "key" => "telegram",
                "label" => "Telegram",
                "wp_field" => "telegram",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Telegram URL", "SameAs URLs", "Other URLs"],
            ],
            [
                "key" => "imdb_url",
                "label" => "IMDb",
                "wp_field" => "imdb_url",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["iMDB URL", "IMDB URL"],
            ],
            [
                "key" => "crunchbase_url",
                "label" => "Crunchbase",
                "wp_field" => "crunchbase_url",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Crunchbase URL"],
            ],
            [
                "key" => "muck_rack_url",
                "label" => "MuckRack",
                "wp_field" => "muck_rack_url",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["MuckRack URL"],
            ],
            [
                "key" => "everipedia_url",
                "label" => "Everipedia",
                "wp_field" => "everipedia_url",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Everipedia URL"],
            ],
            [
                "key" => "wikitia_url",
                "label" => "Wikitia",
                "wp_field" => "wikitia_url",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Wikitia URL"],
            ],
            [
                "key" => "is_verified",
                "label" => "Verified",
                "wp_field" => "is_verified",
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Verified Profiles", "Status"],
            ],
        ], $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, array<string, mixed>> $overrides
     * @return array<int, array<string, mixed>>
     */
    private static function applyOverrides(array $fields, array $overrides): array
    {
        if ($overrides === []) {
            return $fields;
        }

        return array_map(static function (array $field) use ($overrides): array {
            $key = (string) ($field["key"] ?? "");
            if ($key !== "" && isset($overrides[$key]) && is_array($overrides[$key])) {
                return array_merge($field, $overrides[$key]);
            }

            return $field;
        }, $fields);
    }
}
