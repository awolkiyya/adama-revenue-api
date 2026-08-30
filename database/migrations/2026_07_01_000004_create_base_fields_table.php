<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_fields', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            $table->string('code', 100)
                ->unique();

            $table->string('name', 150)
                ->index();

            $table->text('description')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | LAND_AREA       -> M2
            | EMPLOYEE_COUNT  -> PERSON
            |
            */

            $table->foreignUuid('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Data Type
            |--------------------------------------------------------------------------
            |
            | Supported field types:
            |
            | NUMBER
            | DECIMAL
            | TEXT
            | BOOLEAN
            | DATE
            | SELECT
            | RADIO
            | CHECKBOX
            | FILE
            |
            */

            $table->enum('data_type', [
                'NUMBER',
                'DECIMAL',
                'TEXT',
                'BOOLEAN',
                'DATE',
                'SELECT',
                'RADIO',
                'CHECKBOX',
                'FILE',
            ])
                ->default('DECIMAL');


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();

            $table->unsignedInteger('sort_order')
                ->default(0);


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

            $table->index('measurement_unit_id');

            $table->index('sort_order');
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('base_fields');
    }
};