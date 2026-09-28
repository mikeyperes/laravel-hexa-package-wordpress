<?php

namespace hexa_package_wordpress\Models;

use hexa_package_whm\Models\HostingAccount;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WordPress site we can reach and how we reach it.
 *
 * The row holds only non-secret routing facts. The application password and
 * HWS Base Tools secret live in Core's CredentialService under
 * {@see WordPressConnection::credentialSlug()}; use
 * {@see \hexa_package_wordpress\Connections\WordPressConnectionRegistry} to
 * read or write them and to build a WordPressManagerService target.
 *
 * @property int $id
 * @property string $label
 * @property string $site_url
 * @property string $host
 * @property string $transport  wptoolkit | rest | hws_base_tools
 * @property int|null $whm_server_id
 * @property int|null $hosting_account_id
 * @property string|null $cpanel_username
 * @property int|null $wordpress_install_id
 * @property string|null $wordpress_path
 * @property string|null $rest_username
 * @property string|null $hws_key_id
 * @property string $status  unknown | connected | error
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_connected_at
 * @property array<string, mixed>|null $capability_report
 * @property \Illuminate\Support\Carbon|null $capability_checked_at
 */
class WordPressConnection extends Model
{
    public const TRANSPORT_WPTOOLKIT = 'wptoolkit';

    public const TRANSPORT_REST = 'rest';

    public const TRANSPORT_HWS_BASE_TOOLS = 'hws_base_tools';

    public const TRANSPORTS = [self::TRANSPORT_WPTOOLKIT, self::TRANSPORT_REST, self::TRANSPORT_HWS_BASE_TOOLS];

    protected $table = 'wordpress_connections';

    protected $fillable = [
        'label',
        'site_url',
        'host',
        'transport',
        'whm_server_id',
        'hosting_account_id',
        'cpanel_username',
        'wordpress_install_id',
        'wordpress_path',
        'rest_username',
        'hws_key_id',
        'status',
        'last_error',
        'last_connected_at',
        'capability_report',
        'capability_checked_at',
    ];

    protected $casts = [
        'whm_server_id' => 'integer',
        'hosting_account_id' => 'integer',
        'wordpress_install_id' => 'integer',
        'capability_report' => 'array',
        'last_connected_at' => 'datetime',
        'capability_checked_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(WhmServer::class, 'whm_server_id');
    }

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class, 'hosting_account_id');
    }

    public function usesWpToolkit(): bool
    {
        return $this->transport === self::TRANSPORT_WPTOOLKIT && $this->whm_server_id && $this->wordpress_install_id;
    }

    /** CredentialService slug that holds this connection's secrets. */
    public function credentialSlug(): string
    {
        return 'wordpress_connection_'.$this->getKey();
    }

    /** Lowercase host without "www.", the connection's identity for sites we do not host. */
    public static function hostFromUrl(string $url): string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
