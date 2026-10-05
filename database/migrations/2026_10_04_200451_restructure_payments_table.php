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
        /*
        |--------------------------------------------------------------------------
        | Remove Obsolete Payment-Specific Columns
        |--------------------------------------------------------------------------
        |
        | These fields have been moved to the payment detail tables:
        |
        | CASH
        |   -> cash_payment_details
        |
        | BANK_TRANSFER
        |   -> bank_transfer_details
        |
        | ONLINE
        |   -> online_payment_details
        |
        */

        Schema::table('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Remove Old Indexes
            |--------------------------------------------------------------------------
            */

            $table->dropIndex([
                'payment_provider',
                'status',
            ]);

            $table->dropIndex([
                'payment_date',
                'status',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Remove Foreign Key
            |--------------------------------------------------------------------------
            */

            $table->dropForeign([
                'received_by',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Remove Obsolete Columns
            |--------------------------------------------------------------------------
            */

            $table->dropColumn([
                'payment_provider',
                'provider_reference',
                'payment_date',
                'checkout_url',
                'provider_response',
                'received_by',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Restore Removed Columns
            |--------------------------------------------------------------------------
            */

            $table->string('payment_provider', 50)
                ->nullable();

            $table->string('provider_reference', 150)
                ->nullable()
                ->index();

            $table->timestamp('payment_date')
                ->nullable();

            $table->text('checkout_url')
                ->nullable();

            $table->json('provider_response')
                ->nullable();

            $table->foreignUuid('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Restore Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'payment_provider',
                'status',
            ]);

            $table->index([
                'payment_date',
                'status',
            ]);
        });
    }
};