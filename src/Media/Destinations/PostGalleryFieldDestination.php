<?php

namespace hexa_package_wordpress\Media\Destinations;

use hexa_package_wordpress\Media\Contracts\CacheAwareWordPressMediaDestination;
use hexa_package_wordpress\Media\Contracts\WordPressMediaDestination;
use hexa_package_wordpress\Media\WordPressMediaGateway;

/**
 * Adds an image to a post's gallery field (ACF, or post meta in ACF's layout).
 * A gallery holds many images, so assigning appends once and never replaces.
 */
final class PostGalleryFieldDestination implements WordPressMediaDestination, CacheAwareWordPressMediaDestination
{
    public function __construct(public readonly int $postId, public readonly string $field = "gallery")
    {
    }

    public function key(): string
    {
        return "post_gallery:" . $this->postId . ":" . $this->field;
    }

    public function label(): string
    {
        return "WordPress gallery field " . $this->field;
    }

    public function capture(WordPressMediaGateway $gateway, array $target): array
    {
        // media_id stays 0: the pipeline always runs assign, which is a no-op for an image already there.
        return ["media_id" => 0] + $gateway->galleryState($target, $this->postId, $this->field);
    }

    public function assign(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        $state = $gateway->galleryState($target, $this->postId, $this->field);
        if (!($state["success"] ?? false)) {
            return $state;
        }
        $ids = array_map("intval", (array) ($state["media_ids"] ?? []));
        if (in_array($mediaId, $ids, true)) {
            return ["success" => true, "message" => "The image is already in the gallery.", "media_ids" => $ids, "already_in_gallery" => true];
        }

        return $gateway->setGallery($target, $this->postId, $this->field, [...$ids, $mediaId]);
    }

    public function verify(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        $state = $gateway->galleryState($target, $this->postId, $this->field);
        $inGallery = ($state["success"] ?? false) && in_array($mediaId, array_map("intval", (array) ($state["media_ids"] ?? [])), true);

        return [
            "success" => $inGallery,
            "message" => $inGallery ? "Gallery field verified." : "The image is not in the gallery field.",
            "media_id" => $mediaId,
            "media_ids" => (array) ($state["media_ids"] ?? []),
            "url" => (string) ($state["urls"][(string) $mediaId] ?? ""),
        ];
    }

    public function purgeCache(WordPressMediaGateway $gateway, array $target): array
    {
        return $gateway->purgePostCache($target, $this->postId);
    }

    public function rollback(WordPressMediaGateway $gateway, array $target, array $previous): array
    {
        return $gateway->setGallery($target, $this->postId, $this->field, (array) ($previous["media_ids"] ?? []));
    }
}
