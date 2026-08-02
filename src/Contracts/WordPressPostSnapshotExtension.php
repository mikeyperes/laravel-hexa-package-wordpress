<?php

namespace hexa_package_wordpress\Contracts;

interface WordPressPostSnapshotExtension
{
    public function key(): string;

    /** @param array<string, mixed> $target @param array<string, mixed> $post */
    public function supports(array $target, array $post): bool;

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function extend(array $target, array $post): array;
}
