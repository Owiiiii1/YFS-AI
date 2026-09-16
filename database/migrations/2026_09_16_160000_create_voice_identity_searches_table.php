<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_identity_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_contact_id')->nullable()->constrained('voice_contacts')->nullOnDelete();
            $table->string('elevenlabs_conversation_id')->nullable()->index();
            $table->string('status', 32);
            $table->json('hints')->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['voice_contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_identity_searches');
    }
};
