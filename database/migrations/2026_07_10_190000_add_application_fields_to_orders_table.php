<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('theme')->nullable()->after('title');
            $table->date('event_date')->nullable()->after('theme');
            $table->unsignedSmallInteger('guest_count')->nullable()->after('event_date');
            $table->text('inspiration_photo')->nullable()->after('guest_count');
            $table->string('flavor')->nullable()->after('inspiration_photo');
            $table->string('pickup_or_delivery')->nullable()->after('flavor');
            $table->text('bot_summary')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'theme',
                'event_date',
                'guest_count',
                'inspiration_photo',
                'flavor',
                'pickup_or_delivery',
                'bot_summary',
            ]);
        });
    }
};
