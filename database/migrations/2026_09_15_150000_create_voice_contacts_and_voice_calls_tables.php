<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_normalized')->unique();
            $table->string('phone_display')->nullable();
            $table->string('name')->nullable();
            $table->string('preferred_language', 8)->nullable();
            $table->timestamp('first_called_at')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->unsignedInteger('calls_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_contact_id')->constrained('voice_contacts')->cascadeOnDelete();
            $table->string('elevenlabs_conversation_id')->nullable()->unique();
            $table->string('twilio_call_sid')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('language', 8)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 32)->default('done');
            $table->json('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->text('recording_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('voice_contacts');
    }
};
