<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'instagram_username')) {
                $table->string('instagram_username')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('customers', 'instagram_user_id')) {
                $table->string('instagram_user_id', 128)->nullable()->after('instagram_username');
                $table->index('instagram_user_id');
            }
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversation_messages', 'sent_via')) {
                $table->string('sent_via', 24)->nullable()->after('sender_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('conversation_messages', 'sent_via')) {
                $table->dropColumn('sent_via');
            }
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'instagram_user_id')) {
                $table->dropIndex(['instagram_user_id']);
                $table->dropColumn('instagram_user_id');
            }
            if (Schema::hasColumn('customers', 'instagram_username')) {
                $table->dropColumn('instagram_username');
            }
        });
    }
};
