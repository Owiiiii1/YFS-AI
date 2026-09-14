<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 64)->unique();
            $table->string('platform_user_id')->nullable()->index();
            $table->string('status', 32)->default('received');
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_data_deletion_requests');
    }
};
