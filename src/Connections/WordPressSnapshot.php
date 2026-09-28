<?php

namespace hexa_package_wordpress\Connections;

/**
 * Rules for the local copy of a WordPress object (a user or a post) that a
 * tool keeps to compare against the live site.
 */
final class WordPressSnapshot
{
    /**
     * Keys that hold WordPress credentials or login sessions. They are never
     * stored in a snapshot, at any depth (see BUGLOG.md JOURNALIST-BUG-002).
     */
    public const PROTECTED_KEYS = ['user_pass', 'user_activation_key', 'session_tokens', '_application_passwords'];

    /**
     * The snapshot without credential or session keys.
     *
     * @param  array<array-key, mixed>  $snapshot
     * @return array<array-key, mixed>
     */
    public static function clean(array $snapshot): array
    {
        $clean = [];
        foreach ($snapshot as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::PROTECTED_KEYS, true)) {
                continue;
            }
            // A meta row may name its key in a field: ['meta_key' => 'session_tokens', ...].
            if (is_array($value) && in_array(strtolower((string) ($value['meta_key'] ?? $value['key'] ?? '')), self::PROTECTED_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return array_is_list($snapshot) ? array_values($clean) : $clean;
    }
}
