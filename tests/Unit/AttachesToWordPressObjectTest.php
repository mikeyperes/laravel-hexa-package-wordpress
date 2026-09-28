<?php

namespace HexaPackageTests\WordPress;

use hexa_package_wordpress\Connections\Concerns\AttachesToWordPressObject;
use hexa_package_wordpress\Connections\Concerns\UsesWordPressConnection;
use hexa_package_wordpress\Connections\WordPressSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/** The shared person-to-page link: WordPress copy, sync state and edit link. */
final class AttachesToWordPressObjectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 12:00:00');
        foreach (['whm_servers', 'hosting_accounts'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('whm_server_id')->nullable();
                $table->timestamps();
            });
        }
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000001_create_wordpress_connections_table.php')->up();
        DB::table('wordpress_connections')->insert(['id' => 7, 'label' => 'Site', 'site_url' => 'https://www.site.test/', 'host' => 'site.test', 'transport' => 'rest']);
        Schema::create('link_sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('wordpress_connection_id')->nullable();
        });
        DB::table('link_sites')->insert(['id' => 1, 'wordpress_connection_id' => 7]);
        Schema::create('user_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('link_site_id');
            $table->string('wp_user_id')->nullable();
            $table->json('wp_snapshot')->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });
        Schema::create('post_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('link_site_id');
            $table->string('wp_post_id')->nullable();
            $table->json('remote_post')->nullable();
            $table->json('remote_meta')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->timestamp('outdated_at')->nullable();
            $table->text('sync_message')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_pull_replaces_or_merges_the_copy_and_stamps_the_sync_time(): void
    {
        $link = UserLink::create(['link_site_id' => 1, 'wp_user_id' => '42', 'wp_snapshot' => ['display_name' => 'Old', 'roles' => ['author']]]);

        $link->forceFill($link->wordpressPullAttributes(['user' => ['display_name' => 'New']]))->save();
        $this->assertSame(['display_name' => 'New'], $link->fresh()->wp_snapshot);
        $this->assertSame('2026-09-28 12:00:00', $link->fresh()->last_synced_at->toDateTimeString());

        $link->forceFill($link->wordpressPullAttributes(['user' => ['user_url' => 'https://x.test']], merge: true))->save();
        $this->assertSame(['display_name' => 'New', 'user_url' => 'https://x.test'], $link->fresh()->wp_snapshot);
    }

    public function test_credentials_and_sessions_never_reach_a_stored_copy(): void
    {
        $link = UserLink::create(['link_site_id' => 1, 'wp_user_id' => '42']);
        $link->forceFill($link->wordpressPullAttributes(['user' => [
            'display_name' => 'Jane',
            'user_pass' => '$P$hash',
            'user_activation_key' => 'k',
            'meta' => ['session_tokens' => ['a' => 1], '_application_passwords' => ['x'], 'nickname' => 'jj'],
            'meta_rows' => [['meta_key' => 'session_tokens', 'meta_value' => 's'], ['meta_key' => 'bio', 'meta_value' => 'b']],
            'SESSION_TOKENS' => 'upper',
        ]]))->save();

        $stored = json_encode($link->fresh()->wp_snapshot);
        foreach (['$P$hash', 'user_activation_key', 'session_tokens', '_application_passwords', 'SESSION_TOKENS'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, $secret);
        }
        $this->assertSame(['nickname' => 'jj'], $link->fresh()->wp_snapshot['meta']);
        $this->assertSame([['meta_key' => 'bio', 'meta_value' => 'b']], $link->fresh()->wp_snapshot['meta_rows'], 'lists stay lists');
        $this->assertSame(['a' => ['b' => 1]], WordPressSnapshot::clean(['a' => ['b' => 1, 'user_pass' => 'x']]));
    }

    public function test_push_outdated_and_removed_set_the_sync_state_of_a_post_link(): void
    {
        $link = PostLink::create(['link_site_id' => 1, 'wp_post_id' => '9', 'remote_post' => ['post_title' => 'Old', 'post_name' => 'old'], 'sync_status' => 'outdated', 'outdated_at' => now()->subDay()]);

        $link->forceFill($link->wordpressPushAttributes(['post' => ['post_title' => 'New']], 'Pushed.'))->save();
        $fresh = $link->fresh();
        $this->assertSame(['post_title' => 'New', 'post_name' => 'old'], $fresh->remote_post, 'a push merges');
        $this->assertSame(['synced', null, 'Pushed.'], [$fresh->sync_status, $fresh->outdated_at, $fresh->sync_message]);
        $this->assertNotNull($fresh->last_pushed_at);
        $this->assertNotNull($fresh->last_scanned_at);
        $this->assertNull($fresh->last_pulled_at, 'a push is not a pull');

        $fresh->forceFill($fresh->wordpressOutdatedAttributes('Edited here.', ['meta' => ['k' => 'v']]))->save();
        $fresh = $fresh->fresh();
        $this->assertSame(['outdated', 'Edited here.', ['k' => 'v']], [$fresh->sync_status, $fresh->sync_message, $fresh->remote_meta]);
        $this->assertSame('2026-09-28 12:00:00', $fresh->outdated_at->toDateTimeString());

        $fresh->forceFill($fresh->wordpressRemovedAttributes('Deleted on WordPress.'))->save();
        $fresh = $fresh->fresh();
        $this->assertSame(['deleted', 'Deleted on WordPress.'], [$fresh->sync_status, $fresh->sync_message]);
        $this->assertNotNull($fresh->outdated_at, 'removal keeps the outdated time');
    }

    public function test_a_pull_marks_the_post_link_synced_and_can_mark_many_outdated_in_one_update(): void
    {
        $a = PostLink::create(['link_site_id' => 1, 'wp_post_id' => '1', 'sync_status' => 'outdated', 'outdated_at' => now()]);
        PostLink::create(['link_site_id' => 1, 'wp_post_id' => '2']);

        $a->forceFill($a->wordpressPullAttributes(['post' => ['post_title' => 'T'], 'meta' => []], message: 'Imported.'))->save();
        $this->assertSame(['synced', null, 'Imported.'], [$a->fresh()->sync_status, $a->fresh()->outdated_at, $a->fresh()->sync_message]);

        $this->assertSame(2, PostLink::markWordPressOutdated(PostLink::query(), 'Profile merged.'));
        $this->assertSame(['outdated', 'outdated'], PostLink::query()->orderBy('id')->pluck('sync_status')->all());
        $this->assertSame(['Profile merged.'], PostLink::query()->distinct()->pluck('sync_message')->all());
    }

    public function test_a_link_without_sync_columns_only_stamps_its_pull_time(): void
    {
        $link = UserLink::create(['link_site_id' => 1, 'wp_user_id' => '42']);
        $this->assertSame(['wp_snapshot', 'last_synced_at'], array_keys($link->wordpressPushAttributes(['user' => []], 'ignored')));
        $this->assertSame([], $link->wordpressOutdatedAttributes('ignored'));
    }

    public function test_edit_links_ids_and_sections(): void
    {
        $user = UserLink::create(['link_site_id' => 1, 'wp_user_id' => '42', 'wp_snapshot' => ['a' => 1]]);
        $post = PostLink::create(['link_site_id' => 1, 'wp_post_id' => '9', 'remote_post' => ['t' => 1]]);

        $this->assertSame('https://www.site.test/wp-admin/user-edit.php?user_id=42', $user->wordpressEditUrl());
        $this->assertSame('https://www.site.test/wp-admin/post.php?post=9&action=edit', $post->wordpressEditUrl());
        $this->assertSame('', PostLink::create(['link_site_id' => 1])->wordpressEditUrl());
        $this->assertSame('42', $user->remoteObjectId());
        $this->assertSame(['a' => 1], $user->remoteSnapshot('user'));
        $this->assertSame(['post' => ['t' => 1], 'meta' => []], $post->remoteSnapshot());

        $this->expectException(InvalidArgumentException::class);
        $post->wordpressPullAttributes(['acf' => []]);
    }
}

