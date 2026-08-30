<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | PRIMARY KEY
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | INVOICE NUMBER
            |--------------------------------------------------------------------------
            |
            | Human-readable unique invoice identifier.
            |
            | Example:
            |
            | INV-2026-000001
            |
            | The UNIQUE constraint already creates an index.
            |
            */

            $table->string('invoice_number', 100)
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | INVOICE SOURCE
            |--------------------------------------------------------------------------
            |
            | ASSESSMENT
            |     Invoice originates from an approved assessment.
            |
            | DIRECT_COLLECTION
            |     Invoice is created directly for a field/direct
            |     collection process without an assessment.
            |
            */

            $table->enum('source_type', [
                'ASSESSMENT',
                'DIRECT_COLLECTION',
            ])
            ->index();


            /*
            |--------------------------------------------------------------------------
            | ASSESSMENT
            |--------------------------------------------------------------------------
            |
            | Required logically when:
            |
            |     source_type = ASSESSMENT
            |
            | Nullable because DIRECT_COLLECTION does not require
            | an assessment.
            |
            */

            $table->foreignUuid('assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | CITIZEN / TAXPAYER
            |--------------------------------------------------------------------------
            |
            | Every invoice belongs to a taxpayer.
            |
            */

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
            |
            | Administrative unit responsible for the revenue.
            |
            */

            $table->foreignUuid('administrative_unit_id')
                ->nullable()
                ->constrained('administrative_units')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | INVOICE STATUS
            |--------------------------------------------------------------------------
            |
            | Lifecycle:
            |
            | DRAFT
            |    ↓
            | ISSUED
            |    ↓
            | PARTIALLY_PAID
            |    ↓
            | PAID
            |
            | ISSUED
            |    ↓
            | OVERDUE
            |
            | DRAFT
            |    ↓
            | CANCELLED
            |
            | ISSUED / OVERDUE
            |    ↓
            | VOID
            |
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'DRAFT',
                'ISSUED',
                'PARTIALLY_PAID',
                'PAID',
                'OVERDUE',
                'CANCELLED',
                'VOID',
            ])
            ->default('DRAFT')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | CURRENCY
            |--------------------------------------------------------------------------
            |
            | ISO 4217 currency code.
            |
            | Example:
            |
            | ETB
            |
            */

            $table->string('currency', 3)
                ->default('ETB');


            /*
            |--------------------------------------------------------------------------
            | FINANCIAL AMOUNTS
            |--------------------------------------------------------------------------
            |
            | All financial values are backend-authoritative.
            |
            | The frontend must never determine these values.
            |
            | Example:
            |
            | Property Tax       3,500
            | Waste Fee            500
            | Permit Fee         1,000
            | -------------------------
            | Subtotal            5,000
            | Discount             -200
            | Penalty              +100
            | -------------------------
            | TOTAL               4,900
            |
            | Paid                2,000
            | Balance Due         2,900
            |
            */

            $table->decimal(
                'subtotal',
                18,
                4
            )->default(0);


            $table->decimal(
                'discount_amount',
                18,
                4
            )->default(0);


            $table->decimal(
                'penalty_amount',
                18,
                4
            )->default(0);


            $table->decimal(
                'total_amount',
                18,
                4
            )->default(0);


            $table->decimal(
                'paid_amount',
                18,
                4
            )->default(0);


            $table->decimal(
                'balance_due',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | INVOICE DATES
            |--------------------------------------------------------------------------
            */

            $table->timestamp('issued_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | PAYMENT DUE DATE
            |--------------------------------------------------------------------------
            */

            $table->date('due_date')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | PAYMENT COMPLETION
            |--------------------------------------------------------------------------
            */

            $table->timestamp('paid_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | CANCELLED AUDIT
            |--------------------------------------------------------------------------
            |
            | Applies specifically to:
            |
            | DRAFT → CANCELLED
            |
            */

            $table->timestamp('cancelled_at')
                ->nullable();


            $table->foreignUuid('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            $table->text('cancellation_reason')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | VOID AUDIT
            |--------------------------------------------------------------------------
            |
            | VOID is different from CANCELLED.
            |
            | CANCELLED:
            |     Invoice was stopped before becoming financially final.
            |
            | VOID:
            |     Previously issued invoice was invalidated.
            |
            */

            $table->timestamp('voided_at')
                ->nullable();


            $table->foreignUuid('voided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            $table->text('void_reason')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | NOTES
            |--------------------------------------------------------------------------
            */

            $table->text('notes')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | AUDIT
            |--------------------------------------------------------------------------
            |
            | created_by:
            |     User/system that created the invoice.
            |
            | issued_by:
            |     Officer who officially issued the invoice.
            |
            */

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            $table->foreignUuid('issued_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | DIRECT COLLECTION METADATA
            |--------------------------------------------------------------------------
            |
            | Used when:
            |
            | source_type = DIRECT_COLLECTION
            |
            | Example:
            |
            | {
            |     "collection_point": "Market Center",
            |     "reference": "FC-2026-001",
            |     "remarks": "Field collection"
            | }
            |
            */

            $table->json('source_metadata')
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
            | INDEXES
            |--------------------------------------------------------------------------
            */

            $table->index([
                'citizen_id',
                'status',
            ]);


            $table->index([
                'administrative_unit_id',
                'status',
            ]);


            $table->index([
                'source_type',
                'status',
            ]);


            $table->index([
                'status',
                'due_date',
            ]);


            $table->index([
                'issued_at',
            ]);


            $table->index([
                'assessment_id',
            ]);


            $table->index([
                'created_by',
                'created_at',
            ]);


            $table->index([
                'cancelled_at',
            ]);


            $table->index([
                'voided_at',
            ]);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};