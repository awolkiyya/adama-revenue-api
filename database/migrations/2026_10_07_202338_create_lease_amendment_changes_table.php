<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_amendment_changes', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Lease Amendment
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('lease_amendment_id')
                ->constrained('lease_amendments')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Changed Field
            |--------------------------------------------------------------------------
            |
            | Fields used by the current amendment workflow include:
            |
            | NAME
            | LAND_AREA
            | TRANSFER_AREA
            | REMAINING_AREA
            | OTHER_LAND_AREA
            |
            | Additional fields can be added later if the municipality
            | introduces more amendment types.
            |
            */

            $table->string('field_name', 100);


            /*
            |--------------------------------------------------------------------------
            | Value Type
            |--------------------------------------------------------------------------
            */

            $table->enum('value_type', [
                'STRING',
                'INTEGER',
                'DECIMAL',
                'DATE',
                'DATETIME',
                'BOOLEAN',
                'UUID',
                'JSON',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Previous Value
            |--------------------------------------------------------------------------
            |
            | Stores the value before the amendment.
            |
            | Examples:
            |
            | LAND_AREA:
            |   1000
            |
            | NAME:
            |   "Aster Haile"
            |
            */

            $table->json('old_value')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | New Value
            |--------------------------------------------------------------------------
            |
            | Stores the value requested by the amendment.
            |
            | Examples:
            |
            | LAND_AREA:
            |   700
            |
            | NAME:
            |   "Samuel Haile"
            |
            */

            $table->json('new_value')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            |
            | Examples:
            |
            | m²
            | YEAR
            | ETB
            |
            */

            $table->foreignUuid('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Change Reason
            |--------------------------------------------------------------------------
            */

            $table->text('reason')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Change Order
            |--------------------------------------------------------------------------
            |
            | Allows multiple changes to be stored in a deterministic order.
            |
            */

            $table->unsignedInteger('change_order')
                ->default(1);


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'lease_amendment_id',
                    'field_name',
                ],
                'lease_amendment_changes_amendment_field_index'
            );

            $table->index(
                [
                    'lease_amendment_id',
                    'change_order',
                ],
                'lease_amendment_changes_amendment_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_amendment_changes');
    }
};