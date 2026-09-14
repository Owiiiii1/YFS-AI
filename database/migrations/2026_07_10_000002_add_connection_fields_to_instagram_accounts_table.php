<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->string('connection_status')->default('not_configured');
            $table->timestamp('last_connection_check_at')->nullable();
            $table->timestamp('last_connection_success_at')->nullable();
            $table->text('last_connection_error')->nullable();
            $table->json('settings')->nullable();

            $table->index('connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropIndex(['connection_status']);
            $table->dropColumn([
                'connection_status',
                'last_connection_check_at',
                'last_connection_success_at',
                'last_connection_error',
                'settings',
            ]);
        });
    }
};
