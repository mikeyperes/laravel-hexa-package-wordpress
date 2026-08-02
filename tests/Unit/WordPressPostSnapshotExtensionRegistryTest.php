<?php

namespace Tests\Unit;

use hexa_package_wordpress\Contracts\WordPressPostSnapshotExtension;
use hexa_package_wordpress\Services\WordPressPostSnapshotExtensionRegistry;
use PHPUnit\Framework\TestCase;

class WordPressPostSnapshotExtensionRegistryTest extends TestCase
{
    public function test_it_applies_only_supported_extensions(): void
    {
        $registry = new WordPressPostSnapshotExtensionRegistry();
        $registry->register(new class implements WordPressPostSnapshotExtension {
            public function key(): string
            {
                return 'example';
            }

            public function supports(array $target, array $post): bool
            {
                return ($target['url'] ?? null) === 'https://example.test';
            }

            public function extend(array $target, array $post): array
            {
                return ['post_id' => $post['id']];
            }
        });

        $result = $registry->apply(
            ['url' => 'https://example.test'],
            ['id' => 42, 'meta' => ['private' => 'internal']]
        );

        $this->assertSame(['example' => ['post_id' => 42]], $result['data']);
        $this->assertSame([], $result['errors']);
    }

    public function test_it_rejects_an_empty_extension_key(): void
    {
        $registry = new WordPressPostSnapshotExtensionRegistry();
        $this->expectException(\InvalidArgumentException::class);

        $registry->register(new class implements WordPressPostSnapshotExtension {
            public function key(): string
            {
                return '';
            }

            public function supports(array $target, array $post): bool
            {
                return true;
            }

            public function extend(array $target, array $post): array
            {
                return [];
            }
        });
    }
}