final class LinkSite extends Model
{
    use UsesWordPressConnection;

    public $timestamps = false;

    protected $table = 'link_sites';

    protected function wordpressConnectionAttributes(): array
    {
        return ['site_url' => 'site_url'];
    }
}

final class UserLink extends Model
{
    use AttachesToWordPressObject;

    public $timestamps = false;

    protected $table = 'user_links';

    protected $guarded = [];

    protected $casts = ['wp_snapshot' => 'array', 'last_synced_at' => 'datetime'];

    public function site()
    {
        return $this->belongsTo(LinkSite::class, 'link_site_id');
    }

    protected function wordpressAttachment(): array
    {
        return ['site' => 'site', 'object' => 'user', 'remote_id' => 'wp_user_id', 'snapshot' => ['user' => 'wp_snapshot'], 'pulled_at' => 'last_synced_at'];
    }
}

final class PostLink extends Model
{
    use AttachesToWordPressObject;

    public $timestamps = false;

    protected $table = 'post_links';

    protected $guarded = [];

    protected $casts = [
        'remote_post' => 'array', 'remote_meta' => 'array', 'last_pulled_at' => 'datetime', 'last_pushed_at' => 'datetime',
        'last_scanned_at' => 'datetime', 'outdated_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(LinkSite::class, 'link_site_id');
    }

    protected function wordpressAttachment(): array
    {
        return [
            'site' => 'site', 'object' => 'post', 'remote_id' => 'wp_post_id',
            'snapshot' => ['post' => 'remote_post', 'meta' => 'remote_meta'],
            'pulled_at' => 'last_pulled_at', 'pushed_at' => 'last_pushed_at', 'checked_at' => 'last_scanned_at',
            'sync' => ['status' => 'sync_status', 'outdated_at' => 'outdated_at', 'message' => 'sync_message'],
        ];
    }
}
