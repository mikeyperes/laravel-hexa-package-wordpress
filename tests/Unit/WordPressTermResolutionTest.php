<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use PHPUnit\Framework\TestCase;

class WordPressTermResolutionTest extends TestCase
{
    public function test_rest_resolves_an_exact_term_outside_the_initial_collection_page(): void
    {
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(static function (
                string $siteUrl,
                string $username,
                string $applicationPassword,
                string $method,
                string $endpoint,
                array $body,
                array $query,
            ): array {
                self::assertSame('https://herforward.example', $siteUrl);
                self::assertSame('categories', $endpoint);
                self::assertSame('get', $method);
                self::assertSame([], $body);

                if (!array_key_exists('search', $query)) {
                    self::assertSame(['per_page' => 100], $query);

                    return ['success' => true, 'data' => [['id' => 1, 'name' => 'News']]];
                }

                self::assertSame('Law and Legal Services', $query['search']);
                self::assertSame(100, $query['per_page']);

                return ['success' => true, 'data' => [['id' => 8701, 'name' => 'Law and Legal Services']]];
            });

        $result = $this->manager($rest)->ensureTerms(
            $this->target(),
            ['Law and Legal Services'],
            'category',
        );

        $this->assertTrue($result['success']);
        $this->assertSame([8701], $result['term_ids']);
        $this->assertSame([[
            'name' => 'Law and Legal Services',
            'id' => 8701,
            'existed' => true,
            'error' => null,
        ]], $result['term_details']);
    }

    public function test_rest_recovers_the_existing_term_id_from_a_creation_conflict(): void
    {
        $responses = [
            ['success' => true, 'data' => []],
            ['success' => true, 'data' => []],
            [
                'success' => false,
                'status' => 400,
                'message' => 'A term with the name provided already exists in this taxonomy.',
                'data' => [
                    'code' => 'term_exists',
                    'data' => ['status' => 400, 'term_id' => 8701],
                ],
            ],
        ];
        $rest = $this->createMock(WordPressService::class);
        $rest->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(static function () use (&$responses): array {
                return array_shift($responses);
            });

        $result = $this->manager($rest)->ensureTerms(
            $this->target(),
            ['Law and Legal Services'],
            'category',
        );

        $this->assertTrue($result['success']);
        $this->assertSame([8701], $result['term_ids']);
        $this->assertTrue($result['term_details'][0]['existed']);
        $this->assertNull($result['term_details'][0]['error']);
    }

    private function manager(WordPressService $rest): WordPressManagerService
    {
        return new WordPressManagerService($this->createMock(WpToolkitService::class), $rest);
    }

    private function target(): array
    {
        return [
            'mode' => 'rest',
            'url' => 'https://herforward.example',
            'username' => 'publisher',
            'application_password' => 'application-password',
        ];
    }
}
