<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | BILLING / FISCAL PERIOD
            |--------------------------------------------------------------------------
            |
            | The Ethiopian fiscal year for which this invoice represents
            | a financial obligation.
            |
            | Nullable for now because existing invoices may not have
            | a fiscal year assigned yet.
            |
            | Example:
            |
            | Assessment:
            |     2017
            |
            | Invoices:
            |     2017 → INV-2017-000001
            |     2018 → INV-2018-000001
            |     2019 → INV-2019-000001
            |
            | An assessment may therefore generate invoices across
            | multiple fiscal years.
            |
            */

            $table->unsignedSmallInteger('fiscal_year')
                ->nullable()
                ->after('assessment_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->dropIndex([
                'invoices_fiscal_year_index',
            ]);

            $table->dropColumn('fiscal_year');
        });
    }
};