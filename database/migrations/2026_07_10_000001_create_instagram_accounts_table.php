<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('instagram_user_id')->nullable()->index();
            $table->string('facebook_page_id')->nullable()->index();
            $table->text('access_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
