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
            */

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
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
            | subtotal:
            |     Original assessed/service principal total.
            |
            | discount_amount:
            |     Approved discount applied to the invoice.
            |
            | penalty_amount:
            |     Statutory penalty accrued according to the applicable
            |     penalty rule.
            |
            | interest_amount:
            |     Statutory interest accrued according to the applicable
            |     interest rule.
            |
            | total_amount:
            |     Current invoice total after discount, penalty and interest.
            |
            | paid_amount:
            |     Amount already allocated to this invoice.
            |
            | balance_due:
            |     Current unpaid amount.
            |
            | Example:
            |
            | Principal            5,000
            | Discount               200
            | Penalty                100
            | Interest                50
            | --------------------------
            | Total                 4,950
            |
            | Paid                  2,000
            | Balance               2,950
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


            /*
            |--------------------------------------------------------------------------
            | PENALTY
            |--------------------------------------------------------------------------
            |
            | Penalty is kept separate from interest because they are
            | governed by different rules and may have different calculation
            | bases and legal references.
            |
            */

            $table->decimal(
                'penalty_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | INTEREST
            |--------------------------------------------------------------------------
            |
            | Interest is kept separate from penalty.
            |
            | It is NOT calculated when the invoice is initially created
            | from an assessment.
            |
            | Initial value:
            |
            |     0
            |
            | It may later be accrued according to the applicable
            | InterestRule.
            |
            */

            $table->decimal(
                'interest_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | TOTAL AMOUNT
            |--------------------------------------------------------------------------
            |
            | Current financial total of the invoice.
            |
            | Conceptually:
            |
            | total_amount =
            |     subtotal
            |     - discount_amount
            |     + penalty_amount
            |     + interest_amount
            |
            */

            $table->decimal(
                'total_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | PAID AMOUNT
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'paid_amount',
                18,
                4
            )->default(0);


            /*
            |--------------------------------------------------------------------------
            | BALANCE DUE
            |--------------------------------------------------------------------------
            |
            | Current unpaid amount.
            |
            | Conceptually:
            |
            | balance_due =
            |     total_amount - paid_amount
            |
            */

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
            |     source_type = DIRECT_COLLECTION
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