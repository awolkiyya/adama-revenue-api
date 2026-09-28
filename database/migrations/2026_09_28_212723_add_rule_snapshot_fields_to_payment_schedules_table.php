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
        Schema::table('payment_schedules', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Rule Percentage Snapshot
            |--------------------------------------------------------------------------
            |
            | Stores the percentage that was actually applied when this
            | payment schedule was generated.
            |
            | Example:
            |
            |     Revenue code rule:
            |         first_installment_percentage = 10.00
            |
            |     Generated schedule:
            |         installment_number = 1
            |         rule_percentage = 10.00
            |         amount_due = 190000.0000
            |
            | This is a historical snapshot.
            |
            | It must remain unchanged even if the current revenue-code
            | payment schedule rule is changed later.
            |
            | NULL means that no percentage rule was applied to this
            | installment.
            |
            */

            $table->decimal('rule_percentage', 5, 2)
                ->nullable()
                ->after('installment_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table) {

            $table->dropColumn('rule_percentage');
        });
    }
};