<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per WordPress site we can reach and how we reach it. Publish sites,
 * journalist publications and verified-profile sites point here instead of
 * each keeping their own copy. Secrets live in Core's CredentialService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wordpress_connections')) {
            return;
        }

        Schema::create('wordpress_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('site_url');
            $table->string('host')->unique();
            $table->string('transport', 32)->default('wptoolkit');
            $table->unsignedBigInteger('whm_server_id')->nullable();
            $table->unsignedBigInteger('hosting_account_id')->nullable();
            $table->string('cpanel_username')->nullable();
            $table->unsignedBigInteger('wordpress_install_id')->nullable();
            $table->string('wordpress_path')->nullable();
            $table->string('rest_username')->nullable();
            $table->string('hws_key_id')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->text('last_error')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->json('capability_report')->nullable();
            $table->timestamp('capability_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['whm_server_id', 'wordpress_install_id'], 'wordpress_connections_install_unique');
            $table->index('hosting_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_connections');
    }
};
