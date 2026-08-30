<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | PRIMARY KEY
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | INVOICE
            |--------------------------------------------------------------------------
            |
            | Every invoice item belongs to exactly one invoice.
            |
            */

            $table->foreignUuid('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | ASSESSMENT SERVICE
            |--------------------------------------------------------------------------
            |
            | Nullable because an invoice item can originate from:
            |
            | 1. APPROVED ASSESSMENT
            |      assessment_service_id = populated
            |
            | 2. DIRECT COLLECTION
            |      assessment_service_id = NULL
            |
            | The invoice item itself remains independent from the
            | assessment after creation.
            |
            */

            $table->foreignUuid('assessment_service_id')
                ->nullable()
                ->constrained('assessment_services')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | REVENUE SERVICE
            |--------------------------------------------------------------------------
            |
            | Every invoice item must identify the revenue service
            | being charged.
            |
            | This is required for:
            |
            | - Assessment invoices
            | - Direct collection invoices
            |
            */

            $table->foreignUuid('service_id')
                ->constrained('revenue_services')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | LINE NUMBER
            |--------------------------------------------------------------------------
            |
            | Determines the display/order of invoice lines.
            |
            | Example:
            |
            | 1 -> Property Tax
            | 2 -> Waste Fee
            | 3 -> Permit Fee
            |
            */

            $table->unsignedInteger('line_number')
                ->default(1);


            /*
            |--------------------------------------------------------------------------
            | DESCRIPTION SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | Human-readable description captured at invoice creation.
            |
            | This should NOT depend on the current revenue service name
            | after the invoice has been created.
            |
            */

            $table->string('description', 500);


            /*
            |--------------------------------------------------------------------------
            | QUANTITY
            |--------------------------------------------------------------------------
            |
            | Useful for quantity-based services.
            |
            | Example:
            |
            | quantity = 250
            | unit     = M2
            |
            */

            $table->decimal(
                'quantity',
                18,
                4
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | UNIT SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | M2
            | PERSON
            | VEHICLE
            | MONTH
            |
            */

            $table->string('unit', 50)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | UNIT PRICE
            |--------------------------------------------------------------------------
            |
            | Applicable when the pricing model exposes a unit price.
            |
            | For RANGE / FIXED / FORMULA calculations this can be NULL.
            |
            */

            $table->decimal(
                'unit_price',
                18,
                4
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | BASE AMOUNT
            |--------------------------------------------------------------------------
            |
            | Authoritative amount produced by the Decision Provider /
            | Tariff Engine.
            |
            | InvoiceService MUST NOT recalculate this amount.
            |
            */

            $table->decimal(
                'amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | DISCOUNT
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'discount_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | PENALTY
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'penalty_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | FINAL LINE TOTAL
            |--------------------------------------------------------------------------
            |
            | Financial result represented by this invoice line.
            |
            | Conceptually:
            |
            | amount
            | - discount_amount
            | + penalty_amount
            |
            | This value is finalized by backend/domain logic.
            |
            */

            $table->decimal(
                'total_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | CURRENCY
            |--------------------------------------------------------------------------
            */

            $table->string('currency', 3)
                ->default('ETB');


            /*
            |--------------------------------------------------------------------------
            | TARIFF VERSION SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | References the tariff version used by the decision.
            |
            | Nullable because direct collection may not use
            | a tariff version.
            |
            */

            $table->foreignUuid('tariff_version_id')
                ->nullable()
                ->constrained('tariff_versions')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | TARIFF RULE SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | References the exact tariff rule used by the decision.
            |
            */

            $table->foreignUuid('tariff_rule_id')
                ->nullable()
                ->constrained('tariff_rules')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | INPUT SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | Immutable copy of the values used for the calculation.
            |
            | Example:
            |
            | {
            |     "23388827-cd82-4881-a812-c881f9774d1e": 105,
            |     "5c37894c-ebc7-49aa-9e3c-7917e765c286": "RESIDENTIAL"
            | }
            |
            | This is particularly important because dynamic service
            | fields can change after the invoice has been generated.
            |
            */

            $table->json('input_snapshot')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | CALCULATION / DECISION SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | Immutable copy of the authoritative calculation metadata.
            |
            | Example from your current Decision Provider:
            |
            | {
            |     "tariff_version_id": "...",
            |     "tariff_rule_id": "...",
            |     "calculation_type": "RANGE",
            |     "calculated_at": "...",
            |     "base_field_id": "...",
            |     "measurement_unit_id": null,
            |     "configured_amount": "3500.0000",
            |     "percentage": null,
            |     "minimum_amount": "3500.0000",
            |     "maximum_amount": "3500.0000",
            |     "rounding_rule": "NONE",
            |     "formula": null,
            |     "inputs": {
            |         "...": 105,
            |         "...": "RESIDENTIAL"
            |     }
            | }
            |
            */

            $table->json('calculation_snapshot')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | SYSTEM
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | CONSTRAINTS
            |--------------------------------------------------------------------------
            |
            | A single invoice cannot contain the same line number twice.
            |
            */

            $table->unique([
                'invoice_id',
                'line_number',
            ]);


            /*
            |--------------------------------------------------------------------------
            | INDEXES
            |--------------------------------------------------------------------------
            */

            $table->index([
                'invoice_id',
                'service_id',
            ]);


            $table->index(
                'assessment_service_id'
            );


            $table->index(
                'service_id'
            );


            $table->index(
                'tariff_version_id'
            );


            $table->index(
                'tariff_rule_id'
            );


            $table->index(
                'created_at'
            );
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};