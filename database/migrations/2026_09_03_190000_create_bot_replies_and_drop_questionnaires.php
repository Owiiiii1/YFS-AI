<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32)->nullable();
            $table->string('participant_username')->nullable();
            $table->string('type', 32);
            $table->text('summary');
            $table->json('payload')->nullable();
            $table->boolean('telegram_sent')->default(false);
            $table->timestamps();
            $table->index(['created_at', 'type']);
        });

        Schema::dropIfExists('questionnaire_submissions');
        Schema::dropIfExists('questionnaire_templates');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_replies');
    }
};
