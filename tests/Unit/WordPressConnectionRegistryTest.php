<?php

namespace HexaPackageTests\WordPress;

use hexa_package_wordpress\Connections\WordPressConnectionRegistry;
use hexa_package_wordpress\Models\WordPressConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/** Shared WordPress connections: one row per site, secrets only in the credential vault. */
final class WordPressConnectionRegistryTest extends TestCase
{
    private WordPressConnectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->string('type')->default('text');
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        foreach (['whm_servers', 'hosting_accounts'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('whm_server_id')->nullable();
                $table->timestamps();
            });
        }
        DB::table('whm_servers')->insert(['id' => 1, 'name' => 'server 236']);
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000001_create_wordpress_connections_table.php')->up();
        $this->registry = app(WordPressConnectionRegistry::class);
    }

    public function test_a_wp_toolkit_site_is_one_row_per_server_and_install(): void
    {
        $first = $this->registry->remember(['label' => 'Her Forward', 'url' => 'https://herforward.com/', 'whm_server_id' => 1, 'wordpress_install_id' => '35', 'cpanel_username' => 'herforward']);
        $again = $this->registry->remember(['label' => 'HerForward (journalists)', 'site_url' => 'https://www.herforward.com', 'whm_server_id' => 1, 'wordpress_install_id' => 35, 'wordpress_path' => '/home/herforward/public_html']);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WordPressConnection::query()->count());
        $this->assertSame('Her Forward', $again->label, 'known facts are not erased');
        $this->assertSame('/home/herforward/public_html', $again->wordpress_path, 'blank facts are filled');
        $this->assertSame('herforward.com', $again->host);
        $this->assertSame('wptoolkit', $again->transport);
        $this->assertTrue($again->usesWpToolkit());
    }

    public function test_a_rest_site_is_identified_by_host_and_keeps_secrets_in_the_vault(): void
    {
        $connection = $this->registry->remember(['name' => 'Top Hustler', 'url' => 'https://www.tophustler.com', 'connection_type' => 'wp_rest_api', 'wp_username' => 'hexaprwire']);
        $this->registry->storeSecret($connection, WordPressConnectionRegistry::SECRET_APPLICATION_PASSWORD, 'abcd efgh ijkl');

        $this->assertSame('rest', $connection->transport);
        $this->assertSame($connection->id, $this->registry->remember(['url' => 'tophustler.com'])->id);
        $this->assertStringNotContainsString('abcd', json_encode(DB::table('wordpress_connections')->first()));
        $this->assertStringNotContainsString('abcd', (string) DB::table('settings')->value('value'), 'stored encrypted');

        $target = $this->registry->target($connection, ['site_id' => 43]);
        $this->assertSame('rest', $target['mode']);
        $this->assertSame('hexaprwire', $target['username']);
        $this->assertSame('abcd efgh ijkl', $target['application_password']);
        $this->assertSame(43, $target['site_id']);

        $this->registry->storeSecret($connection, WordPressConnectionRegistry::SECRET_APPLICATION_PASSWORD, '');
        $this->assertFalse($this->registry->hasSecret($connection, WordPressConnectionRegistry::SECRET_APPLICATION_PASSWORD));
    }

    public function test_a_wp_toolkit_target_reads_no_secrets(): void
    {
        $connection = $this->registry->remember(['url' => 'https://mashviral.com', 'whm_server_id' => 1, 'wordpress_install_id' => 284, 'cpanel_username' => 'mashviral', 'wordpress_path' => '/home/mashviral/public_html']);
        DB::enableQueryLog();
        $target = $this->registry->target($connection);

        $this->assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'settings')));
        $this->assertSame(284, $target['install_id']);
        $this->assertSame('mashviral', $target['cpanel_user']);
        $this->assertSame('/home/mashviral/public_html', $target['wp_path']);
        $this->assertSame($connection->id, $target['connection_id']);
    }

    public function test_overwrite_replaces_known_facts_and_checks_are_recorded(): void
    {
        $connection = $this->registry->remember(['label' => 'Old', 'url' => 'https://site.test']);
        $this->registry->remember(['label' => 'New', 'url' => 'https://site.test'], overwrite: true);
        $this->registry->recordCheck($connection->refresh(), false, 'HTTP 401', ['rest' => false]);

        $connection->refresh();
        $this->assertSame('New', $connection->label);
        $this->assertSame('error', $connection->status);
        $this->assertSame('HTTP 401', $connection->last_error);
        $this->assertSame(['rest' => false], $connection->capability_report);
        $this->assertNotNull($connection->capability_checked_at);

        $this->registry->recordCheck($connection, true, null, ['plugins' => ['acf' => true]]);
        $this->assertSame(['rest' => false, 'plugins' => ['acf' => true]], $connection->refresh()->capability_report, 'sections merge');
        $this->assertSame('connected', $connection->status);
        $this->assertNull($connection->last_error);
    }

    public function test_bad_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->remember(['label' => 'No address']);
    }

    public function test_unknown_secret_names_and_transports_are_rejected(): void
    {
        $connection = $this->registry->remember(['url' => 'https://site.test']);
        $this->assertThrows(fn () => $this->registry->storeSecret($connection, 'login_password', 'x'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->registry->remember(['url' => 'https://x.test', 'transport' => 'ftp']), InvalidArgumentException::class);
    }
}
