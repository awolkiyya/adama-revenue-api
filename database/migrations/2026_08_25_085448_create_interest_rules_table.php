<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interest_rules', function (Blueprint $table) {

            /**
             * -----------------------------------------------------------------
             * Primary Key
             * -----------------------------------------------------------------
             */
            $table->uuid('id')->primary();

            /**
             * -----------------------------------------------------------------
             * Tariff Version
             * -----------------------------------------------------------------
             *
             * The interest rule belongs to a specific tariff version.
             *
             * This guarantees that an assessment can reproduce the
             * interest rule that was legally applicable at that time.
             */
            $table->foreignUuid('tariff_version_id')
                ->constrained('tariff_versions')
                ->cascadeOnDelete();

            /**
             * -----------------------------------------------------------------
             * Interest Rate
             * -----------------------------------------------------------------
             *
             * Stored as percentage.
             *
             * Example:
             *
             * 24.725 = 24.725%
             *
             * Calculation engine converts it to:
             *
             * 24.725 / 100 = 0.24725
             */
            $table->decimal('rate', 10, 4);

            /**
             * -----------------------------------------------------------------
             * Rate Period
             * -----------------------------------------------------------------
             *
             * YEAR
             * MONTH
             * DAY
             */
            $table->string('rate_period', 20)->default('YEAR');

            /**
             * -----------------------------------------------------------------
             * Calculation Method
             * -----------------------------------------------------------------
             *
             * SIMPLE
             * COMPOUND
             */
            $table->string('calculation_method', 30)->default('SIMPLE');

            /**
             * -----------------------------------------------------------------
             * Legal Reference
             * -----------------------------------------------------------------
             */
            $table->string('legal_reference')->nullable();

            /**
             * -----------------------------------------------------------------
             * Description
             * -----------------------------------------------------------------
             */
            $table->text('description')->nullable();

            /**
             * -----------------------------------------------------------------
             * Status
             * -----------------------------------------------------------------
             */
            $table->boolean('is_active')->default(true);

            /**
             * -----------------------------------------------------------------
             * Timestamps
             * -----------------------------------------------------------------
             */
            $table->timestamps();

            /**
             * -----------------------------------------------------------------
             * Constraints
             * -----------------------------------------------------------------
             *
             * One interest rule per tariff version.
             */
            $table->unique(
                'tariff_version_id',
                'interest_rules_tariff_version_unique'
            );

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_rules');
    }
};