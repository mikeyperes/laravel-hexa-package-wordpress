<?php

namespace Tests\Unit;

use hexa_package_wordpress\Data\WordPressPostMutation;
use hexa_package_wordpress\Services\WordPressManagerService;
use PHPUnit\Framework\TestCase;

class WordPressPostMutationTest extends TestCase
{
    public function test_mutation_normalizes_aliases_and_legacy_taxonomy_fields(): void
    {
        $mutation = WordPressPostMutation::fromArray([
            'category_ids' => ['12', 11, 12],
            'tag_ids' => [21],
            'featured_media_id' => '41',
            'post_name' => 'Exact Slug',
            'publication' => [32, '31', 32],
        ]);

        $this->assertTrue($mutation->isValid());
        $this->assertSame([11, 12], $mutation->categories);
        $this->assertSame([21], $mutation->tags);
        $this->assertSame(41, $mutation->featuredMedia);
        $this->assertSame('Exact Slug', $mutation->slug);
        $this->assertSame(['publication' => [31, 32]], $mutation->taxonomies);
        $this->assertSame(['publication'], $mutation->providedTaxonomies);
        $this->assertTrue($mutation->provided['categories']);
        $this->assertTrue($mutation->provided['featured_media']);
    }

    public function test_mutation_rejects_unsafe_boundary_values_before_remote_execution(): void
    {
        $mutation = WordPressPostMutation::fromArray([
            'post_type' => '../posts',
            'featured_media' => -2,
            'taxonomies' => [
                'Invalid/Name' => [3],
                'publication' => [4, 'not-an-id'],
            ],
        ]);

        $this->assertFalse($mutation->isValid());
        $this->assertStringContainsString('Post type must be a valid WordPress post-type key', implode(' ', $mutation->validationErrors));
        $this->assertStringContainsString('Featured media must be zero or a positive integer', implode(' ', $mutation->validationErrors));
        $this->assertStringContainsString('Taxonomy keys must use valid lowercase WordPress taxonomy names', implode(' ', $mutation->validationErrors));
        $this->assertStringContainsString('Taxonomy publication must contain only positive integer IDs', implode(' ', $mutation->validationErrors));
    }

    public function test_default_author_adapter_marks_the_field_as_explicitly_provided(): void
    {
        $mutation = WordPressPostMutation::fromArray([])->withDefaultAuthor('campaign-editor');
        $payload = $mutation->toArray();

        $this->assertSame('campaign-editor', $payload['author']);
        $this->assertTrue($payload['_provided']['author']);
    }

    public function test_legacy_normalized_array_adapter_matches_the_typed_mutation(): void
    {
        $input = [
            'title' => 'Adapter title',
            'excerpt' => '',
            'categories' => [],
            'taxonomies' => ['publication' => [2891]],
        ];
        $manager = new FakeWordPressPostMutationNormalizer;

        $this->assertSame(
            WordPressPostMutation::fromArray($input)->toArray(),
            $manager->normalizeForTest($input),
        );
    }

    public function test_invalid_mutation_fails_closed_without_remote_evaluation(): void
    {
        $manager = new FakeWordPressPostMutationNormalizer;

        $result = $manager->updatePost([], 991, [
            'taxonomies' => ['publication' => ['not-an-id']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid WordPress post mutation', $result['message']);
        $this->assertNotEmpty($result['data']['validation_errors']);
        $this->assertSame(0, $manager->evaluationCount);
    }
}

final class FakeWordPressPostMutationNormalizer extends WordPressManagerService
{
    public int $evaluationCount = 0;

    public function __construct() {}

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
        $this->evaluationCount++;

        return ['success' => false, 'stdout' => ''];
    }

    public function normalizeForTest(array $payload): array
    {
        $method = new \ReflectionMethod(WordPressManagerService::class, 'normalizePostPayload');
        $method->setAccessible(true);

        return $method->invoke($this, $payload);
    }
}
