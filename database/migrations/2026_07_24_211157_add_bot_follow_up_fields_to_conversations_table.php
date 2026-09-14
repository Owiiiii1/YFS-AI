<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('bot_awaits_reply')->default(false)->after('bot_enabled');
            $table->string('bot_awaiting_topic', 64)->nullable()->after('bot_awaits_reply');
            $table->timestamp('bot_awaiting_since')->nullable()->after('bot_awaiting_topic');
            $table->timestamp('bot_reminder_sent_at')->nullable()->after('bot_awaiting_since');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'bot_awaits_reply',
                'bot_awaiting_topic',
                'bot_awaiting_since',
                'bot_reminder_sent_at',
            ]);
        });
    }
};
