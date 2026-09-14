<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_page_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('facebook_page_id')->nullable()->index();
            $table->text('access_token_encrypted')->nullable();
            $table->string('token_type')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_refreshed_at')->nullable();
            $table->timestamp('token_refresh_failed_at')->nullable();
            $table->text('token_refresh_error')->nullable();
            $table->timestamp('token_last_checked_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('connection_status')->default('not_configured');
            $table->timestamp('last_connection_check_at')->nullable();
            $table->timestamp('last_connection_success_at')->nullable();
            $table->text('last_connection_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_page_accounts');
    }
};
