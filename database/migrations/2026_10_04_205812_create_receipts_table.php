<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the receipts table.
     *
     * A receipt is the official document issued after a payment
     * has been successfully completed.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * One payment can have at most one active receipt.
             *
             * Receipt belongs to the payment, not to a specific
             * payment method. Therefore cash, bank transfer,
             * and online payments can all generate receipts.
             */
            $table->foreignUuid('payment_id')
                ->unique()
                ->constrained('payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Official municipal receipt number.
             *
             * Example:
             * RCP-2026-000001
             */
            $table->string('receipt_number', 100)
                ->unique();

            /*
             * User who officially issued/generated the receipt.
             */
            $table->foreignUuid('issued_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * Time at which the receipt was officially issued.
             */
            $table->timestamp('issued_at');

            /*
             * Receipt lifecycle.
             *
             * ISSUED   = valid receipt
             * VOIDED   = receipt cancelled/invalidated
             */
            $table->string('status', 30)
                ->default('ISSUED');

            /*
             * Optional reason or administrative note.
             *
             * Particularly useful when a receipt is voided.
             */
            $table->text('notes')->nullable();

            $table->timestamps();

            /*
             * Operational indexes.
             */
            $table->index([
                'issued_by',
                'issued_at',
            ]);

            $table->index([
                'status',
                'issued_at',
            ]);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};