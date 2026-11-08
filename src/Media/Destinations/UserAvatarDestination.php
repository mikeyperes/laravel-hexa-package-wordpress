<?php

namespace hexa_package_wordpress\Media\Destinations;

use hexa_package_wordpress\Media\Contracts\CacheAwareWordPressMediaDestination;
use hexa_package_wordpress\Media\Contracts\RevalidatesCurrentWordPressMediaDestination;
use hexa_package_wordpress\Media\Contracts\WordPressMediaDestination;
use hexa_package_wordpress\Media\WordPressMediaGateway;

final class UserAvatarDestination implements WordPressMediaDestination, CacheAwareWordPressMediaDestination, RevalidatesCurrentWordPressMediaDestination
{
    private array $assignment = [];

    public function __construct(public readonly int $userId)
    {
    }

    public function key(): string
    {
        return "user_avatar:" . $this->userId;
    }

    public function label(): string
    {
        return "WordPress user avatar";
    }

    public function capture(WordPressMediaGateway $gateway, array $target): array
    {
        $profile = $gateway->manager()->getUserProfile($target, $this->userId, true);
        $data = (array) ($profile["data"] ?? []);

        return [
            "success" => (bool) ($profile["success"] ?? false),
            "media_id" => (int) ($data["avatar_media_id"] ?? $data["wp_user_avatar"] ?? 0),
            "url" => (string) ($data["avatar_full_url"] ?? $data["avatar_url"] ?? ""),
            "provider" => (string) ($data["avatar_provider"] ?? ""),
            "author_url" => (string) ($data["author_url"] ?? ""),
        ];
    }

    public function assign(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        return $this->assignment = $gateway->manager()->setUserAvatar($target, $this->userId, $mediaId > 0 ? $mediaId : null, false);
    }

    public function verify(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        $profile = $gateway->manager()->getUserProfile($target, $this->userId, true);
        $data = (array) ($profile["data"] ?? []);
        $storedId = (int) ($data["avatar_media_id"] ?? $data["wp_user_avatar"] ?? 0);
        $url = (string) ($data["avatar_full_url"] ?? $data["avatar_url"] ?? "");
        $provider = (string) ($data["avatar_provider"] ?? "");
        $success = ($profile["success"] ?? false)
            && ($mediaId > 0 ? $storedId === $mediaId && filter_var($url, FILTER_VALIDATE_URL) : $storedId === 0);

        return [
            "frontend_avatar_url" => (string) ($this->assignment["avatar_result"]["frontend_avatar_url"] ?? ""),
            "admin_avatar_url" => (string) ($this->assignment["avatar_result"]["admin_avatar_url"] ?? ""),
            "success" => $success,
            "message" => $success ? "Public WordPress avatar provider state verified." : "WordPress avatar assignment did not verify against the active provider.",
            "media_id" => $storedId,
            "url" => $url,
            "provider" => $provider,
            "author_url" => (string) ($data["author_url"] ?? ""),
        ];
    }

    /** Only this author's cache is cleared, never the whole site. See BUGLOG.md WORDPRESS-AVATAR-003. */
    public function purgeCache(WordPressMediaGateway $gateway, array $target): array
    {
        return $gateway->purgeAuthorCache($target, $this->userId);
    }

    public function rollback(WordPressMediaGateway $gateway, array $target, array $previous): array
    {
        $mediaId = (int) ($previous["media_id"] ?? 0);

        return $gateway->manager()->setUserAvatar($target, $this->userId, $mediaId > 0 ? $mediaId : null, false);
    }
}
