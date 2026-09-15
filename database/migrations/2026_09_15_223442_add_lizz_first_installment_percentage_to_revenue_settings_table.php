<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('revenue_settings', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Lizz First Installment Percentage
            |--------------------------------------------------------------------------
            |
            | Global Lizz policy percentage used when:
            |
            |     first_installment_required = true
            |
            | Example:
            |
            |     10.00 = 10%
            |
            | This is a global Lizz configuration.
            | The resolved percentage should be copied to the assessment
            | when the assessment is created so historical assessments
            | remain unchanged if this setting is updated later.
            |
            */

            $table->decimal('lizz_first_installment_percentage', 5, 2)
                ->nullable()
                ->after('interest_enabled');
        });

        /*
        |--------------------------------------------------------------------------
        | Percentage Validation
        |--------------------------------------------------------------------------
        |
        | Valid values:
        |
        |     NULL
        |     > 0 and <= 100
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_lizz_first_installment_percentage_check
            CHECK (
                lizz_first_installment_percentage IS NULL
                OR (
                    lizz_first_installment_percentage > 0
                    AND lizz_first_installment_percentage <= 100
                )
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE revenue_settings
            DROP CONSTRAINT IF EXISTS revenue_settings_lizz_first_installment_percentage_check
        ");

        Schema::table('revenue_settings', function (Blueprint $table) {
            $table->dropColumn('lizz_first_installment_percentage');
        });
    }
};
