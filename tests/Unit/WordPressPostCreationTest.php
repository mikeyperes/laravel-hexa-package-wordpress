<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use PHPUnit\Framework\TestCase;

class WordPressPostCreationTest extends TestCase
{
    public function test_toolkit_create_stages_and_verifies_every_supplied_field(): void
    {
        $excerpt = 'A unique excerpt with "quotes", an apostrophe, and exact punctuation.';
        $manager = new FakeWordPressPostCreationManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_CREATE:' . json_encode([
                'success' => true,
                'data' => [
                    'post_id' => 991,
                    'post_status' => 'publish',
                    'post_title' => 'Regression title',
                    'post_excerpt' => $excerpt,
                    'post_content_sha256' => hash('sha256', '<p>Regression body.</p>'),
                    'verification' => [
                        'verified' => true,
                        'checked_fields' => ['post_title', 'post_content', 'post_excerpt', 'post_status'],
                    ],
                ],
            ]),
        ]);

        $result = $manager->createPost(
            ['default_author' => 'campaign-editor'],
            'Regression title',
            '<p>Regression body.</p>',
            'publish',
            [
                'excerpt' => $excerpt,
                'date' => '2026-07-24 09:30:00',
                'categories' => [11, 12],
                'tags' => [21],
                'taxonomies' => ['publication' => [31]],
                'featured_media' => 41,
                'post_type' => 'post',
                'slug' => 'regression-title',
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame($excerpt, $result['data']['post_excerpt']);
        $this->assertTrue($result['data']['verification']['verified']);
        $this->assertStringContainsString(var_export($excerpt, true), $manager->evaluatedPhp);
        $this->assertStringContainsString('$core = []', $manager->evaluatedPhp);
        $this->assertStringContainsString('$requestedStatus === (string) $originalPost->post_status', $manager->evaluatedPhp);
        $this->assertStringContainsString('wp_insert_post($core, true)', $manager->evaluatedPhp);
        $this->assertStringContainsString('wp_set_object_terms($id, $termIds, $taxonomy, false)', $manager->evaluatedPhp);
        $this->assertStringContainsString('term_exists($termId, $taxonomy)', $manager->evaluatedPhp);
        $this->assertStringContainsString('wp_attachment_is_image($featuredMediaId)', $manager->evaluatedPhp);
        $this->assertStringContainsString('set_post_thumbnail($id, $featuredMediaId)', $manager->evaluatedPhp);
        $this->assertStringContainsString('$stageMismatches = $compare(', $manager->evaluatedPhp);
        $this->assertStringContainsString('$finalMismatches = $compare(', $manager->evaluatedPhp);
        $this->assertStringContainsString('hash("sha256", (string) $state["post_content"])', $manager->evaluatedPhp);
        $this->assertStringContainsString('sanitize_title((string) ($payload[$source]', $manager->evaluatedPhp);
        $this->assertStringContainsString("'author' => 'campaign-editor'", $manager->evaluatedPhp);
        $this->assertStringContainsString("'categories' =>", $manager->evaluatedPhp);
        $this->assertStringContainsString("'tags' =>", $manager->evaluatedPhp);
        $this->assertStringContainsString("'publication' =>", $manager->evaluatedPhp);
        $this->assertStringContainsString("'featured_media' => 41", $manager->evaluatedPhp);
        $relationshipWrite = strpos($manager->evaluatedPhp, '$writeErrors = !$isCreate ? $writeRelationships($postId) : [];');
        $coreWrite = strpos($manager->evaluatedPhp, '$writeResult = wp_insert_post($core, true);');
        $this->assertIsInt($relationshipWrite);
        $this->assertIsInt($coreWrite);
        $this->assertLessThan($coreWrite, $relationshipWrite, 'Updates must expose supplied relationships to WordPress publish guards before the core save.');
        $this->assertStringContainsString('$writeErrors = $isCreate ? $writeRelationships($postId) : [];', $manager->evaluatedPhp);
        $this->assertStringContainsString('wp_kses_post($value)', $manager->evaluatedPhp);
        $this->assertStringContainsString('$core["edit_date"] = true;', $manager->evaluatedPhp);
        $this->assertStringContainsString('"post_date_gmt"', $manager->evaluatedPhp);
        $this->assertStringContainsString('$finalExpected["post_date"] = $finalTransitionDate;', $manager->evaluatedPhp);
        $this->assertStringContainsString('$canonicalizePostDate', $manager->evaluatedPhp);
        $this->assertStringContainsString("\$matches[1] . ' ' . \$matches[2]", $manager->evaluatedPhp);
        $this->assertStringContainsString('wp_unique_post_slug(', $manager->evaluatedPhp);
        $this->assertStringContainsString('$finalExpected["post_name"] = $finalTransitionSlug;', $manager->evaluatedPhp);
        $this->assertStringContainsString('catch (\\Throwable $exception)', $manager->evaluatedPhp);
        $this->assertStringContainsString('get_post_field("post_status", $postId)', $manager->evaluatedPhp);
        $this->assertStringContainsString('"hook_errors" => array_values(array_filter(', $manager->evaluatedPhp);
    }

    public function test_toolkit_relationship_only_update_skips_core_write_and_preserves_post_date(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_UPDATE:' . json_encode([
                'success' => true,
                'data' => [
                    'post_id' => 77,
                    'post_status' => 'draft',
                    'featured_media' => 41,
                    'verification' => ['verified' => true],
                ],
            ]),
        ]);

        $result = $manager->updatePost([], 77, ['featured_media' => 41]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('$core = [];', $manager->evaluatedPhp);
        $this->assertStringContainsString('} elseif ($core === []) {', $manager->evaluatedPhp);
        $this->assertStringContainsString('$writeResult = $postId;', $manager->evaluatedPhp);
        $this->assertStringContainsString('Relationship-only changes must not trigger WordPress core date normalization.', $manager->evaluatedPhp);
        $this->assertLessThan(
            strpos($manager->evaluatedPhp, '} elseif ($core === []) {'),
            strpos($manager->evaluatedPhp, '$writeErrors = !$isCreate ? $writeRelationships($postId) : [];')
        );
    }

    public function test_toolkit_create_surfaces_field_verification_failure_and_draft_id(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_CREATE:' . json_encode([
                'success' => false,
                'message' => 'WordPress post verification failed during staging: post_excerpt.',
                'data' => [
                    'post_id' => 992,
                    'post_status' => 'draft',
                    'post_excerpt' => '',
                    'verification' => [
                        'verified' => false,
                        'phase' => 'stage_readback',
                        'mismatches' => ['post_excerpt' => ['expected' => 'This must be stored.', 'actual' => '']],
                    ],
                ],
            ]),
        ]);

        $result = $manager->createPost([], 'Failed excerpt', '<p>Body.</p>', 'publish', [
            'excerpt' => 'This must be stored.',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('verification failed', $result['message']);
        $this->assertSame(992, $result['data']['post_id']);
        $this->assertSame('draft', $result['data']['post_status']);
        $this->assertSame('', $result['data']['post_excerpt']);
        $this->assertSame('stage_readback', $result['data']['verification']['phase']);
    }

    public function test_toolkit_empty_categories_are_resolved_to_the_wordpress_default_before_verification(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_CREATE:' . json_encode([
                'success' => true,
                'data' => [
                    'post_id' => 993,
                    'post_status' => 'publish',
                    'verification' => ['verified' => true],
                ],
            ]),
        ]);

        $result = $manager->createPost([], 'Default category', '<p>Body.</p>', 'publish', [
            'categories' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('$defaultCategoryId = (int) get_option("default_category")', $manager->evaluatedPhp);
        $this->assertStringContainsString('$expectedTermChanges["category"] = [$defaultCategoryId]', $manager->evaluatedPhp);
        $this->assertLessThan(
            strpos($manager->evaluatedPhp, 'foreach ($expectedTermChanges as $taxonomy => $termIds)'),
            strpos($manager->evaluatedPhp, '$defaultCategoryId = (int) get_option("default_category")')
        );
    }

    public function test_toolkit_update_uses_native_readback_and_exact_database_rollback(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => true,
            'stdout' => 'HEXA_TOOLKIT_UPDATE:' . json_encode([
                'success' => false,
                'message' => 'WordPress changed one or more fields while finalizing the post: post_content.',
                'data' => [
                    'post_id' => 77,
                    'post_status' => 'publish',
                    'verification' => [
                        'verified' => false,
                        'phase' => 'final_readback',
                        'rollback' => ['success' => true],
                    ],
                ],
            ]),
        ]);

        $result = $manager->updatePost([], 77, [
            'title' => 'Updated title',
            'content' => '<p>Updated body with \\ a literal slash.</p>',
            'excerpt' => '',
            'categories' => [],
            'tags' => [],
            'featured_media' => 0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['data']['verification']['rollback']['success']);
        $this->assertStringContainsString('$operation = \'update\'', $manager->evaluatedPhp);
        $this->assertStringContainsString('$postId = 77;', $manager->evaluatedPhp);
        $this->assertStringContainsString('$wpdb->update($wpdb->posts, $core, ["ID" => $id])', $manager->evaluatedPhp);
        $this->assertStringContainsString('"post_content" => (string) $before["post_content"]', $manager->evaluatedPhp);
        $this->assertStringContainsString('clean_post_cache($id)', $manager->evaluatedPhp);
        $this->assertStringContainsString("'excerpt' => ''", $manager->evaluatedPhp);
        $this->assertStringContainsString("'featured_media' => 0", $manager->evaluatedPhp);
    }

    public function test_payload_presence_distinguishes_omitted_fields_from_explicit_clears(): void
    {
        $manager = new FakeWordPressPostCreationManager(['success' => false]);
        $payload = $manager->normalizeForTest([
            'excerpt' => '',
            'categories' => [],
            'tags' => [],
            'taxonomies' => ['publication' => []],
            'featured_media' => 0,
        ]);

        $this->assertTrue($payload['_provided']['excerpt']);
        $this->assertTrue($payload['_provided']['categories']);
        $this->assertTrue($payload['_provided']['tags']);
        $this->assertTrue($payload['_provided']['featured_media']);
        $this->assertFalse($payload['_provided']['content']);
        $this->assertSame('', $payload['excerpt']);
        $this->assertSame([], $payload['categories']);
        $this->assertSame([], $payload['tags']);
        $this->assertSame(0, $payload['featured_media']);
        $this->assertSame(['publication'], $payload['_provided_taxonomies']);
        $this->assertSame([], $payload['taxonomies']['publication']);
    }

    public function test_unparseable_toolkit_output_fails_closed(): void
    {
        $manager = new FakeWordPressPostCreationManager(['success' => true, 'stdout' => 'unrelated output']);

        $result = $manager->createPost([], 'Title', '<p>Body</p>', 'publish');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to parse', $result['message']);
        $this->assertNull($result['data']);
    }

    public function test_verified_marker_is_authoritative_when_shutdown_returns_nonzero(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => false,
            'stdout' => 'HEXA_TOOLKIT_CREATE:' . json_encode([
                'success' => true,
                'message' => 'Post created and verified.',
                'data' => [
                    'post_id' => 994,
                    'post_status' => 'publish',
                    'verification' => [
                        'verified' => true,
                        'phase' => 'final_readback',
                    ],
                ],
            ]) . "\nA shutdown hook returned an error.",
            'message' => 'Direct wp-cli eval failed after the marked result was emitted.',
            'exit_code' => 1,
        ]);

        $result = $manager->createPost([], 'Committed post', '<p>Committed body.</p>', 'publish');

        $this->assertTrue($result['success']);
        $this->assertSame(994, $result['data']['post_id']);
        $this->assertTrue($result['data']['verification']['verified']);
    }

    public function test_toolkit_post_lookup_honors_slug_search_and_page_after_nonzero_shutdown(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => false,
            'stdout' => 'HEXA_POST_LIST:' . json_encode([[
                'id' => 31328,
                'slug' => 'aave-labs-expands-defi-lending',
                'title' => ['rendered' => 'Aave Labs Expands DeFi Lending'],
            ]]) . "\nA shutdown hook returned an error.",
            'message' => 'Native evaluation returned a non-zero exit code.',
        ]);

        $result = $manager->listPosts([], [
            'slug' => 'aave-labs-expands-defi-lending',
            'search' => 'Aave Labs',
            'page' => 3,
            'per_page' => 17,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(31328, $result['data'][0]['id']);
        $this->assertStringContainsString('"posts_per_page"=>17', $manager->evaluatedPhp);
        $this->assertStringContainsString('"paged"=>3', $manager->evaluatedPhp);
        $this->assertStringContainsString('$args["name"]=\'aave-labs-expands-defi-lending\'', $manager->evaluatedPhp);
        $this->assertStringContainsString('$args["s"]=\'Aave Labs\'', $manager->evaluatedPhp);
    }

    public function test_toolkit_metadata_readback_is_marker_first_and_media_filter_free(): void
    {
        $manager = new FakeWordPressPostCreationManager([
            'success' => false,
            'stdout' => 'HEXA_POST_META_DETAILS:' . json_encode([
                'success' => true,
                'posts' => [31328 => [
                    'id' => 31328,
                    'post_id' => 31328,
                    'meta' => ['article_summary' => 'Verified summary'],
                ]],
            ]) . "\nA shutdown hook returned an error.",
            'message' => 'Native evaluation returned a non-zero exit code.',
        ]);

        $result = $manager->getPostMetaByIds([], [31328]);

        $this->assertTrue($result['success']);
        $this->assertSame('Verified summary', $result['posts'][31328]['meta']['article_summary']);
        $this->assertStringContainsString('get_post_meta($postId)', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('wp_get_attachment_url', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('wp_get_attachment_image_src', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('get_permalink', $manager->evaluatedPhp);
    }
}

final class FakeWordPressPostCreationManager extends WordPressManagerService
{
    public string $evaluatedPhp = '';

    public function __construct(private readonly array $evaluation)
    {
    }

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

        return $this->evaluation;
    }

    public function normalizeForTest(array $payload): array
    {
        $method = new \ReflectionMethod(WordPressManagerService::class, 'normalizePostPayload');
        $method->setAccessible(true);

        return $method->invoke($this, $payload);
    }
}
