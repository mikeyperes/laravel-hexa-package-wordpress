<?php

namespace hexa_package_wordpress\Media\Destinations;

use hexa_package_wordpress\Media\Contracts\WordPressMediaDestination;
use hexa_package_wordpress\Media\WordPressMediaGateway;

final class PostFeaturedImageDestination implements WordPressMediaDestination
{
    public function __construct(public readonly int $postId)
    {
    }

    public function key(): string
    {
        return "post_featured_image:" . $this->postId;
    }

    public function label(): string
    {
        return "WordPress post featured image";
    }

    public function capture(WordPressMediaGateway $gateway, array $target): array
    {
        return $gateway->featuredImageState($target, $this->postId);
    }

    public function assign(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        return $gateway->setFeaturedImage($target, $this->postId, $mediaId);
    }

    public function verify(WordPressMediaGateway $gateway, array $target, int $mediaId): array
    {
        $state = $gateway->featuredImageState($target, $this->postId);
        $state["success"] = (bool) ($state["success"] ?? false) && (int) ($state["media_id"] ?? 0) === $mediaId;
        $state["message"] = $state["success"] ? "Live WordPress featured image state verified." : "WordPress featured image did not verify.";

        return $state;
    }

    public function rollback(WordPressMediaGateway $gateway, array $target, array $previous): array
    {
        return $gateway->setFeaturedImage($target, $this->postId, (int) ($previous["media_id"] ?? 0));
    }
}
