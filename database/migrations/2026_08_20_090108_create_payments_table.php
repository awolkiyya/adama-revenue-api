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
        Schema::create('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Business References
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('invoice_id')
                ->constrained('invoices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->cascadeOnUpdate()
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Citizen / Payer
            |--------------------------------------------------------------------------
            |
            | The citizen who owns/makes the payment.
            |
            | citizens.id is UUID.
            |
            */

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->cascadeOnUpdate()
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            $table->string('payment_method', 50);

            $table->string('payment_provider', 50);

            $table->string('status', 30)
                ->default('PENDING');


            /*
            |--------------------------------------------------------------------------
            | Transaction References
            |--------------------------------------------------------------------------
            */

            $table->string(
                'transaction_reference',
                150
            )->unique();

            $table->string(
                'provider_reference',
                150
            )
                ->nullable()
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            $table->decimal(
                'amount',
                15,
                2
            );

            $table->string(
                'currency',
                10
            )->default('ETB');


            /*
            |--------------------------------------------------------------------------
            | Payment Dates
            |--------------------------------------------------------------------------
            */

            $table->timestamp(
                'payment_date'
            )->nullable();

            $table->timestamp(
                'verified_at'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Online Checkout
            |--------------------------------------------------------------------------
            */

            $table->text(
                'checkout_url'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Payer Snapshot
            |--------------------------------------------------------------------------
            |
            | These fields preserve the payer information at the
            | time of payment for audit/reconciliation purposes.
            |
            */

            $table->string(
                'payer_name',
                255
            )->nullable();

            $table->string(
                'payer_email',
                255
            )->nullable();

            $table->string(
                'payer_phone',
                50
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Failure Information
            |--------------------------------------------------------------------------
            */

            $table->text(
                'failure_reason'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Provider Response
            |--------------------------------------------------------------------------
            |
            | Raw response from Chapa, Telebirr, etc.
            |
            */

            $table->json(
                'provider_response'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Application Metadata
            |--------------------------------------------------------------------------
            */

            $table->json(
                'metadata'
            )->nullable();


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

            $table->index([
                'invoice_id',
                'status',
            ]);

            $table->index([
                'citizen_id',
                'status',
            ]);

            $table->index([
                'payment_provider',
                'status',
            ]);

            $table->index([
                'payment_method',
                'status',
            ]);

            $table->index([
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};