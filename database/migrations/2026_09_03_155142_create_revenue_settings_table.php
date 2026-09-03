<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Revenue General Settings
        |--------------------------------------------------------------------------
        |
        | Global configuration for the Revenue Management module.
        |
        | IMPORTANT:
        | - Penalty rates/rules belong to penalty_rules.
        | - Interest rates/rules belong to interest_rules.
        | - Actual invoice due_date belongs to invoices.
        | - This table contains global revenue behavior and configuration.
        |
        */

        Schema::create('revenue_settings', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            |
            | Global revenue payment period.
            |
            | Example:
            | Adooleessa 1 → Guraandhala 30
            |
            */

            $table->unsignedTinyInteger('payment_start_month');

            $table->unsignedTinyInteger('payment_start_day');

            $table->unsignedTinyInteger('payment_end_month');

            $table->unsignedTinyInteger('payment_end_day');


            /*
            |--------------------------------------------------------------------------
            | Calendar Configuration
            |--------------------------------------------------------------------------
            */

            $table->string('calendar_type', 20)
                ->default('ETHIOPIAN');


            /*
            |--------------------------------------------------------------------------
            | Penalty Configuration
            |--------------------------------------------------------------------------
            |
            | These are global switches/behavior settings only.
            |
            | Actual penalty policies are stored in penalty_rules.
            |
            */

            $table->boolean('penalty_enabled')
                ->default(true);

            $table->boolean('interest_enabled')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | Assessment Settings
            |--------------------------------------------------------------------------
            */

            $table->boolean('assessment_auto_calculation')
                ->default(true);

            $table->boolean('assessment_manual_adjustment')
                ->default(false);

            $table->boolean('assessment_requires_approval')
                ->default(false);

            $table->boolean('assessment_reassessment_allowed')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | Invoice Settings
            |--------------------------------------------------------------------------
            */

            $table->boolean('invoice_auto_numbering')
                ->default(true);

            $table->string('invoice_prefix', 30)
                ->default('INV');

            $table->boolean('invoice_allow_partial_payment')
                ->default(true);

            $table->boolean('invoice_allow_overpayment')
                ->default(false);

            $table->boolean('invoice_allow_overdue_payment')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | Payment Settings
            |--------------------------------------------------------------------------
            */

            $table->boolean('payment_confirmation_required')
                ->default(true);

            $table->boolean('payment_auto_receipt')
                ->default(true);

            $table->boolean('payment_allow_partial')
                ->default(true);

            /*
            | Enabled payment methods.
            |
            | Example:
            | [
            |     "CASH",
            |     "BANK",
            |     "MOBILE_MONEY"
            | ]
            */

            $table->jsonb('payment_methods')
                ->default(json_encode([
                    'CASH',
                    'BANK',
                    'MOBILE_MONEY',
                ]));


            /*
            |--------------------------------------------------------------------------
            | Receipt Settings
            |--------------------------------------------------------------------------
            */

            $table->boolean('receipt_auto_numbering')
                ->default(true);

            $table->string('receipt_prefix', 30)
                ->default('REC');

            $table->boolean('receipt_allow_reprint')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | Calculation Settings
            |--------------------------------------------------------------------------
            */

            $table->string('currency', 10)
                ->default('ETB');

            $table->unsignedTinyInteger('decimal_places')
                ->default(2);

            $table->unsignedTinyInteger('percentage_precision')
                ->default(4);

            $table->string('rounding_mode', 20)
                ->default('HALF_UP');


            /*
            |--------------------------------------------------------------------------
            | Revenue Calculation Order
            |--------------------------------------------------------------------------
            |
            | Determines the order used by the revenue calculation engine.
            |
            */

            $table->string('calculation_order', 30)
                ->default('PENALTY_THEN_INTEREST');


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true);


            /*
            |--------------------------------------------------------------------------
            | Legal / Description
            |--------------------------------------------------------------------------
            */

            $table->string('legal_reference', 500)
                ->nullable();

            $table->text('description')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Audit Users
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignUuid('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


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

            $table->index(
                'is_active',
                'revenue_settings_active_index'
            );

            $table->index(
                'calendar_type',
                'revenue_settings_calendar_index'
            );
        });


        /*
        |--------------------------------------------------------------------------
        | Payment Period Constraints
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_start_month_check
            CHECK (payment_start_month BETWEEN 1 AND 13)
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_end_month_check
            CHECK (payment_end_month BETWEEN 1 AND 13)
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_start_day_check
            CHECK (payment_start_day BETWEEN 1 AND 31)
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_end_day_check
            CHECK (payment_end_day BETWEEN 1 AND 31)
        ");


        /*
        |--------------------------------------------------------------------------
        | Calendar Constraint
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_calendar_type_check
            CHECK (
                calendar_type IN (
                    'ETHIOPIAN',
                    'GREGORIAN'
                )
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Calculation Constraints
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_decimal_places_check
            CHECK (decimal_places BETWEEN 0 AND 6)
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_percentage_precision_check
            CHECK (percentage_precision BETWEEN 0 AND 8)
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_rounding_mode_check
            CHECK (
                rounding_mode IN (
                    'HALF_UP',
                    'HALF_DOWN',
                    'HALF_EVEN',
                    'UP',
                    'DOWN'
                )
            )
        ");

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_calculation_order_check
            CHECK (
                calculation_order IN (
                    'PENALTY_THEN_INTEREST',
                    'INTEREST_THEN_PENALTY'
                )
            )
        ");
    }


    public function down(): void
    {
        Schema::dropIfExists('revenue_settings');
    }
};