<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use PHPUnit\Framework\TestCase;

/**
 * Author profiles on external connections go through HexaWP Core's
 * `hexa-plugin-core/v1/users/{id}/profile` route: directly with an
 * Application Password, and through the signed HWS Base Tools bridge.
 */
class WordPressUserProfileRouteTest extends TestCase
{
    private const REST = ['mode' => 'rest', 'url' => 'https://example.org', 'username' => 'fixture', 'application_password' => 'fixture-password'];

    public function test_application_password_writes_meta_through_the_core_profile_route(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('requestRoute')
            ->with('https://example.org', 'fixture', 'fixture-password', 'POST', 'hexa-plugin-core/v1/users/7/profile', ['meta' => ['job_title' => 'Editor']], [], 60)
            ->willReturn(['success' => true, 'status' => 200, 'message' => 'ok', 'data' => ['rows' => [], 'meta' => []]]);
        $rest->expects($this->never())->method('request');

        $result = $this->manager($rest)->updateUserMeta(self::REST, 7, 'job_title', 'Editor');

        $this->assertTrue($result['success']);
    }

    public function test_hws_base_tools_signs_the_profile_route_with_an_operation_id(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('signedRequestRoute')
            ->with(
                'https://example.org',
                'hws_0123456789abcdef01234567',
                str_repeat('s', 64),
                'POST',
                'hws-base-tools/v1/external-publishing/users/7/profile',
                $this->callback(fn (array $body): bool => $body['avatar'] === ['media_id' => 55] && str_starts_with((string) $body['operation_id'], 'publish:')),
                [],
                60,
            )
            ->willReturn(['success' => true, 'status' => 200, 'message' => 'ok', 'data' => ['rows' => [], 'meta' => []]]);

        $manager = $this->manager($rest);
        $method = new \ReflectionMethod($manager, 'userProfileBridge');
        $result = $method->invoke($manager, [
            'mode' => 'hws_base_tools',
            'url' => 'https://example.org',
            'hws_key_id' => 'hws_0123456789abcdef01234567',
            'hws_api_secret' => str_repeat('s', 64),
        ], 7, ['avatar' => ['media_id' => 55]]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['unavailable']);
    }

    public function test_a_site_without_the_route_falls_back_to_plain_rest(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->method('requestRoute')->willReturn(['success' => false, 'status' => 404, 'message' => 'No route', 'data' => ['code' => 'rest_no_route']]);
        $rest->expects($this->once())->method('request')
            ->with('https://example.org', 'fixture', 'fixture-password', 'post', 'users/7', ['meta' => ['job_title' => 'Editor']])
            ->willReturn(['success' => true, 'status' => 200, 'message' => 'ok', 'data' => []]);

        $result = $this->manager($rest)->updateUserMeta(self::REST, 7, 'job_title', 'Editor');

        $this->assertTrue($result['success']);
        $this->assertSame('User meta updated via REST.', $result['message']);
    }

    public function test_a_refused_write_is_reported_and_never_retried_over_plain_rest(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->method('requestRoute')->willReturn([
            'success' => false,
            'status' => 403,
            'message' => 'The meta key wp_capabilities cannot be written through the profile bridge.',
            'data' => ['code' => 'hexa_user_profile_meta_forbidden'],
        ]);
        $rest->expects($this->never())->method('request');

        $result = $this->manager($rest)->updateUserMeta(self::REST, 7, 'wp_capabilities', 'a:0:{}');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cannot be written', $result['message']);
    }

    private function manager(WordPressService $rest): WordPressManagerService
    {
        return new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);
    }
}
