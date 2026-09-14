<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('manual_mode_reenable_days')
                ->default(3)
                ->after('structured_prompts_ready');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('bot_manual_mode_at')->nullable()->after('bot_enabled');
            $table->index(['bot_enabled', 'bot_manual_mode_at'], 'conversations_manual_bot_mode_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_manual_bot_mode_index');
            $table->dropColumn('bot_manual_mode_at');
        });

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('manual_mode_reenable_days');
        });
    }
};
