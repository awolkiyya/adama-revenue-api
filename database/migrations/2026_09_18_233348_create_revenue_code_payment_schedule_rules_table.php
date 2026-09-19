<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revenue_code_payment_schedule_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('revenue_code_id')
                ->unique()
                ->constrained('revenue_codes')
                ->cascadeOnDelete();

            $table->boolean('is_enabled')->default(false)->index();

            $table->decimal('first_installment_percentage', 5, 2)
                ->nullable();

            $table->timestampsTz();
        });

        DB::statement("
            ALTER TABLE revenue_code_payment_schedule_rules
            ADD CONSTRAINT revenue_code_payment_schedule_rules_first_installment_percentage_check
            CHECK (
                first_installment_percentage IS NULL
                OR (
                    first_installment_percentage > 0
                    AND first_installment_percentage <= 100
                )
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE revenue_code_payment_schedule_rules
            DROP CONSTRAINT IF EXISTS revenue_code_payment_schedule_rules_first_installment_percentage_check
        ");

        Schema::dropIfExists('revenue_code_payment_schedule_rules');
    }
};