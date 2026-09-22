<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_captures', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Capturable (polymorphic parent)
            |--------------------------------------------------------------------------
            |
            | A service capture belongs to whichever "capture flow"
            | created it. Today that's:
            |
            | App\Models\Assessment       (approval required)
            | App\Models\FieldCollection  (no approval required)
            |
            | This is intentionally NOT a real foreign key — a
            | polymorphic relation can't be constrained against two
            | different parent tables at the database level. Referential
            | integrity for this link is enforced in the application
            | layer (model observers / service layer), not the schema.
            |
            | Adds:
            |   capturable_id   (uuid)
            |   capturable_type (string)
            |
            | And an index on (capturable_type, capturable_id).
            |
            */

            $table->uuidMorphs('capturable');


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
            | Preserves the order selected by the officer/collector.
            |
            */

            $table->unsignedInteger('service_order')
                ->default(1);


            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            |
            | This is NOT the parent's final decision/approval state.
            |
            | It only describes the processing state of this
            | individual selected service.
            |
            | For an Assessment, COMPLETED still awaits approval.
            | For a FieldCollection, COMPLETED is used directly on
            | the invoice — there is no approval step.
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
            |
            | A given service can only appear once within the same
            | capturable parent (one Assessment, or one FieldCollection).
            |
            */

            $table->unique([
                'capturable_type',
                'capturable_id',
                'service_id',
            ], 'service_captures_parent_service_unique');


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            |
            | uuidMorphs('capturable') already indexes
            | (capturable_type, capturable_id) on its own.
            |
            */

            $table->index([
                'capturable_type',
                'capturable_id',
                'status',
            ], 'service_captures_parent_status_index');

            $table->index([
                'service_id',
                'status',
            ]);

            $table->index([
                'capturable_type',
                'capturable_id',
                'service_order',
            ], 'service_captures_parent_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_captures');
    }
};