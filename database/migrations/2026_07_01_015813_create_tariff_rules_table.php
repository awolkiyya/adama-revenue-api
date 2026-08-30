<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('tariff_rules', function (Blueprint $table) {


            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')
                ->primary();




            /*
            |--------------------------------------------------------------------------
            | Tariff Version
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('tariff_version_id')
                ->constrained('tariff_versions')
                ->cascadeOnDelete();





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
            | Rule Identity
            |--------------------------------------------------------------------------
            */


            /*
                Example:

                PROP-COM-001

                Commercial Property Tax Rule

            */


            $table->string('code',100)
                ->unique();



            /*
                Example:

                Commercial Building Above 500m2
            */

            $table->string('name',150)
                ->index();



            $table->text('description')
                ->nullable();







            /*
            |--------------------------------------------------------------------------
            | Calculation Engine
            |--------------------------------------------------------------------------
            */


            /*
                FIXED

                Example:
                500 ETB


                PERCENTAGE

                Example:
                property_value * 2%


                PER_UNIT

                Example:
                area * 50 ETB


                RANGE

                Example:
                0-100m2 = 10
                101-500m2 = 20


                FORMULA

                Example:
                employees * rate + penalty

            */


            $table->enum('calculation_type',[

                'FIXED',

                'PERCENTAGE',

                'PER_UNIT',

                'RANGE',

                'FORMULA',

            ])
            ->index();







            /*
            |--------------------------------------------------------------------------
            | Base Assessment Field
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | land_area
            | employee_count
            | vehicle_count
            |
            */


            $table->foreignUuid('base_field_id')
                ->nullable()
                ->constrained('base_fields')
                ->nullOnDelete();







            /*
            |--------------------------------------------------------------------------
            | Calculation Unit
            |--------------------------------------------------------------------------
            |
            | Mainly used for PER_UNIT
            |
            | Example:
            |
            | 50 ETB / M2
            | 100 ETB / PERSON
            |
            */


            $table->foreignUuid('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete();








            /*
            |--------------------------------------------------------------------------
            | Priority & Execution
            |--------------------------------------------------------------------------
            */


            /*
                Priority:

                Determines matching priority


                Execution order:

                Determines calculation sequence

            */


            $table->unsignedInteger('priority')
                ->default(1);



            $table->unsignedInteger('execution_order')
                ->default(1);








            /*
            |--------------------------------------------------------------------------
            | Range Configuration
            |--------------------------------------------------------------------------
            |
            | Used by RANGE calculation
            |
            */


            $table->decimal(
                'min_value',
                14,
                4
            )
            ->nullable();



            $table->decimal(
                'max_value',
                14,
                4
            )
            ->nullable();








            /*
            |--------------------------------------------------------------------------
            | Calculation Values
            |--------------------------------------------------------------------------
            */


            /*
                FIXED:

                amount = 500


                PER_UNIT:

                amount = 50

            */


            $table->decimal(
                'amount',
                14,
                4
            )
            ->nullable();






            /*
                Percentage:

                2.5%

            */


            $table->decimal(
                'percentage',
                8,
                4
            )
            ->nullable();








            /*
            |--------------------------------------------------------------------------
            | Charge Limits
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | Minimum tax = 500
            | Maximum tax = 50000
            |
            */


            $table->decimal(
                'minimum_amount',
                14,
                4
            )
            ->nullable();



            $table->decimal(
                'maximum_amount',
                14,
                4
            )
            ->nullable();








            /*
            |--------------------------------------------------------------------------
            | Formula Engine
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | area * rate + penalty
            |
            */


            $table->text('formula')
                ->nullable();








            /*
            |--------------------------------------------------------------------------
            | Formula Conditions
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | [
            |   {
            |     "field":"property_type",
            |     "operator":"equals",
            |     "value":"COMMERCIAL"
            |   }
            | ]
            |
            */


            $table->jsonb('conditions')
                ->nullable();








            /*
            |--------------------------------------------------------------------------
            | Rounding Rule
            |--------------------------------------------------------------------------
            */


            $table->enum('rounding_rule',[

                'NONE',

                'ROUND_UP',

                'ROUND_DOWN',

                'NEAREST',

            ])
            ->default('NONE');








            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */


            $table->boolean('is_active')
                ->default(true);








            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            */


            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();



            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();








            /*
            |--------------------------------------------------------------------------
            | System
            |--------------------------------------------------------------------------
            */


            $table->timestamps();

            $table->softDeletes();








            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */


            $table->unique([
                'tariff_version_id',
                'service_id',
                'priority'
            ]);



            $table->index([
                'service_id',
                'is_active'
            ]);



            $table->index([
                'tariff_version_id',
                'is_active'
            ]);



            $table->index([
                'calculation_type',
                'is_active'
            ]);



            $table->index([
                'base_field_id'
            ]);



            $table->index([
                'measurement_unit_id'
            ]);



        });
    }




    public function down(): void
    {
        Schema::dropIfExists('tariff_rules');
    }

};