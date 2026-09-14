<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_role_connections', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->string('provider');
            $table->string('key_source_provider');
            $table->string('active_model')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('bot_decision_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 32)->nullable();
            $table->foreignId('trigger_inbound_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->json('trigger_inbound_message_ids')->nullable();
            $table->text('effective_customer_text')->nullable();
            $table->string('status', 48)->default('running');
            $table->string('reply_source', 48)->nullable();
            $table->json('decision_path')->nullable();
            $table->string('template_key', 96)->nullable();
            $table->string('skip_reason', 255)->nullable();
            $table->string('conversation_status', 48)->nullable();
            $table->string('intake_status', 48)->nullable();
            $table->json('intake_data_snapshot')->nullable();
            $table->string('language', 8)->nullable();
            $table->unsignedInteger('bot_prompt_revision')->nullable();
            $table->boolean('flavor_prompt_included')->default(false);
            $table->boolean('bot_enabled')->default(false);
            $table->json('outbound_message_ids')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_trace_id')->nullable()->constrained('bot_decision_traces')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->string('connection_role', 64)->default('bot_runtime');
            $table->string('provider', 32);
            $table->string('model', 255);
            $table->string('status', 24);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->char('system_prompt_hash', 64);
            $table->char('user_prompt_hash', 64);
            $table->longText('system_prompt')->nullable();
            $table->longText('user_prompt')->nullable();
            $table->longText('response_text')->nullable();
            $table->json('parsed_result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('bot_prompt_revision')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['purpose', 'created_at']);
        });

        Schema::create('ai_prompt_analysis_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_session_id')->nullable()->constrained('ai_prompt_analysis_sessions')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_username')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedTinyInteger('step')->default(1);
            $table->text('question');
            $table->text('context_summary')->nullable();
            $table->unsignedInteger('bot_revision_at_start');
            $table->json('analysis_payload')->nullable();
            $table->text('user_instruction')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'updated_at']);
        });

        Schema::create('ai_prompt_analysis_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_prompt_analysis_sessions')->cascadeOnDelete();
            $table->string('role', 24);
            $table->unsignedTinyInteger('stage');
            $table->longText('body');
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_prompt_change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_prompt_analysis_sessions')->cascadeOnDelete();
            $table->unsignedInteger('base_revision');
            $table->string('status', 32)->default('preview_ready');
            $table->json('proposed_patch');
            $table->json('preview_before');
            $table->json('preview_after');
            $table->json('sensitive_changes')->nullable();
            $table->json('validation_results')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('applied_revision')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_change_proposals');
        Schema::dropIfExists('ai_prompt_analysis_messages');
        Schema::dropIfExists('ai_prompt_analysis_sessions');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('bot_decision_traces');
        Schema::dropIfExists('ai_role_connections');
    }
};
