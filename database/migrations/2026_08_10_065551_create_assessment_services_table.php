<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_services', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Parent Assessment
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('assessment_id')
                ->constrained('assessments')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('service_id')
                ->constrained('revenue_services')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Service Order
            |--------------------------------------------------------------------------
            |
            | Preserves the order selected by the officer.
            |
            */

            $table->unsignedInteger('service_order')
                ->default(1);


            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            |
            | This is NOT the final assessment decision.
            |
            | It only describes the processing state of this
            | individual selected service.
            |
            */

            $table->enum('status', [
                'CAPTURED',
                'PROCESSING',
                'COMPLETED',
                'ERROR',
                'CANCELLED',
            ])
            ->default('CAPTURED')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Computed Amount
            |--------------------------------------------------------------------------
            |
            | Amount calculated by the Decision Provider for
            | this individual service.
            |
            | This is NOT the final assessment decision.
            |
            */

            $table->decimal(
                'computed_amount',
                18,
                4
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $table->string('currency_code', 3)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Calculation Metadata
            |--------------------------------------------------------------------------
            |
            | Stores information explaining how this service
            | was calculated.
            |
            | Example:
            |
            | {
            |     "tariff_version_id": "...",
            |     "tariff_rule_id": "...",
            |     "calculation_type": "PER_UNIT",
            |     "variables": {
            |         "LAND_AREA": 500,
            |         "RATE": 50
            |     }
            | }
            |
            */

            $table->json('calculation_metadata')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Calculation Error
            |--------------------------------------------------------------------------
            */

            $table->text('calculation_error')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Calculation Timestamp
            |--------------------------------------------------------------------------
            */

            $table->timestamp('calculated_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | System
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | Constraints
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'assessment_id',
                'service_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'assessment_id',
                'status',
            ]);

            $table->index([
                'service_id',
                'status',
            ]);

            $table->index([
                'assessment_id',
                'service_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_services');
    }
};