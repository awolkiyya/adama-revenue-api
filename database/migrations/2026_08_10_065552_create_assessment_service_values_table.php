<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_service_values', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Assessment Service
            |--------------------------------------------------------------------------
            |
            | The selected revenue service inside the assessment.
            |
            | Example:
            |
            | Assessment
            |   └── Property Tax Service
            |           └── assessment_service_values
            |
            */

            $table->foreignUuid('assessment_service_id')
                ->constrained('assessment_services')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Revenue Service Field
            |--------------------------------------------------------------------------
            |
            | Points to the exact service-specific field configuration
            | used when the value was captured.
            |
            | revenue_service_fields contains:
            |
            | - service_id
            | - base_field_id
            | - is_required
            | - label
            | - help_text
            | - validation_rules
            | - options
            | - input_type
            |
            | The base field is therefore reached through:
            |
            | assessment_service_values
            |        ↓
            | revenue_service_field_id
            |        ↓
            | revenue_service_fields
            |        ↓
            | base_field_id
            |        ↓
            | base_fields
            |
            */

            $table->foreignUuid('revenue_service_field_id')
                ->constrained('revenue_service_fields')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Field Code Snapshot
            |--------------------------------------------------------------------------
            |
            | Historical identity of the field at capture time.
            |
            | Example:
            |
            | LAND_AREA
            | PROPERTY_TYPE
            | EMPLOYEE_COUNT
            |
            */

            $table->string('field_code', 100);


            /*
            |--------------------------------------------------------------------------
            | Field Label Snapshot
            |--------------------------------------------------------------------------
            |
            | Historical label displayed to the officer.
            |
            | Example:
            |
            | Land Area
            | Property Type
            |
            */

            $table->string('field_label', 200)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Data Type Snapshot
            |--------------------------------------------------------------------------
            |
            | Represents what kind of data the field stores.
            |
            | This comes from the canonical base field.
            |
            */

            $table->enum('data_type', [
                'NUMBER',
                'DECIMAL',
                'TEXT',
                'BOOLEAN',
                'DATE',
            ])
            ->default('TEXT');


            /*
            |--------------------------------------------------------------------------
            | Input Type Snapshot
            |--------------------------------------------------------------------------
            |
            | Represents how the value was collected by the UI.
            |
            | This is different from data_type.
            |
            | Example:
            |
            | data_type = TEXT
            | input_type = SELECT
            |
            | data_type = TEXT
            | input_type = CHECKBOX
            |
            */

            $table->enum('input_type', [
                'TEXT',
                'NUMBER',
                'DECIMAL',
                'SELECT',
                'RADIO',
                'CHECKBOX',
                'DATE',
                'TEXTAREA',
                'FILE',
            ])
            ->default('TEXT');


            /*
            |--------------------------------------------------------------------------
            | Captured Value
            |--------------------------------------------------------------------------
            |
            | JSON is intentionally used because the system supports
            | different field value structures.
            |
            |--------------------------------------------------------------------------
            | NUMBER
            |--------------------------------------------------------------------------
            |
            | 500
            |
            |--------------------------------------------------------------------------
            | DECIMAL
            |--------------------------------------------------------------------------
            |
            | 250.50
            |
            |--------------------------------------------------------------------------
            | TEXT
            |--------------------------------------------------------------------------
            |
            | "ABC Trading"
            |
            |--------------------------------------------------------------------------
            | BOOLEAN
            |--------------------------------------------------------------------------
            |
            | true
            |
            |--------------------------------------------------------------------------
            | DATE
            |--------------------------------------------------------------------------
            |
            | "2026-08-10"
            |
            |--------------------------------------------------------------------------
            | SELECT
            |--------------------------------------------------------------------------
            |
            | "COMMERCIAL"
            |
            |--------------------------------------------------------------------------
            | RADIO
            |--------------------------------------------------------------------------
            |
            | "OWNED"
            |
            |--------------------------------------------------------------------------
            | MULTI SELECT
            |--------------------------------------------------------------------------
            |
            | ["COMMERCIAL", "INDUSTRIAL"]
            |
            |--------------------------------------------------------------------------
            | CHECKBOX GROUP
            |--------------------------------------------------------------------------
            |
            | ["WATER", "WASTE", "ELECTRICITY"]
            |
            |--------------------------------------------------------------------------
            | FILE
            |--------------------------------------------------------------------------
            |
            | {
            |     "path": "...",
            |     "name": "...",
            |     "mime_type": "...",
            |     "size": 123456
            | }
            |
            */

            $table->json('value')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Display Value
            |--------------------------------------------------------------------------
            |
            | Human-readable snapshot of the captured value.
            |
            | Example:
            |
            | value:
            |     "COMMERCIAL"
            |
            | display_value:
            |     "Commercial Property"
            |
            | For multiple selection:
            |
            | value:
            |     ["COMMERCIAL", "INDUSTRIAL"]
            |
            | display_value:
            |     "Commercial Property, Industrial Property"
            |
            */

            $table->text('display_value')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Measurement Unit Snapshot
            |--------------------------------------------------------------------------
            |
            | Historical measurement unit associated with the field.
            |
            | Example:
            |
            | M2
            | M3
            | PERSON
            | VEHICLE
            |
            */

            $table->foreignUuid('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Field Order Snapshot
            |--------------------------------------------------------------------------
            |
            | Preserves the order in which the field appeared
            | during assessment capture.
            |
            */

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
            | Constraints
            |--------------------------------------------------------------------------
            |
            | A configured service field can have only one captured
            | value inside one assessment service.
            |
            */

            $table->unique([
                'assessment_service_id',
                'revenue_service_field_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'assessment_service_id',
                'sort_order',
            ]);

            $table->index(
                'revenue_service_field_id'
            );

            $table->index(
                'field_code'
            );

            $table->index([
                'assessment_service_id',
                'field_code',
            ]);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('assessment_service_values');
    }
};