<?php

namespace hexa_package_wordpress\Services;

use hexa_package_wordpress\Contracts\WordPressPostSnapshotExtension;
use Throwable;

final class WordPressPostSnapshotExtensionRegistry
{
    /** @var array<string, WordPressPostSnapshotExtension> */
    private array $extensions = [];

    public function register(WordPressPostSnapshotExtension $extension): void
    {
        $key = trim($extension->key());
        if ($key === '') {
            throw new \InvalidArgumentException('A WordPress post snapshot extension needs a key.');
        }

        $this->extensions[$key] = $extension;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $post
     * @return array{data: array<string, array<string, mixed>>, errors: array<string, string>}
     */
    public function apply(array $target, array $post): array
    {
        $data = [];
        $errors = [];

        foreach ($this->extensions as $key => $extension) {
            try {
                if ($extension->supports($target, $post)) {
                    $data[$key] = $extension->extend($target, $post);
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors[$key] = $exception->getMessage();
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }
}
