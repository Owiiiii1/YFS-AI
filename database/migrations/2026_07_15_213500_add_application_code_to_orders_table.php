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
            $table->string('application_code', 4)->nullable()->after('id');
        });

        $orderIds = DB::table('orders')->orderBy('id')->pluck('id');
        if ($orderIds->count() > 9000) {
            throw new \RuntimeException('Four-digit application code space is exhausted.');
        }

        $codes = range(1000, 9999);
        shuffle($codes);

        foreach ($orderIds as $index => $orderId) {
            DB::table('orders')
                ->where('id', $orderId)
                ->update(['application_code' => (string) $codes[$index]]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('application_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['application_code']);
            $table->dropColumn('application_code');
        });
    }
};
