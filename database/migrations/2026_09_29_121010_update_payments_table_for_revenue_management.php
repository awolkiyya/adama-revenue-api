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
        Schema::table('payments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Payment Number
            |--------------------------------------------------------------------------
            |
            | Human-readable, system-generated payment identifier.
            |
            | Example:
            |
            | PAY-2026-000001
            |
            | This is different from:
            |
            | transaction_reference:
            | Internal/payment transaction reference.
            |
            | provider_reference:
            | Reference returned by a bank/payment provider.
            |
            */

            $table->string('payment_number', 100)
                ->unique()
                ->after('invoice_id');


            /*
            |--------------------------------------------------------------------------
            | Payment Verification / Audit
            |--------------------------------------------------------------------------
            |
            | received_by:
            | The revenue collector/user who recorded the payment.
            |
            | verified_by:
            | The user who verified or rejected the payment.
            |
            */

            $table->foreignUuid('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignUuid('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Additional Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'received_by',
                'created_at',
            ]);

            $table->index([
                'verified_by',
                'verified_at',
            ]);

            $table->index([
                'payment_date',
                'status',
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
            | Remove Indexes
            |--------------------------------------------------------------------------
            */

            $table->dropIndex([
                'received_by',
                'created_at',
            ]);

            $table->dropIndex([
                'verified_by',
                'verified_at',
            ]);

            $table->dropIndex([
                'payment_date',
                'status',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Remove Foreign Keys
            |--------------------------------------------------------------------------
            */

            $table->dropForeign([
                'received_by',
            ]);

            $table->dropForeign([
                'verified_by',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Remove Payment Number
            |--------------------------------------------------------------------------
            */

            $table->dropUnique([
                'payment_number',
            ]);

            $table->dropColumn([
                'payment_number',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Remove Verification Columns
            |--------------------------------------------------------------------------
            */

            $table->dropColumn([
                'received_by',
                'verified_by',
            ]);
        });
    }
};