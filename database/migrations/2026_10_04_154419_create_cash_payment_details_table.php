<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payment_details', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('payment_id')
                ->unique()
                ->constrained('payments')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Cash Receiver
            |--------------------------------------------------------------------------
            |
            | The user who physically received the cash.
            |
            */

            $table->foreignUuid('received_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Cash Receipt
            |--------------------------------------------------------------------------
            */

            $table->string('cash_receipt_number', 100)
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Cashier / Collection Session
            |--------------------------------------------------------------------------
            |
            | Nullable because your system may not yet have cashier sessions.
            |
            */

            $table->uuid('cashier_session_id')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Cash Collection Time
            |--------------------------------------------------------------------------
            */

            $table->timestamp('cash_received_at');

            $table->text('notes')
                ->nullable();

            $table->timestamps();

            $table->index([
                'received_by',
                'cash_received_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payment_details');
    }
};