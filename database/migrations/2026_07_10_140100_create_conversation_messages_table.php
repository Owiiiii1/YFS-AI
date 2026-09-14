<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('sender_type', 16);
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
            $table->index(['conversation_id', 'direction', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
