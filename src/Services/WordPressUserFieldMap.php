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
                "key" => "avatar_url",
                "label" => "Profile Photo",
                "wp_field" => "avatar_url",
                "wp_type" => "profile_photo",
                "wp_page" => "profile.php",
                "notion_field_groups" => [
                    ["label" => "Headshot", "fields" => ["Headshot", "HeadShot", "Profile Photo", "Profile Photos"]],
                    ["label" => "Headshot PNG", "fields" => ["Headshot PNG", "HeadShot PNG", "PNG Headshot"]],
                    ["label" => "Old Headshot", "fields" => ["Old Headshot", "Headshot old", "Headshot Old", "Headshot OLD", "Old HeadShot"]],
                    ["label" => "Gallery", "fields" => ["Gallery", "Gallery Photos"]],
                    ["label" => "Personal Photos", "fields" => ["Personal Photos", "Personal Photo Gallery"]],
                ],
                "notion_fields" => ["Headshot", "Headshot PNG", "Old Headshot", "Headshot old", "Gallery", "Gallery Photos", "Personal Photos"],
                "expand_notion_fields" => true,
                "show_missing_notion_fields" => true,
                "photo_bridge" => true,
                "photo_upload" => true,
                "notion_to_wp" => false,
                "notion_to_wp_reason" => "Profile photos require media import and avatar assignment; use Use as WP Profile Photo or Upload Profile Photo on this row.",
            ],
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
                "key" => "website_meta",
                "label" => "Website Meta",
                "wp_field" => "urls_website",
                "wp_aliases" => ["website"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Official Website", "Company Website URL"],
            ],
            [
                "key" => "facebook",
                "label" => "Facebook",
                "wp_field" => "urls_facebook",
                "wp_aliases" => ["facebook"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Facebook URL"],
            ],
            [
                "key" => "instagram",
                "label" => "Instagram",
                "wp_field" => "urls_instagram",
                "wp_aliases" => ["instagram"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Instagram URL"],
            ],
            [
                "key" => "linkedin",
                "label" => "LinkedIn",
                "wp_field" => "urls_linkedin",
                "wp_aliases" => ["linkedin"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal LinkedIn URL"],
            ],
            [
                "key" => "twitter",
                "label" => "Twitter / X",
                "wp_field" => "urls_x",
                "wp_aliases" => ["twitter", "x", "urls_twitter"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Personal Twitter URL"],
            ],
            [
                "key" => "youtube",
                "label" => "YouTube",
                "wp_field" => "urls_youtube",
                "wp_aliases" => ["youtube"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["YouTube URL"],
            ],
            [
                "key" => "tiktok",
                "label" => "TikTok",
                "wp_field" => "urls_tiktok",
                "wp_aliases" => ["tiktok"],
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
                "wp_field" => "urls_github",
                "wp_aliases" => ["github"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["GitHub URL"],
            ],
            [
                "key" => "soundcloud",
                "label" => "SoundCloud",
                "wp_field" => "urls_soundcloud",
                "wp_aliases" => ["soundcloud"],
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
                "wp_field" => "urls_whatsapp",
                "wp_aliases" => ["whatsapp"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["WhatsApp"],
            ],
            [
                "key" => "telegram",
                "label" => "Telegram",
                "wp_field" => "urls_telegram",
                "wp_aliases" => ["telegram"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Telegram URL", "SameAs URLs", "Other URLs"],
            ],
            [
                "key" => "imdb_url",
                "label" => "IMDb",
                "wp_field" => "urls_imdb",
                "wp_aliases" => ["imdb_url", "imdb"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["iMDB URL", "IMDB URL"],
            ],
            [
                "key" => "crunchbase_url",
                "label" => "Crunchbase",
                "wp_field" => "urls_crunchbase",
                "wp_aliases" => ["crunchbase_url", "crunchbase"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Crunchbase URL"],
            ],
            [
                "key" => "muck_rack_url",
                "label" => "MuckRack",
                "wp_field" => "urls_muckrack",
                "wp_aliases" => ["muck_rack_url", "muckrack_url", "muckrack"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["MuckRack URL"],
            ],
            [
                "key" => "wikipedia_url",
                "label" => "Wikipedia",
                "wp_field" => "urls_wikipedia",
                "wp_aliases" => ["wikipedia_url", "wikipedia"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Wikipedia URL", "Wikipedia"],
            ],
            [
                "key" => "f6s_url",
                "label" => "F6S",
                "wp_field" => "urls_f6s",
                "wp_aliases" => ["f6s_url", "f6s"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["F6S URL", "F6S"],
            ],
            [
                "key" => "threads_url",
                "label" => "Threads",
                "wp_field" => "urls_threads",
                "wp_aliases" => ["threads_url", "threads"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Threads URL", "Threads"],
            ],
            [
                "key" => "the_org_url",
                "label" => "The Org",
                "wp_field" => "urls_the_org",
                "wp_aliases" => ["the_org_url", "the_org"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["The Org URL", "The Org"],
            ],
            [
                "key" => "calendly_url",
                "label" => "Calendly",
                "wp_field" => "urls_calendly",
                "wp_aliases" => ["calendly_url", "calendly"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Calendly URL", "Calendly"],
            ],
            [
                "key" => "signal_url",
                "label" => "Signal",
                "wp_field" => "urls_signal",
                "wp_aliases" => ["signal_url", "signal"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Signal URL", "Signal"],
            ],
            [
                "key" => "amazon_url",
                "label" => "Amazon",
                "wp_field" => "urls_amazon",
                "wp_aliases" => ["amazon_url", "amazon"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Amazon URL", "Amazon"],
            ],
            [
                "key" => "audible_url",
                "label" => "Audible",
                "wp_field" => "urls_audible",
                "wp_aliases" => ["audible_url", "audible"],
                "wp_type" => "usermeta",
                "wp_page" => "profile.php",
                "notion_fields" => ["Audible URL", "Audible"],
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
                "source_transform" => "verified_boolean",
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
