<?php

use App\Support\OrderCatalogDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_occasions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('order_allergies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('status');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('customer_id');
            $table->string('size_mode', 16)->default('guests')->after('guest_count');
            $table->decimal('cake_weight_lb', 8, 2)->nullable()->after('size_mode');
            $table->string('inspiration_image_path')->nullable()->after('inspiration_photo');
        });

        $now = now();

        foreach (OrderCatalogDefaults::occasions() as $index => $name) {
            DB::table('order_occasions')->insert([
                'name' => $name,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (OrderCatalogDefaults::allergies() as $index => $name) {
            DB::table('order_allergies')->insert([
                'name' => $name,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->importExistingNames(
            'orders',
            'theme',
            'order_occasions',
            OrderCatalogDefaults::occasions(),
            ['custom cake'],
        );

        $this->importExistingNames(
            'orders',
            'allergies',
            'order_allergies',
            OrderCatalogDefaults::allergies(),
            ['not too sweet'],
        );

        if (! DB::table('customers')->where('is_staff', true)->exists()) {
            DB::table('customers')->insert([
                'name' => 'Staff',
                'status' => 'active',
                'is_staff' => true,
                'notes' => 'Virtual client for internal staff orders.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'is_staff',
                'size_mode',
                'cake_weight_lb',
                'inspiration_image_path',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_staff');
        });

        Schema::dropIfExists('order_allergies');
        Schema::dropIfExists('order_occasions');
    }

    /**
     * @param  list<string>  $known
     * @param  list<string>  $skip
     */
    private function importExistingNames(
        string $sourceTable,
        string $sourceColumn,
        string $catalogTable,
        array $known,
        array $skip,
    ): void {
        $knownLower = array_map(static fn (string $name): string => mb_strtolower($name), $known);
        $skipLower = array_map(static fn (string $name): string => mb_strtolower($name), $skip);
        $sort = (int) DB::table($catalogTable)->max('sort_order');
        $now = now();

        $values = DB::table($sourceTable)
            ->whereNotNull($sourceColumn)
            ->where($sourceColumn, '!=', '')
            ->distinct()
            ->pluck($sourceColumn);

        foreach ($values as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }

            $lower = mb_strtolower($name);
            if (in_array($lower, $knownLower, true) || in_array($lower, $skipLower, true)) {
                continue;
            }

            if (DB::table($catalogTable)->whereRaw('LOWER(name) = ?', [$lower])->exists()) {
                continue;
            }

            $sort += 10;
            DB::table($catalogTable)->insert([
                'name' => $name,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $knownLower[] = $lower;
        }
    }
};
