<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('bot_enabled')->default(true);
            $table->string('main_prompt_path')->nullable();
            $table->string('main_prompt_original_name')->nullable();
            $table->longText('main_prompt_text')->nullable();
            $table->json('additional_prompts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
