<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('field_collections', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Collection Reference
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | FCN-2026-000001
            |
            */

            $table->string('collection_number', 100)
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Taxpayer / Citizen
            |--------------------------------------------------------------------------
            |
            | The taxpayer/citizen this field collection was
            | collected from.
            |
            */

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            |
            | The office / administrative unit this collection
            | belongs to.
            |
            */

            $table->foreignUuid('administrative_unit_id')
                ->nullable()
                ->constrained('administrative_units')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Collected By
            |--------------------------------------------------------------------------
            |
            | The field collector who captured this collection.
            |
            */

            $table->foreignUuid('collected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Collection Date
            |--------------------------------------------------------------------------
            |
            | Business date on which the field collection took
            | place. Always set automatically to the submission
            | date since collections are recorded live, on-site.
            |
            */

            $table->date('collection_date')
                ->default(now()->toDateString())
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Collection Status
            |--------------------------------------------------------------------------
            |
            | CAPTURED
            |   Collection has been recorded and its invoice
            |   generated. There is no approval step — a field
            |   collection is authoritative the moment it is
            |   created.
            |
            | CANCELLED
            |   Collection has been voided after the fact.
            |
            */

            $table->enum('status', [
                'CAPTURED',
                'CANCELLED',
            ])
            ->default('CAPTURED')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Collection Notes
            |--------------------------------------------------------------------------
            */

            $table->text('notes')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            |
            | The invoice generated for this collection. Field
            | collections generate their invoice directly on
            | creation — there is no separate pricing-preview
            | step beforehand.
            |
            */

            $table->foreignUuid('invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Cancellation
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('cancellation_reason')
                ->nullable();

            $table->timestamp('cancelled_at')
                ->nullable();


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

            $table->index([
                'citizen_id',
                'status',
            ]);

            $table->index([
                'administrative_unit_id',
                'status',
            ]);

            $table->index([
                'collected_by',
                'collection_date',
            ]);

            $table->index([
                'created_by',
                'created_at',
            ]);

            $table->index([
                'collection_date',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_collections');
    }
};