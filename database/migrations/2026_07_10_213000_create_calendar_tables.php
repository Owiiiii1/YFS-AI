<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('default_slot_count')->default(4);
            $table->json('weekend_days')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_day_settings', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedTinyInteger('slot_count');
            $table->timestamps();
        });

        Schema::create('calendar_slots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('slot_index');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status')->default('free');
            $table->timestamps();

            $table->unique(['date', 'slot_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_slots');
        Schema::dropIfExists('calendar_day_settings');
        Schema::dropIfExists('calendar_settings');
    }
};
