<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32)->default('instagram');
            $table->string('participant_id', 128);
            $table->string('participant_name')->nullable();
            $table->string('participant_username')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'participant_id']);
            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
