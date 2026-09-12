<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevenueSettingSeeder extends Seeder
{
    /**
     * Seed the Revenue Management global settings.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Default Global Revenue Settings
        |--------------------------------------------------------------------------
        |
        | This creates the initial global Revenue Management configuration.
        |
        | This configuration contains only global operational behavior.
        |
        | Tariff rates       -> tariff_rules
        | Penalty rules      -> penalty_rules
        | Interest rules     -> interest_rules
        | Legal due date     -> annual_payment_due_date
        |
        | The annual payment due date is stored as an Ethiopian recurring
        | MM-DD value. The year is intentionally not stored.
        |
        */

        DB::transaction(function () {

            /*
            |--------------------------------------------------------------------------
            | Find Existing Active Configuration
            |--------------------------------------------------------------------------
            |
            | revenue_settings is a singleton configuration.
            |
            | The database guarantees that only one active record can exist
            | through the partial unique index.
            |
            */

            $existing = DB::table('revenue_settings')
                ->where('is_active', true)
                ->first();

            if ($existing) {
                /*
                |--------------------------------------------------------------------------
                | Configuration Already Exists
                |--------------------------------------------------------------------------
                |
                | Do not overwrite administrator-configured values.
                |
                | This makes the seeder safe to run multiple times.
                |
                */

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Create Initial Configuration
            |--------------------------------------------------------------------------
            */

            DB::table('revenue_settings')->insert([

                /*
                |--------------------------------------------------------------------------
                | Primary Key
                |--------------------------------------------------------------------------
                */

                'id' => (string) Str::uuid(),


                /*
                |--------------------------------------------------------------------------
                | Annual Payment Due Date
                |--------------------------------------------------------------------------
                |
                | Ethiopian recurring annual deadline.
                |
                | Month 13 = Pagume
                | Day 6    = final Pagume day
                |
                | Stored as:
                |
                |     MM-DD
                |
                | The year is intentionally omitted because this deadline
                | recurs every Ethiopian calendar year.
                |
                */

                'annual_payment_due_date' => '13-06',


                /*
                |--------------------------------------------------------------------------
                | Penalty / Interest
                |--------------------------------------------------------------------------
                |
                | These fields only enable or disable the calculation engines.
                |
                | Actual penalty rates/rules are configured in:
                |
                |     penalty_rules
                |
                | Actual interest rates/rules are configured in:
                |
                |     interest_rules
                |
                */

                'penalty_enabled' => true,

                'interest_enabled' => true,


                /*
                |--------------------------------------------------------------------------
                | Assessment
                |--------------------------------------------------------------------------
                */

                'assessment_auto_calculation' => true,

                'assessment_allow_manual_adjustment' => false,

                'assessment_requires_approval' => false,

                'assessment_reassessment_allowed' => true,


                /*
                |--------------------------------------------------------------------------
                | Invoice
                |--------------------------------------------------------------------------
                */

                'invoice_auto_numbering' => true,

                'invoice_prefix' => 'INV',

                'invoice_allow_overpayment' => false,

                'invoice_allow_overdue_payment' => true,


                /*
                |--------------------------------------------------------------------------
                | Payment
                |--------------------------------------------------------------------------
                */

                'payment_confirmation_required' => true,

                'payment_auto_receipt' => true,


                /*
                |--------------------------------------------------------------------------
                | Payment Methods
                |--------------------------------------------------------------------------
                |
                | At least one payment method must be enabled.
                |
                | The database migration defaults to:
                |
                |     CASH
                |     BANK
                |     MOBILE_MONEY
                |
                */

                'enabled_payment_methods' => json_encode([
                    'CASH',
                    'BANK',
                    'MOBILE_MONEY',
                ]),


                /*
                |--------------------------------------------------------------------------
                | Receipt
                |--------------------------------------------------------------------------
                */

                'receipt_auto_numbering' => true,

                'receipt_prefix' => 'REC',

                'receipt_allow_reprint' => true,


                /*
                |--------------------------------------------------------------------------
                | Status
                |--------------------------------------------------------------------------
                */

                'is_active' => true,


                /*
                |--------------------------------------------------------------------------
                | Legal / Description
                |--------------------------------------------------------------------------
                */

                'legal_reference' => null,

                'description' =>
                    'Global Revenue Management configuration. ' .
                    'The annual payment due date is used to resolve ' .
                    'the applicable legal due date for assessment services. ' .
                    'Tariff, penalty, and interest rules are managed ' .
                    'through their respective rule configurations.',


                /*
                |--------------------------------------------------------------------------
                | Audit
                |--------------------------------------------------------------------------
                |
                | Seeder-created configuration has no application user
                | as creator/updater.
                |
                */

                'created_by' => null,

                'updated_by' => null,


                /*
                |--------------------------------------------------------------------------
                | Timestamps
                |--------------------------------------------------------------------------
                */

                'created_at' => now(),

                'updated_at' => now(),
            ]);
        });
    }
}
