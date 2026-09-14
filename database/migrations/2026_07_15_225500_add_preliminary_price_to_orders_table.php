<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('preliminary_price', 10, 2)->nullable()->after('total');
        });

        DB::table('orders')
            ->whereNull('preliminary_price')
            ->whereNotNull('expected_deposit')
            ->update([
                'preliminary_price' => DB::raw('expected_deposit * 2'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('preliminary_price');
        });
    }
};
