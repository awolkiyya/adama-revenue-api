<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the receipt number from cash payment details.
     *
     * Receipts are managed independently in the `receipts` table.
     */
    public function up(): void
    {
        Schema::table('cash_payment_details', function (Blueprint $table) {
            $table->dropUnique([
                'cash_receipt_number',
            ]);

            $table->dropColumn('cash_receipt_number');
        });
    }

    /**
     * Restore the receipt number column if this migration is rolled back.
     */
    public function down(): void
    {
        Schema::table('cash_payment_details', function (Blueprint $table) {
            $table->string('cash_receipt_number', 100)
                ->nullable()
                ->unique();
        });
    }
};