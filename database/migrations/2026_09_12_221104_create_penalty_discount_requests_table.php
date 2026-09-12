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
        Schema::create('penalty_discount_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Target Invoice
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Request
            |--------------------------------------------------------------------------
            |
            | Created by the Revenue Compliance Officer.
            |
            */

            $table->decimal('requested_amount', 18, 4);

            $table->text('reason');

            $table->enum('status', [
                'DRAFT',
                'SUBMITTED',
                'DECIDED',
                'CANCELLED',
            ])->default('DRAFT');

            $table->foreignUuid('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('submitted_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Administrative Decision
            |--------------------------------------------------------------------------
            |
            | Completed by the Revenue Tax Administrative Officer.
            |
            */

            $table->enum('decision', [
                'APPROVED',
                'REJECTED',
            ])->nullable();

            $table->decimal('approved_amount', 18, 4)->nullable();

            $table->text('decision_reason')->nullable();

            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('decided_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Application Tracking
            |--------------------------------------------------------------------------
            |
            | Records whether the approved discount has actually been
            | applied to the invoice.
            |
            */

            $table->boolean('applied_to_invoice')
                ->default(false);

            $table->timestamp('applied_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit / Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['invoice_id', 'status'],
                'penalty_discount_requests_invoice_status_index'
            );

            $table->index(
                ['citizen_id', 'status'],
                'penalty_discount_requests_citizen_status_index'
            );

            $table->index(
                ['created_by', 'status'],
                'penalty_discount_requests_created_by_status_index'
            );

            $table->index(
                ['decided_by', 'decision'],
                'penalty_discount_requests_decided_by_decision_index'
            );

            $table->index('submitted_at');
            $table->index('decided_at');
            $table->index('applied_to_invoice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_discount_requests');
    }
};