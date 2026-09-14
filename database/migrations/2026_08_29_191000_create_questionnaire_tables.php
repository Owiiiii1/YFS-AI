<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('audience', 32);
            $table->json('fields');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('questionnaire_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 32);
            $table->string('template_name')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32)->nullable();
            $table->string('participant_username')->nullable();
            $table->json('answers');
            $table->string('status', 32)->default('completed');
            $table->timestamps();
        });

        $now = now();
        DB::table('questionnaire_templates')->insert([
            [
                'name' => 'Анкета клиента',
                'audience' => 'client',
                'fields' => json_encode([
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['id' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['id' => 'city', 'label' => 'City', 'type' => 'text', 'required' => false],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Анкета партнёра',
                'audience' => 'partner',
                'fields' => json_encode([
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['id' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true],
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['id' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Анкета стаффа',
                'audience' => 'staff',
                'fields' => json_encode([
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                    ['id' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['id' => 'role', 'label' => 'Role', 'type' => 'text', 'required' => true],
                    ['id' => 'available_from', 'label' => 'Available from', 'type' => 'date', 'required' => false],
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_submissions');
        Schema::dropIfExists('questionnaire_templates');
    }
};
