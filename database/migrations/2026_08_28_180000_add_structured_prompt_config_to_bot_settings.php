<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->json('prompt_config')->nullable()->after('additional_prompts');
            $table->unsignedInteger('prompt_revision')->default(0)->after('prompt_config');
            $table->boolean('structured_prompts_ready')->default(false)->after('prompt_revision');
        });

        Schema::create('bot_prompt_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('prompt_config');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['bot_setting_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_prompt_revisions');

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'prompt_config',
                'prompt_revision',
                'structured_prompts_ready',
            ]);
        });
    }
};
