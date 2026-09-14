<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('instagram_accounts', 'token_type')) {
                $table->string('token_type')->nullable()->after('token_expires_at');
            }
            if (! Schema::hasColumn('instagram_accounts', 'token_refreshed_at')) {
                $table->timestamp('token_refreshed_at')->nullable()->after('token_type');
            }
            if (! Schema::hasColumn('instagram_accounts', 'token_refresh_failed_at')) {
                $table->timestamp('token_refresh_failed_at')->nullable()->after('token_refreshed_at');
            }
            if (! Schema::hasColumn('instagram_accounts', 'token_refresh_error')) {
                $table->text('token_refresh_error')->nullable()->after('token_refresh_failed_at');
            }
            if (! Schema::hasColumn('instagram_accounts', 'token_last_checked_at')) {
                $table->timestamp('token_last_checked_at')->nullable()->after('token_refresh_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('instagram_accounts', 'token_last_checked_at')) {
                $table->dropColumn('token_last_checked_at');
            }
            if (Schema::hasColumn('instagram_accounts', 'token_refresh_error')) {
                $table->dropColumn('token_refresh_error');
            }
            if (Schema::hasColumn('instagram_accounts', 'token_refresh_failed_at')) {
                $table->dropColumn('token_refresh_failed_at');
            }
            if (Schema::hasColumn('instagram_accounts', 'token_refreshed_at')) {
                $table->dropColumn('token_refreshed_at');
            }
            if (Schema::hasColumn('instagram_accounts', 'token_type')) {
                $table->dropColumn('token_type');
            }
        });
    }
};
