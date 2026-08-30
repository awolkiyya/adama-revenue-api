<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table) {

            // Primary Key
            $table->uuid('id')->primary();

            // Tariff version this penalty rule belongs to
            $table->foreignUuid('tariff_version_id')
                ->constrained('tariff_versions')
                ->cascadeOnDelete();

            // Revenue service this penalty applies to
            $table->foreignUuid('service_id')
                ->constrained('revenue_services')
                ->cascadeOnDelete();

            // Rule identification
            $table->string('name');

            // Penalty calculation configuration
            $table->string('calculation_type');

            // Initial penalty percentage
            $table->decimal('initial_rate', 10, 4)->nullable();

            // Additional penalty rate
            $table->decimal('increment_rate', 10, 4)->nullable();

            // Maximum penalty percentage
            $table->decimal('maximum_rate', 10, 4)->nullable();

            // Grace period before penalty starts
            $table->unsignedInteger('grace_period_days')->default(0);

            // Whether this rule is active
            $table->boolean('is_active')->default(true);

            // Optional legal/reference information
            $table->text('description')->nullable();
            $table->string('legal_reference')->nullable();

            $table->timestamps();

            // Prevent duplicate penalty rules for the same
            // service within the same tariff version.
            $table->unique(
                ['tariff_version_id', 'service_id'],
                'penalty_rules_version_service_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_rules');
    }
};