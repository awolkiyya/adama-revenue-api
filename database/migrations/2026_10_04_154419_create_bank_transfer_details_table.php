<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfer_details', function (Blueprint $table) {

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
            | Destination Bank Account
            |--------------------------------------------------------------------------
            |
            | The municipal bank account that received the transfer.
            |
            */

            $table->foreignUuid('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Transfer Information
            |--------------------------------------------------------------------------
            */

            $table->string(
                'transfer_reference',
                150
            )->index();

            $table->timestamp(
                'transfer_date'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Sender Information
            |--------------------------------------------------------------------------
            */

            $table->string(
                'sender_name',
                255
            )->nullable();

            $table->string(
                'sender_account',
                100
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Verification
            |--------------------------------------------------------------------------
            */

            $table->string(
                'verification_status',
                30
            )->default('PENDING');

            $table->foreignUuid('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp(
                'verified_at'
            )->nullable();


            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            $table->text('notes')
                ->nullable();


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
                'verification_status',
                'created_at',
            ]);

            $table->index([
                'verified_by',
                'verified_at',
            ]);

            $table->index([
                'bank_account_id',
                'transfer_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_details');
    }
};