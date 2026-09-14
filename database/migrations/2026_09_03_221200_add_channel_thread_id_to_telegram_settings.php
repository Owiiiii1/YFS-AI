<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->unsignedInteger('channel_thread_id')->nullable()->after('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn('channel_thread_id');
        });
    }
};
