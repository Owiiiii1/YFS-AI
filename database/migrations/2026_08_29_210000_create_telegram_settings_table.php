<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->text('bot_token_encrypted')->nullable();
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->string('bot_username')->nullable();
            $table->string('bot_name')->nullable();
            $table->string('bot_status', 32)->default('not_configured');
            $table->string('webhook_secret')->nullable();
            $table->timestamp('webhook_set_at')->nullable();
            $table->string('channel_id')->nullable();
            $table->string('channel_username')->nullable();
            $table->string('channel_title')->nullable();
            $table->string('channel_status', 32)->default('not_configured');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
