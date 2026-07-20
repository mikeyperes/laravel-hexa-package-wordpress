<?php

namespace hexa_package_wordpress\Media\Contracts;

use hexa_package_wordpress\Media\WordPressMediaGateway;

interface WordPressMediaDestination
{
    public function key(): string;

    public function label(): string;

    public function capture(WordPressMediaGateway $gateway, array $target): array;

    public function assign(WordPressMediaGateway $gateway, array $target, int $mediaId): array;

    public function verify(WordPressMediaGateway $gateway, array $target, int $mediaId): array;

    public function rollback(WordPressMediaGateway $gateway, array $target, array $previous): array;
}
