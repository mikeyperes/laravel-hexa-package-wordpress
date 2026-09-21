<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WordPressRestRouteTest extends TestCase
{
    public function test_plugin_author_lookup_uses_the_dedicated_signed_authors_route(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('signedRequestRoute')->with(
            'https://example.org',
            'hws_0123456789abcdef01234567',
            str_repeat('s', 64),
            'get',
            'hws-base-tools/v1/external-publishing/authors',
            [],
            [
                'per_page' => 100,
                'context' => 'edit',
            ],
            60,
        )->willReturn([
            'success' => true,
            'status' => 200,
            'message' => 'Signed plugin request succeeded.',
            'data' => [[
                'id' => 53,
                'name' => 'Humza Khan',
                'slug' => 'hakhan96',
                'email' => 'author@example.org',
                'roles' => ['author'],
            ]],
        ]);

        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);
        $result = $manager->listAuthors([
            'mode' => 'hws_base_tools',
            'url' => 'https://example.org',
            'hws_key_id' => 'hws_0123456789abcdef01234567',
            'hws_api_secret' => str_repeat('s', 64),
        ], true);

        $this->assertTrue($result['success']);
        $this->assertSame('hakhan96', $result['authors'][0]['user_login']);
        $this->assertSame(['author'], $result['authors'][0]['roles']);
    }

    public function test_native_rest_author_lookup_keeps_the_core_fields_filter(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('request')->with(
            'https://example.org',
            'fixture',
            'fixture-password',
            'get',
            'users',
            [],
            [
                'per_page' => 100,
                'context' => 'edit',
                '_fields' => 'id,name,slug,email,roles',
            ],
            60,
        )->willReturn([
            'success' => true,
            'status' => 200,
            'message' => 'REST request succeeded.',
            'data' => [[
                'id' => 53,
                'name' => 'Humza Khan',
                'slug' => 'hakhan96',
                'email' => 'author@example.org',
                'roles' => ['author'],
            ]],
        ]);

        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);
        $result = $manager->listAuthors([
            'mode' => 'rest',
            'url' => 'https://example.org',
            'username' => 'fixture',
            'application_password' => 'fixture-password',
        ], true);

        $this->assertTrue($result['success']);
        $this->assertSame('hakhan96', $result['authors'][0]['user_login']);
    }

    public function test_http_mode_uses_authenticated_namespaced_transport(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->once())->method('requestRoute')->with(
            'https://example.org', 'fixture', 'fixture-password', 'PUT', '/hexa-fixture/v1/items/source%3A1',
            ['operation_id' => 'op-1'], [], 60
        )->willReturn(['success' => false, 'status' => 409, 'data' => ['code' => 'conflict']]);
        $manager = new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);
        $result = $manager->requestRestRoute([
            'url' => 'https://example.org', 'username' => 'fixture', 'application_password' => 'fixture-password',
        ], 'PUT', '/hexa-fixture/v1/items/source%3A1', ['operation_id' => 'op-1']);
        $this->assertSame(409, $result['status']);
    }

    #[DataProvider('httpStatuses')]
    public function test_toolkit_retains_exact_status_and_does_not_replay_after_shutdown_failure(int $status): void
    {
        $manager = $this->getMockBuilder(WordPressManagerService::class)->disableOriginalConstructor()->onlyMethods(['evaluatePhp'])->getMock();
        $manager->expects($this->once())->method('evaluatePhp')->willReturn([
            'success' => false,
            'stdout' => 'Plugin notice'."\n".'HEXA_REST_ROUTE:'.json_encode(['status' => $status, 'data' => ['post_id' => 42, 'message' => 'Fixture result']])."\nShutdown notice",
        ]);
        $result = $manager->requestRestRoute($this->target(), 'PUT', '/hexa-fixture/v1/items/source%3A1', ['title' => 'Quotes \' and PHP $never_execute']);
        $this->assertSame($status, $result['status']);
        $this->assertSame(42, $result['data']['post_id']);
        $this->assertSame($status < 300, $result['success']);
    }

    public static function httpStatuses(): array
    {
        return [[201], [401], [403], [404], [409], [422], [500]];
    }

    public function test_missing_receipt_stays_unknown_with_one_execution(): void
    {
        $manager = $this->getMockBuilder(WordPressManagerService::class)->disableOriginalConstructor()->onlyMethods(['evaluatePhp'])->getMock();
        $manager->expects($this->once())->method('evaluatePhp')->willReturn(['success' => false, 'stdout' => 'Connection closed.']);
        $result = $manager->requestRestRoute($this->target(), 'PUT', '/hexa-fixture/v1/items/one');
        $this->assertFalse($result['success']);
        $this->assertNull($result['status']);
        $this->assertNull($result['data']);
    }

    public function test_missing_saved_actor_cannot_execute(): void
    {
        $manager = $this->getMockBuilder(WordPressManagerService::class)->disableOriginalConstructor()->onlyMethods(['evaluatePhp'])->getMock();
        $manager->expects($this->never())->method('evaluatePhp');
        $result = $manager->requestRestRoute(array_replace($this->target(), ['default_author' => '']), 'GET', '/hexa-fixture/v1/manifest');
        $this->assertSame(401, $result['status']);
    }

    #[DataProvider('invalidRoutes')]
    public function test_invalid_routes_never_reach_a_transport(string $route): void
    {
        $manager = $this->getMockBuilder(WordPressManagerService::class)->disableOriginalConstructor()->onlyMethods(['evaluatePhp'])->getMock();
        $manager->expects($this->never())->method('evaluatePhp');
        $result = $manager->requestRestRoute($this->target(), 'GET', $route);
        $this->assertSame(400, $result['status']);
    }

    public static function invalidRoutes(): array
    {
        return [['https://evil.example/path'], ['//evil.example/v1/path'], ['/fixture/v1/../users'], ['/fixture/v1/%2e%2e/users'], ['/fixture/v1/path%3Fuser=1'], ['/fixture/v1/path%0A'], ['/fixture/v1/path%5C']];
    }

    private function target(): array
    {
        $server = new WhmServer;
        $server->id = 99;
        return ['mode' => 'wptoolkit', 'url' => 'https://example.org', 'server' => $server, 'install_id' => 99, 'default_author' => 'fixture-actor'];
    }
}
