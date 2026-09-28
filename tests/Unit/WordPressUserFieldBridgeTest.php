<?php

namespace HexaPackageTests\WordPress;

use hexa_package_notion\Services\NotionService;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressUserFieldBridgeService;
use Tests\TestCase;

/** The journalist field bridge: Notion and a WordPress user side by side, both write directions. */
final class WordPressUserFieldBridgeTest extends TestCase
{
    private const FIELDS = [
        ['key' => 'display_name', 'label' => 'Display name', 'wp_field' => 'display_name', 'wp_type' => 'native', 'notion_fields' => ['Full Name', 'Name']],
        ['key' => 'description', 'label' => 'Biography', 'wp_field' => 'description', 'wp_type' => 'native', 'notion_fields' => ['Biography (Short)']],
        ['key' => 'urls_linkedin', 'label' => 'LinkedIn', 'wp_field' => 'urls_linkedin', 'wp_type' => 'usermeta', 'wp_aliases' => ['linkedin'], 'notion_fields' => ['LinkedIn URL']],
        ['key' => 'avatar', 'label' => 'Profile Photo', 'wp_field' => 'avatar_url', 'wp_type' => 'profile_photo', 'photo_bridge' => true, 'notion_to_wp' => false, 'notion_fields' => ['Headshot']],
    ];

    private BridgeNotion $notion;

    private BridgeWordPress $wordpress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notion = new BridgeNotion([
            'full name' => 'jane writer',
            'Biography (short)' => "Writes about tech.\n\nLives in Miami.",
            'LinkedIn URL' => 'https://linkedin.com/in/Jane/',
            'Headshot' => ['https://img.test/jane.jpg'],
        ]);
        $this->wordpress = new BridgeWordPress([
            'display_name' => 'Jane Writer',
            'description' => "Writes about tech.\r\n\r\nLives in  Miami.",
            'urls_linkedin' => 'https://linkedin.com/in/jane',
            'avatar_url' => 'https://site.test/avatar.jpg',
        ]);
    }

    public function test_rows_find_notion_fields_by_any_spelling_and_propose_only_real_differences(): void
    {
        $rows = collect($this->bridge()->payload(['mode' => 'rest'], 7, 'page-1', self::FIELDS)['rows'])->keyBy('key');

        $this->assertSame('full name', $rows['display_name']['notion_field'], 'matched "Full Name" by its normalized spelling');
        $this->assertSame('jane writer', $rows['display_name']['wp_proposed_value'], 'a case difference is a real change');
        $this->assertSame('', $rows['description']['wp_proposed_value'], 'line endings and spacing are not');
        $this->assertSame('', $rows['urls_linkedin']['wp_proposed_value'], 'URLs ignore case and a trailing slash');
        $this->assertTrue($rows['avatar']['is_photo_bridge']);
    }

    public function test_notion_to_wordpress_writes_every_alias_and_verifies_by_reading_back(): void
    {
        $this->notion->values['LinkedIn URL'] = 'https://linkedin.com/in/jane-writer';

        $result = $this->bridge()->push(['mode' => 'rest'], 7, 'page-1', self::FIELDS, 'urls_linkedin', 'notion_to_wp');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([['urls_linkedin', 'https://linkedin.com/in/jane-writer'], ['linkedin', 'https://linkedin.com/in/jane-writer']], $this->wordpress->metaWrites);

        $this->wordpress->ignoreWrites = true;
        $this->notion->values['LinkedIn URL'] = 'https://linkedin.com/in/someone-else';
        $failed = $this->bridge()->push(['mode' => 'rest'], 7, 'page-1', self::FIELDS, 'urls_linkedin', 'notion_to_wp');
        $this->assertFalse($failed['success']);
        $this->assertStringStartsWith('WordPress write did not verify for LinkedIn.', $failed['message']);
    }

    public function test_wordpress_to_notion_is_confirmed_by_notion_and_never_writes_a_photo(): void
    {
        $saved = $this->bridge()->push(['mode' => 'rest'], 7, 'page-1', self::FIELDS, 'display_name', 'wp_to_notion');
        $this->assertTrue($saved['success'], $saved['message']);
        $this->assertSame([['full name', 'Jane Writer']], $this->notion->writes);

        $this->notion->keeps = 'Jane W.';
        $refused = $this->bridge()->push(['mode' => 'rest'], 7, 'page-1', self::FIELDS, 'display_name', 'wp_to_notion');
        $this->assertFalse($refused['success']);
        $this->assertSame('Notion did not keep the new Display name. Sent Jane Writer; Notion has Jane W..', $refused['message']);

        $photo = $this->bridge()->push(['mode' => 'rest'], 7, 'page-1', self::FIELDS, 'avatar', 'wp_to_notion');
        $this->assertSame([false, 'Profile photos are copied with the photo picker, not this field.'], [$photo['success'], $photo['message']]);
        $this->assertCount(2, $this->notion->writes, 'no photo write reached Notion');
    }

    private function bridge(): WordPressUserFieldBridgeService
    {
        return new WordPressUserFieldBridgeService($this->notion, $this->wordpress);
    }
}

final class BridgeNotion extends NotionService
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public array $writes = [];

    /** A value Notion keeps instead of the one sent (null: keeps what was sent). */
    public ?string $keeps = null;

    public function __construct(public array $values)
    {
    }

    public function getPage(string $pageId): array
    {
        return ['success' => true, 'page' => ['id' => $pageId, 'properties' => $this->values]];
    }

    public function getPageRaw(string $pageId): array
    {
        return ['success' => false];
    }

    public function updatePageProperty(string $pageId, string $propertyName, mixed $value): array
    {
        $this->writes[] = [$propertyName, $value];
        $stored = $this->keeps ?? $value;
        $this->values[$propertyName] = $stored;

        return ['success' => true, 'page' => [], 'property_type' => 'rich_text', 'verified' => $this->keeps === null, 'stored_value' => $stored];
    }
}

final class BridgeWordPress extends WordPressManagerService
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public array $metaWrites = [];

    public bool $ignoreWrites = false;

    public function __construct(public array $user)
    {
    }

    public function getUserProfile(array $target, int $userId, bool $forceRefresh = false): array
    {
        return ['success' => true, 'data' => $this->user];
    }

    public function updateNativeField(array $target, string $objectType, int $objectId, string $field, string $value): array
    {
        if (!$this->ignoreWrites) {
            $this->user[$field] = $value;
        }

        return ['success' => true];
    }

    public function updateUserMeta(array $target, int $userId, string $key, mixed $value): array
    {
        $this->metaWrites[] = [$key, $value];
        if (!$this->ignoreWrites) {
            $this->user[$key] = $value;
        }

        return ['success' => true];
    }
}
