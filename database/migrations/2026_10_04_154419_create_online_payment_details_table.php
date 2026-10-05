<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payment_details', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('payment_id')
                ->unique()
                ->constrained('payments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Payment Provider
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Provider References
            |--------------------------------------------------------------------------
            */

            $table->string(
                'checkout_reference',
                150
            )->nullable()->index();

            $table->string(
                'provider_transaction_id',
                150
            )->nullable()->index();


            /*
            |--------------------------------------------------------------------------
            | Checkout
            |--------------------------------------------------------------------------
            */

            $table->text('checkout_url')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Provider Status
            |--------------------------------------------------------------------------
            */

            $table->string(
                'provider_status',
                50
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Callback
            |--------------------------------------------------------------------------
            */

            $table->timestamp(
                'callback_received_at'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Provider Response
            |--------------------------------------------------------------------------
            */

            $table->json(
                'provider_response'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Payment Completion
            |--------------------------------------------------------------------------
            */

            $table->timestamp(
                'paid_at'
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
                'payment_provider_id',
                'provider_status',
            ]);

            $table->index([
                'paid_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payment_details');
    }
};