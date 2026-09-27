<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for BUGLOG.md JOURNALIST-BUG-001 and JOURNALIST-BUG-002.
 */
class WordPressUserProfileReadTest extends TestCase
{
    public function test_toolkit_profile_is_one_evaluation_scoped_to_the_requested_user(): void
    {
        $manager = new FakeWordPressUserProfileManager($this->profilePayload());

        $result = $manager->getUserProfile(['mode' => 'wptoolkit'], 7, true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $manager->evaluations, 'A profile read must bootstrap WordPress exactly once.');
        $this->assertStringContainsString('$args["include"]=[7];', $manager->evaluatedPhp);
        $this->assertStringContainsString('post_author IN (7)', $manager->evaluatedPhp);
        $this->assertStringContainsString('HEXA_USER_PROFILE:', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('HEXA_USER_LIST:', $manager->evaluatedPhp);
    }

    public function test_credential_meta_is_excluded_inside_wordpress(): void
    {
        $manager = new FakeWordPressUserProfileManager($this->profilePayload());

        $manager->getUserProfile(['mode' => 'wptoolkit'], 7, true);

        $this->assertMatchesRegularExpression('/\$protectedMeta=array \(\s*0 => \'session_tokens\',\s*1 => \'_application_passwords\',\s*\);/', $manager->evaluatedPhp);
        $this->assertStringContainsString('if (in_array((string) $metaKey, $protectedMeta, true)) { continue; }', $manager->evaluatedPhp);
    }

    public function test_user_meta_is_merged_into_the_profile_like_the_previous_reader(): void
    {
        $manager = new FakeWordPressUserProfileManager($this->profilePayload());

        $data = $manager->getUserProfile(['mode' => 'wptoolkit'], 7, true)['data'];

        $this->assertSame('7', $data['ID']);
        $this->assertSame('jane', $data['user_login']);
        $this->assertSame('Jane writes about markets.', $data['description']);
        $this->assertSame('55', $data['wp_user_avatar']);
        $this->assertSame('legacy_avatar_meta', $data['avatar_provider']);
        $this->assertArrayNotHasKey('session_tokens', $data);
    }

    public function test_missing_user_and_failed_evaluation_are_reported(): void
    {
        $missing = new FakeWordPressUserProfileManager(['success' => true, 'stdout' => 'HEXA_USER_PROFILE:' . json_encode(['rows' => [], 'meta' => [], 'legacy_avatar_url' => '', 'avatar_provider' => 'legacy_avatar_meta'])]);
        $this->assertSame('WordPress user #7 was not found.', $missing->getUserProfile([], 7, true)['message']);

        $failed = new FakeWordPressUserProfileManager(['success' => false, 'message' => 'SSH connection failed']);
        $this->assertFalse($failed->getUserProfile([], 7, true)['success']);
        $this->assertSame(1, $failed->evaluations);
    }

    public function test_full_inventory_query_is_unchanged(): void
    {
        $manager = new FakeWordPressUserProfileManager(['success' => true, 'stdout' => 'HEXA_USER_LIST:[]']);
        $php = (new \ReflectionMethod(WordPressManagerService::class, 'toolkitUserRowsPhp'))->invoke($manager);

        $this->assertStringContainsString('$args=["fields"=>"all","number"=>9999];$users=get_users($args);', $php);
        $this->assertStringNotContainsString('include', $php);
        $this->assertStringNotContainsString('post_author IN', $php);
        $this->assertStringEndsWith('echo "HEXA_USER_LIST:" . wp_json_encode($rows);', $php);
    }

    private function profilePayload(): array
    {
        return [
            'success' => true,
            'stdout' => 'HEXA_USER_PROFILE:' . json_encode([
                'rows' => [[
                    'id' => 7, 'ID' => 7, 'user_login' => 'jane', 'user_nicename' => 'jane', 'display_name' => 'Jane Doe',
                    'user_email' => 'jane@example.test', 'user_url' => '', 'roles' => ['author'],
                    'wp_user_avatar' => '', 'avatar_media_id' => '', 'wp_user_avatars' => '', 'simple_local_avatar' => '',
                    'avatar_url' => '', 'avatar_thumbnail_url' => '', 'avatar_full_url' => '', 'avatar_sizes' => [],
                    'author_url' => 'https://example.test/author/jane/', 'wp_admin_url' => 'https://example.test/wp-admin/user-edit.php?user_id=7',
                    'post_count' => 3, 'post_count_known' => true, 'content_count' => 3, 'content_count_known' => true,
                ]],
                'meta' => [
                    ['meta_key' => 'description', 'meta_value' => 'Jane writes about markets.'],
                    ['meta_key' => 'wp_user_avatar', 'meta_value' => '55'],
                ],
                'legacy_avatar_url' => 'https://example.test/wp-content/uploads/jane.jpg',
                'avatar_provider' => 'legacy_avatar_meta',
            ]),
        ];
    }
}

final class FakeWordPressUserProfileManager extends WordPressManagerService
{
    public string $evaluatedPhp = '';
    public int $evaluations = 0;

    public function __construct(private readonly array $evaluation) {}

    public function normalizeTarget(array $target): array
    {
        return array_replace(['mode' => 'wptoolkit', 'server' => null, 'install_id' => 1, 'url' => 'https://example.test', 'site_id' => 1], $target);
    }

    public function usesWpToolkit(array $target): bool
    {
        return true;
    }

    public function evaluatePhp(array $target, string $php): array
    {
        $this->evaluations++;
        $this->evaluatedPhp = $php;

        return $this->evaluation;
    }
}
