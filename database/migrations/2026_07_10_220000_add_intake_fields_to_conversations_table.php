<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'intake_status')) {
                $table->string('intake_status', 32)->default('collecting')->after('bot_enabled');
            }
            if (! Schema::hasColumn('conversations', 'intake_data')) {
                $table->json('intake_data')->nullable()->after('intake_status');
            }
            if (! Schema::hasColumn('conversations', 'intake_order_id')) {
                $table->foreignId('intake_order_id')->nullable()->after('intake_data')->constrained('orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'intake_order_id')) {
                $table->dropConstrainedForeignId('intake_order_id');
            }
            if (Schema::hasColumn('conversations', 'intake_data')) {
                $table->dropColumn('intake_data');
            }
            if (Schema::hasColumn('conversations', 'intake_status')) {
                $table->dropColumn('intake_status');
            }
        });
    }
};
