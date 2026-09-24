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
        Schema::table('assessment_services', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Historical Payment Summary
            |--------------------------------------------------------------------------
            |
            | Used especially for EXISTING_LIZZ assessments.
            |
            | paid_amount:
            | Total amount already paid before the assessment was
            | registered in the system.
            |
            | remaining_amount:
            | Historical outstanding balance at the time of registration.
            |
            | balance_as_of_date:
            | Date on which the historical paid/balance information
            | was established or confirmed.
            |
            */

            $table->decimal('paid_amount', 18, 4)
                ->default(0)
                ->after('computed_amount');

            $table->decimal('remaining_amount', 18, 4)
                ->nullable()
                ->after('paid_amount');

            $table->date('balance_as_of_date')
                ->nullable()
                ->after('remaining_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_services', function (Blueprint $table) {
            $table->dropColumn([
                'paid_amount',
                'remaining_amount',
                'balance_as_of_date',
            ]);
        });
    }
};
