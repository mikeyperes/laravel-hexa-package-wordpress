<?php

namespace HexaPackageTests\WordPress;

use hexa_package_wordpress\Connections\Concerns\UsesWordPressConnection;
use hexa_package_wordpress\Models\WordPressConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/** The shared building block: a model reads and writes its site facts through the connection. */
final class UsesWordPressConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32))]);
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->string('type')->default('text');
            $table->timestamps();
        });
        foreach (['whm_servers', 'hosting_accounts'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('whm_server_id')->nullable();
                $table->string('username')->nullable();
                $table->timestamps();
            });
        }
        DB::table('whm_servers')->insert(['id' => 1]);
        DB::table('hosting_accounts')->insert(['id' => 5, 'whm_server_id' => 1, 'username' => 'siteuser']);
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000001_create_wordpress_connections_table.php')->up();
        Schema::create('tool_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('wordpress_connection_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_reads_writes_secrets_reports_and_serialization(): void
    {
        $site = ToolSite::create(['name' => 'Tool', 'address' => 'https://tool.example', 'kind' => 'rest', 'user' => 'api', 'password' => 'pw one', 'report' => ['ok' => true]]);

        $connection = WordPressConnection::query()->sole();
        $this->assertSame(['Tool', 'rest', 'api'], [$connection->label, $connection->transport, $connection->rest_username]);
        $this->assertSame(['ok' => true], $connection->capability_report['tool']);

        $fresh = ToolSite::query()->findOrFail($site->id);
        $this->assertSame('https://tool.example', $fresh->address);
        $this->assertSame('pw one', $fresh->password);
        $this->assertSame(['id', 'name', 'wordpress_connection_id', 'created_at', 'updated_at', 'address', 'kind', 'user', 'report', 'account', 'install'], array_keys($fresh->toArray()));

        $fresh->update(['password' => 'pw two', 'report' => ['ok' => false]]);
        $this->assertSame('pw two', ToolSite::query()->findOrFail($site->id)->password);
        $this->assertSame(['ok' => false], $connection->refresh()->capability_report['tool']);
    }

    public function test_hosting_account_supplies_server_and_cpanel_user_and_relations_are_read_only(): void
    {
        $site = ToolSite::create(['name' => 'Hosted', 'address' => 'https://hosted.example', 'kind' => 'wptoolkit', 'account' => 5, 'install' => 12]);
        $connection = WordPressConnection::query()->sole();
        $this->assertSame([1, 'siteuser', 12], [$connection->whm_server_id, $connection->cpanel_username, $connection->wordpress_install_id]);
        $this->assertSame(1, ToolSite::query()->findOrFail($site->id)->server->id);
        $this->assertSame('wptoolkit', $site->wordpressTarget()['mode']);

        $this->expectException(InvalidArgumentException::class);
        $site->server = null;
    }

    public function test_a_model_without_site_facts_needs_no_connection(): void
    {
        $site = ToolSite::create(['name' => 'Draft']);
        $this->assertNull($site->wordpress_connection_id);
        $this->assertNull($site->address);
        $this->assertSame(0, WordPressConnection::query()->count());
    }
}

final class ToolSite extends Model
{
    use UsesWordPressConnection;

    protected $table = 'tool_sites';

    protected $fillable = ['name', 'address', 'kind', 'user', 'password', 'report', 'account', 'install'];

    protected function wordpressConnectionAttributes(): array
    {
        return [
            'address' => 'site_url',
            'kind' => 'transport',
            'user' => 'rest_username',
            'password' => 'secret:application_password',
            'report' => 'report:tool',
            'account' => 'hosting_account_id',
            'install' => 'wordpress_install_id',
            'server' => 'relation:server',
        ];
    }
}
