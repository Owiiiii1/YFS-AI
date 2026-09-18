<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_call_id')->nullable()->constrained('voice_calls')->nullOnDelete();
            $table->foreignId('voice_contact_id')->constrained('voice_contacts')->cascadeOnDelete();
            $table->string('elevenlabs_conversation_id')->unique();
            $table->string('department', 16);
            $table->string('reason', 500);
            $table->string('status', 16)->default('open');
            $table->boolean('callback_requested')->default(false);
            $table->string('callback_phone')->nullable();
            $table->string('preferred_callback_time')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('child_name')->nullable();
            $table->string('show_city')->nullable();
            $table->text('summary')->nullable();
            $table->string('source', 16)->default('voice');
            $table->timestamp('telegram_sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'department']);
            $table->index('voice_contact_id');
        });

        Schema::create('voice_call_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_call_id')->unique()->constrained('voice_calls')->cascadeOnDelete();
            $table->string('intent')->nullable();
            $table->string('department', 16)->nullable();
            $table->boolean('human_followup_required')->default(false);
            $table->boolean('callback_requested')->default(false);
            $table->boolean('callback_committed_by_agent')->default(false);
            $table->boolean('live_followup_created')->default(false);
            $table->text('summary')->nullable();
            $table->json('unresolved_questions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_analyses');
        Schema::dropIfExists('voice_followups');
    }
};
