<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('final_price', 10, 2)->nullable()->after('total');
            $table->decimal('expected_deposit', 10, 2)->nullable()->after('final_price');
            $table->string('payment_status', 32)->default('unpaid')->after('expected_deposit');
            $table->decimal('payment_amount', 10, 2)->nullable()->after('payment_status');
            $table->string('payment_receipt_path')->nullable()->after('payment_amount');
            $table->timestamp('payment_reviewed_at')->nullable()->after('payment_receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'final_price',
                'expected_deposit',
                'payment_status',
                'payment_amount',
                'payment_receipt_path',
                'payment_reviewed_at',
            ]);
        });
    }
};
