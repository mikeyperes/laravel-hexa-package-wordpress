<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use PHPUnit\Framework\TestCase;

class WordPressPostTaxonomyVerificationTest extends TestCase
{
    public function test_create_requires_confirmed_taxonomy_ids(): void
    {
        $manager = $this->managerWithResult([
            'success' => true,
            'message' => 'Post created and verified.',
            'data' => $this->verifiedData(attempts: 1),
        ]);

        $result = $manager->createPost([], 'Verified post', 'Body', 'draft', [
            'taxonomies' => ['publication' => [2891]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['taxonomy_verification']['success']);
        $this->assertSame(
            [2891],
            $result['data']['taxonomy_verification']['taxonomies']['publication']['actual'],
        );
        $this->assertStringContainsString('wp_set_object_terms($id, $termIds, $taxonomy, false)', $manager->evaluatedPhp);
        $this->assertStringContainsString('"taxonomy_verification"', $manager->evaluatedPhp);
        $this->assertNull($manager->syntaxError, $manager->syntaxError ?? '');
    }

    public function test_create_retries_a_transient_taxonomy_mismatch(): void
    {
        $manager = $this->managerWithResult([
            'success' => true,
            'message' => 'Post created and verified.',
            'data' => $this->verifiedData(attempts: 2),
        ]);

        $result = $manager->createPost([], 'Retried post', 'Body', 'draft', [
            'taxonomies' => ['publication' => [2891]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            2,
            $result['data']['taxonomy_verification']['taxonomies']['publication']['attempts'],
        );
        $this->assertStringContainsString('$retryTaxonomies = array_intersect_key(', $manager->evaluatedPhp);
        $this->assertStringContainsString('$writeTaxonomies($postId, $retryTaxonomies)', $manager->evaluatedPhp);
        $this->assertStringContainsString('$taxonomyAttempts[$taxonomy]', $manager->evaluatedPhp);
    }

    public function test_create_leaves_a_draft_when_taxonomy_cannot_be_verified(): void
    {
        $manager = $this->managerWithResult([
            'success' => false,
            'message' => 'WordPress post verification failed during staging: publication expected [2891] but WordPress confirmed [].',
            'data' => array_replace($this->verifiedData(attempts: 2), [
                'post_status' => 'draft',
                'verification' => [
                    'verified' => false,
                    'phase' => 'stage_readback',
                    'rollback' => ['success' => true, 'errors' => [], 'mismatches' => []],
                ],
                'rollback' => ['success' => true, 'errors' => [], 'mismatches' => []],
                'taxonomy_verification' => [
                    'success' => false,
                    'taxonomies' => [
                        'publication' => [
                            'success' => false,
                            'attempts' => 2,
                            'expected' => [2891],
                            'actual' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $manager->createPost([], 'Rejected post', 'Body', 'draft', [
            'taxonomies' => ['publication' => [2891]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(991, $result['data']['post_id']);
        $this->assertSame('draft', $result['data']['post_status']);
        $this->assertTrue($result['data']['rollback']['success']);
        $this->assertFalse($result['data']['taxonomy_verification']['success']);
        $this->assertStringContainsString('expected [2891]', $result['message']);
        $this->assertStringContainsString('$forceDraft = static function', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('wp_delete_post(', $manager->evaluatedPhp);
    }

    public function test_update_propagates_taxonomy_failure_and_verified_rollback(): void
    {
        $manager = $this->managerWithResult([
            'success' => false,
            'message' => 'WordPress post verification failed during staging: publication expected [2891] but WordPress confirmed [].',
            'data' => array_replace($this->verifiedData(attempts: 2), [
                'verification' => [
                    'verified' => false,
                    'phase' => 'stage_readback',
                    'rollback' => ['success' => true, 'errors' => [], 'mismatches' => []],
                ],
                'rollback' => ['success' => true, 'errors' => [], 'mismatches' => []],
                'taxonomy_verification' => [
                    'success' => false,
                    'taxonomies' => [
                        'publication' => [
                            'success' => false,
                            'attempts' => 2,
                            'expected' => [2891],
                            'actual' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $manager->updatePost([], 991, [
            'taxonomies' => ['publication' => [2891]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['data']['taxonomy_verification']['success']);
        $this->assertTrue($result['data']['rollback']['success']);
        $this->assertSame('stage_readback', $result['data']['verification']['phase']);
        $this->assertStringContainsString("\$operation = 'update'", $manager->evaluatedPhp);
        $this->assertStringContainsString('$rollback($postId, $original)', $manager->evaluatedPhp);
    }

    private function managerWithResult(array $payload): FakeWordPressPostTaxonomyManager
    {
        return new FakeWordPressPostTaxonomyManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_CREATE:'.json_encode($payload),
        ]);
    }

    /** @return array<string, mixed> */
    private function verifiedData(int $attempts): array
    {
        return [
            'post_id' => 991,
            'post_status' => 'draft',
            'verification' => [
                'verified' => true,
                'phase' => 'final_readback',
                'checked_fields' => ['taxonomy:publication'],
            ],
            'taxonomy_verification' => [
                'success' => true,
                'taxonomies' => [
                    'publication' => [
                        'success' => true,
                        'attempts' => $attempts,
                        'expected' => [2891],
                        'actual' => [2891],
                    ],
                ],
            ],
        ];
    }
}

final class FakeWordPressPostTaxonomyManager extends WordPressManagerService
{
    public string $evaluatedPhp = '';

    public ?string $syntaxError = null;

    public function __construct(private readonly array $evaluation) {}

    public function normalizeTarget(array $target): array
    {
        return array_replace([
            'mode' => 'wptoolkit',
            'server' => null,
            'install_id' => 1,
            'default_author' => '',
        ], $target);
    }

    public function usesWpToolkit(array $target): bool
    {
        return true;
    }

    public function evaluatePhp(array $target, string $php): array
    {
        $this->evaluatedPhp = $php;
        try {
            eval('if (false) {'.$php.'}');
        } catch (\ParseError $error) {
            $this->syntaxError = $error->getMessage();
        }

        $marker = str_contains($php, "\$operation = 'update'")
            ? 'HEXA_TOOLKIT_UPDATE:'
            : 'HEXA_TOOLKIT_CREATE:';
        $separator = strpos((string) $this->evaluation['stdout'], ':');
        $payload = $separator === false ? '' : substr((string) $this->evaluation['stdout'], $separator + 1);

        return array_replace($this->evaluation, ['stdout' => $marker.$payload]);
    }
}
