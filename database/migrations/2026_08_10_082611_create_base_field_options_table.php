<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_field_options', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Base Field
            |--------------------------------------------------------------------------
            |
            | The reusable field this option belongs to.
            |
            | Example:
            |
            | base_field:
            |     PROPERTY_TYPE
            |
            | options:
            |     RESIDENTIAL
            |     COMMERCIAL
            |     INDUSTRIAL
            |
            */

            $table->foreignUuid('base_field_id')
                ->constrained('base_fields')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Option Value
            |--------------------------------------------------------------------------
            |
            | Machine-readable value submitted by the frontend.
            |
            | Example:
            |
            | COMMERCIAL
            | RESIDENTIAL
            | INDUSTRIAL
            |
            */

            $table->string('value', 150);


            /*
            |--------------------------------------------------------------------------
            | Option Label
            |--------------------------------------------------------------------------
            |
            | Human-readable value displayed to the user.
            |
            | Example:
            |
            | value = COMMERCIAL
            | label = Commercial Property
            |
            */

            $table->string('label', 200);


            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            |
            | Optional explanation for the option.
            |
            */

            $table->text('description')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Sort Order
            |--------------------------------------------------------------------------
            |
            | Controls the order displayed in:
            |
            | SELECT
            | RADIO
            | CHECKBOX
            |
            */

            $table->unsignedInteger('sort_order')
                ->default(0);


            /*
            |--------------------------------------------------------------------------
            | Default Option
            |--------------------------------------------------------------------------
            |
            | Useful for SELECT/RADIO fields where one option
            | may be selected by default.
            |
            */

            $table->boolean('is_default')
                ->default(false);


            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            |
            | Allows an option to be disabled without deleting it.
            |
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
            | The same option value cannot be duplicated
            | inside the same base field.
            |
            */

            $table->unique([
                'base_field_id',
                'value',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'base_field_id',
                'sort_order',
            ]);

            $table->index([
                'base_field_id',
                'is_active',
            ]);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('base_field_options');
    }
};