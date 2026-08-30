<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_service_fields', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('service_id')
                ->constrained('revenue_services')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Base Field
            |--------------------------------------------------------------------------
            |
            | Reusable canonical field definition.
            |
            | Example:
            |
            | LAND_AREA
            | PROPERTY_TYPE
            | EMPLOYEE_COUNT
            |
            */

            $table->foreignUuid('base_field_id')
                ->constrained('base_fields')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Field Order
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('sort_order')
                ->default(0);


            /*
            |--------------------------------------------------------------------------
            | Required
            |--------------------------------------------------------------------------
            |
            | Determines whether the officer must provide
            | a value when this service is assessed.
            |
            */

            $table->boolean('is_required')
                ->default(false);


            /*
            |--------------------------------------------------------------------------
            | Service-Specific Label
            |--------------------------------------------------------------------------
            |
            | Optional override of the global BaseField name.
            |
            */

            $table->string('label', 150)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Help Text
            |--------------------------------------------------------------------------
            */

            $table->text('help_text')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Validation Rules
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | {
            |     "min": 0,
            |     "max": 100000
            | }
            |
            */

            $table->json('validation_rules')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


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
            |
            | One BaseField can only be configured once
            | within the same RevenueService.
            |
            */

            $table->unique([
                'service_id',
                'base_field_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'service_id',
                'is_active',
            ]);

            $table->index([
                'base_field_id',
                'is_active',
            ]);

            $table->index([
                'service_id',
                'sort_order',
            ]);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('revenue_service_fields');
    }
};