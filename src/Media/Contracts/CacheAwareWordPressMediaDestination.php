<?php

namespace hexa_package_wordpress\Media\Contracts;

use hexa_package_wordpress\Media\WordPressMediaGateway;

interface CacheAwareWordPressMediaDestination
{
    public function purgeCache(WordPressMediaGateway $gateway, array $target): array;
}
