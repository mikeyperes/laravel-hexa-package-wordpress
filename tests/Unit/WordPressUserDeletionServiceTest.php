<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressUserDeletionService;
use Tests\TestCase;

class WordPressUserDeletionServiceTest extends TestCase
{
    public function test_toolkit_context_reports_content_and_excludes_other_batch_sources(): void
    {
        $manager = $this->manager(['normalizeTarget', 'usesWpToolkit', 'evaluatePhp']);
        $manager->method('normalizeTarget')->willReturn(['mode' => 'wptoolkit']);
        $manager->method('usesWpToolkit')->willReturn(true);
        $manager->expects($this->once())->method('evaluatePhp')->willReturn([
            'success' => true,
            'stdout' => 'HEXA_USER_DELETION_CONTEXT:' . json_encode([
                'success' => true,
                'source_user' => $this->user(10, 'Source'),
                'content_count' => 7,
                'administrators' => [$this->user(2, 'Admin', ['administrator']), $this->user(3, 'Also deleting')],
                'top_authors' => [$this->user(4, 'Top author')],
                'other_authors' => [],
            ]),
        ]);

        $context = (new WordPressUserDeletionService($manager))->context([], 10, [
            'exclude_user_ids' => [3],
        ]);

        $this->assertTrue($context['success']);
        $this->assertSame(7, $context['content_count']);
        $this->assertTrue($context['requires_reassignment']);
        $this->assertSame([3, 10], $context['excluded_user_ids']);
        $this->assertSame([2, 4], collect($context['candidate_groups'])->pluck('items')->flatten(1)->pluck('id')->all());
    }

    public function test_rest_context_requires_safe_reassignment_when_content_count_is_unavailable(): void
    {
        $manager = $this->manager(['normalizeTarget', 'usesWpToolkit', 'listUsers']);
        $manager->method('normalizeTarget')->willReturn(['mode' => 'rest']);
        $manager->method('usesWpToolkit')->willReturn(false);
        $manager->expects($this->exactly(2))->method('listUsers')->willReturnOnConsecutiveCalls(
            ['success' => true, 'users' => [$this->user(10, 'Source')]],
            ['success' => true, 'users' => [$this->user(10, 'Source'), $this->user(2, 'Admin', ['administrator'])]],
        );

        $context = (new WordPressUserDeletionService($manager))->context([], 10);

        $this->assertTrue($context['success']);
        $this->assertNull($context['content_count']);
        $this->assertFalse($context['content_count_known']);
        $this->assertTrue($context['requires_reassignment']);
        $this->assertStringContainsString('complete content count', $context['message']);
    }

    public function test_delete_rejects_missing_required_assignment(): void
    {
        $manager = $this->restManagerForDelete();
        $manager->expects($this->never())->method('deleteUser');

        $result = (new WordPressUserDeletionService($manager))->delete([], 10);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Select another WordPress user', $result['message']);
    }

    public function test_delete_rejects_destination_that_does_not_exist(): void
    {
        $manager = $this->restManagerForDelete([
            'success' => true,
            'users' => [],
        ]);
        $manager->expects($this->never())->method('deleteUser');

        $result = (new WordPressUserDeletionService($manager))->delete([], 10, 2);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function test_delete_reassigns_content_after_destination_validation(): void
    {
        $manager = $this->restManagerForDelete([
            'success' => true,
            'users' => [$this->user(2, 'Destination')],
        ]);
        $manager->expects($this->once())
            ->method('deleteUser')
            ->with(['mode' => 'rest'], 10, 2)
            ->willReturn(['success' => true, 'message' => 'User deleted via REST.']);

        $result = (new WordPressUserDeletionService($manager))->delete([], 10, 2);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['reassigned_to_user_id']);
        $this->assertSame('Destination', $result['destination_user']['name']);
    }

    public function test_delete_explicitly_deletes_owned_content_without_reassignment(): void
    {
        $manager = $this->restManagerForDelete();
        $manager->expects($this->once())
            ->method('deleteUser')
            ->with(['mode' => 'rest'], 10, null)
            ->willReturn(['success' => true, 'message' => 'User and content deleted via REST.']);

        $result = (new WordPressUserDeletionService($manager))->delete([], 10, null, [
            'delete_content' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['reassigned_to_user_id']);
        $this->assertTrue($result['deleted_content']);
        $this->assertSame('delete', $result['content_action']);
    }

    private function restManagerForDelete(?array $destinationResult = null): WordPressManagerService
    {
        $manager = $this->manager(['normalizeTarget', 'usesWpToolkit', 'listUsers', 'deleteUser']);
        $manager->method('normalizeTarget')->willReturn(['mode' => 'rest']);
        $manager->method('usesWpToolkit')->willReturn(false);
        $returns = [
            ['success' => true, 'users' => [$this->user(10, 'Source')]],
            ['success' => true, 'users' => [$this->user(10, 'Source'), $this->user(2, 'Destination')]],
        ];
        if ($destinationResult !== null) $returns[] = $destinationResult;
        $manager->expects($this->exactly(count($returns)))
            ->method('listUsers')
            ->willReturnOnConsecutiveCalls(...$returns);
        return $manager;
    }

    private function manager(array $methods): WordPressManagerService
    {
        return $this->getMockBuilder(WordPressManagerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    private function user(int $id, string $name, array $roles = ['author']): array
    {
        return [
            'id' => $id,
            'ID' => $id,
            'display_name' => $name,
            'user_login' => strtolower(str_replace(' ', '-', $name)),
            'user_email' => 'user' . $id . '@example.com',
            'roles' => $roles,
            'post_count' => 2,
        ];
    }
}
