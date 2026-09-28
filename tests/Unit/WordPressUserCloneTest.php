<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for BUGLOG.md JOURNALIST-BUG-005: journalist username
 * changes copy one WordPress user onto another with these two methods.
 */
class WordPressUserCloneTest extends TestCase
{
    public function test_export_reads_one_user_in_one_evaluation_and_leaves_out_sessions_and_app_passwords(): void
    {
        $snapshot = ['core' => ['ID' => 7, 'user_login' => 'jane'], 'meta' => ['description' => ['Bio']], 'skipped_meta_keys' => ['session_tokens']];
        $manager = new FakeWordPressCloneManager(['success' => true, 'stdout' => "noise\nHEXA_USER_CLONE_EXPORT:".json_encode(['success' => true, 'snapshot' => $snapshot])]);

        $result = $manager->exportUserCloneSnapshot(['mode' => 'wptoolkit'], 7);

        $this->assertSame(['success' => true, 'message' => 'WordPress user clone snapshot exported.', 'snapshot' => $snapshot], $result);
        $this->assertSame(1, $manager->evaluations);
        $this->assertStringContainsString('$userId = 7;', $manager->evaluatedPhp);
        $this->assertStringContainsString('"/(^|_)session_tokens$/", "/(^|_)application_passwords$/"', $manager->evaluatedPhp);
        $this->assertStringNotContainsString('user_pass', $manager->evaluatedPhp);
    }

    public function test_export_refuses_bad_input_and_reports_failures(): void
    {
        $manager = new FakeWordPressCloneManager(['success' => true, 'stdout' => '']);
        $this->assertSame('A source WordPress user ID is required.', $manager->exportUserCloneSnapshot([], 0)['message']);
        $this->assertSame(0, $manager->evaluations);

        $rest = new FakeWordPressCloneManager(['success' => true, 'stdout' => ''], toolkit: false);
        $this->assertSame('Full WordPress user cloning requires WP Toolkit PHP access.', $rest->exportUserCloneSnapshot([], 7)['message']);
        $this->assertSame(0, $rest->evaluations);

        $failed = new FakeWordPressCloneManager(['success' => false, 'message' => 'SSH refused.']);
        $this->assertSame(['success' => false, 'message' => 'SSH refused.', 'snapshot' => null], $failed->exportUserCloneSnapshot([], 7));

        $missing = new FakeWordPressCloneManager(['success' => true, 'stdout' => 'HEXA_USER_CLONE_EXPORT:'.json_encode(['success' => false, 'message' => 'Source WordPress user not found.'])]);
        $this->assertSame('Source WordPress user not found.', $missing->exportUserCloneSnapshot([], 7)['message']);

        $garbage = new FakeWordPressCloneManager(['success' => true, 'stdout' => 'Fatal error']);
        $this->assertSame('Failed to parse WordPress user clone export.', $garbage->exportUserCloneSnapshot([], 7)['message']);
    }

    public function test_apply_embeds_the_snapshot_as_data_that_cannot_break_out_of_the_php(): void
    {
        $snapshot = [
            'core' => ['user_login' => 'jane', 'display_name' => "O'Brien'; exit('pwned'); //", 'roles' => ['author']],
            'meta' => ['description' => ['__OVERRIDES__ and "quotes" and \\backslashes'], 'nickname' => ['Jay']],
            'skipped_meta_keys' => ['session_tokens'],
        ];
        $overrides = ['user_email' => 'jane+rename1@example.test', 'preserve_user_nicename' => false];
        $manager = new FakeWordPressCloneManager(['success' => true, 'stdout' => 'HEXA_USER_CLONE_APPLY:'.json_encode(['success' => true, 'data' => ['copied_meta_keys' => ['description', 'nickname'], 'skipped_meta_keys' => ['session_tokens']]])]);

        $result = $manager->applyUserCloneSnapshot(['mode' => 'wptoolkit'], 9001, $snapshot, $overrides);

        $this->assertTrue($result['success']);
        $this->assertSame(['description', 'nickname'], $result['data']['copied_meta_keys']);
        // Run only the three assignments: they must rebuild exactly the given values.
        $assignments = substr($manager->evaluatedPhp, 0, strpos($manager->evaluatedPhp, '$user = get_userdata'));
        $values = (static function (string $code): array {
            eval($code);

            return [$targetUserId, $snapshot, $overrides];
        })($assignments);
        $this->assertSame([9001, $snapshot, $overrides], $values);
        $this->assertStringContainsString('preg_match("/(^|_)(session_tokens|application_passwords)$/", $key)', $manager->evaluatedPhp);
    }

    public function test_apply_refuses_bad_input_and_reports_failures(): void
    {
        $manager = new FakeWordPressCloneManager(['success' => true, 'stdout' => '']);
        $this->assertSame(['success' => false, 'message' => 'A target WordPress user ID is required.', 'data' => []], $manager->applyUserCloneSnapshot([], 0, []));

        $rejected = new FakeWordPressCloneManager(['success' => true, 'stdout' => 'HEXA_USER_CLONE_APPLY:'.json_encode(['success' => false, 'message' => 'Sorry, that email address is already used!'])]);
        $this->assertSame('Sorry, that email address is already used!', $rejected->applyUserCloneSnapshot([], 9001, [])['message']);

        $garbage = new FakeWordPressCloneManager(['success' => true, 'stdout' => '']);
        $this->assertSame('Failed to parse WordPress user clone apply.', $garbage->applyUserCloneSnapshot([], 9001, [])['message']);
    }
}

final class FakeWordPressCloneManager extends WordPressManagerService
{
    public string $evaluatedPhp = '';

    public int $evaluations = 0;

    public function __construct(private readonly array $evaluation, private readonly bool $toolkit = true) {}

    public function normalizeTarget(array $target): array
    {
        return array_replace(['mode' => 'wptoolkit', 'server' => null, 'install_id' => 1, 'url' => 'https://example.test'], $target);
    }

    public function usesWpToolkit(array $target): bool
    {
        return $this->toolkit;
    }

    public function evaluatePhp(array $target, string $php): array
    {
        $this->evaluations++;
        $this->evaluatedPhp = $php;

        return $this->evaluation;
    }
}
