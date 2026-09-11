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
            | The reusable base field this option belongs to.
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
            | Stable machine-readable value.
            |
            | Example:
            |
            | RESIDENTIAL
            | COMMERCIAL
            | INDUSTRIAL
            |
            */

            $table->string('value', 150);

            /*
            |--------------------------------------------------------------------------
            | Option Label
            |--------------------------------------------------------------------------
            |
            | Human-readable value displayed in the frontend.
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
            | Sort Order
            |--------------------------------------------------------------------------
            |
            | Controls the display order of options.
            |
            */

            $table->unsignedInteger('sort_order')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Default Option
            |--------------------------------------------------------------------------
            |
            | Determines whether this option is selected by default.
            |
            */

            $table->boolean('is_default')
                ->default(false);

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
            | The same machine-readable option value cannot be duplicated
            | within the same base field.
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_field_options');
    }
};