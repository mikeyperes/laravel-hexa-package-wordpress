<?php

namespace hexa_package_wordpress\Console;

use hexa_package_wordpress\Connections\ApplicationPasswordAuthorization;
use hexa_package_wordpress\Connections\WordPressConnectionRegistry;
use Illuminate\Console\Command;

/** Start WordPress's Authorize Application flow, or show whether a connection has its password. */
class AuthorizeApplicationPasswordCommand extends Command
{
    protected $signature = 'wordpress:app-password {site : Site URL or host}
        {--label= : Connection label for a new site}
        {--app='.ApplicationPasswordAuthorization::DEFAULT_APP_NAME.' : Application Password name shown in WordPress}
        {--status : Only report the connection, without starting an authorization}';

    protected $description = 'Start a one-click WordPress Application Password authorization for a connection, or report its status (no secrets shown)';

    public function handle(ApplicationPasswordAuthorization $authorization, WordPressConnectionRegistry $connections): int
    {
        $site = (string) $this->argument('site');
        if ($this->option('status')) {
            $connection = $connections->find(['site_url' => $site]);
            $this->line(json_encode($connection ? [
                'connection_id' => (int) $connection->getKey(),
                'host' => $connection->host,
                'transport' => $connection->transport,
                'username' => $connection->rest_username,
                'application_password' => $connections->hasSecret($connection, WordPressConnectionRegistry::SECRET_APPLICATION_PASSWORD) ? 'present' : 'missing',
                'status' => $connection->status,
                'last_error' => $connection->last_error,
            ] : ['host' => $site, 'connection' => 'missing'], JSON_UNESCAPED_SLASHES));

            return $connection ? self::SUCCESS : self::FAILURE;
        }

        $this->line(json_encode($authorization->begin($site, (string) $this->option('label'), (string) $this->option('app')), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
