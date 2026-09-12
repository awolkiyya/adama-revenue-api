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
        /*
        |--------------------------------------------------------------------------
        | Revenue General Settings
        |--------------------------------------------------------------------------
        |
        | Global configuration for the Revenue Management module.
        |
        | IMPORTANT:
        |
        | - Tariff rates/rules belong to tariff_rules.
        | - Penalty rates/rules belong to penalty_rules.
        | - Interest rates/rules belong to interest_rules.
        | - Assessment obligation due_date belongs to assessment_services.
        | - Invoice due_date belongs to invoices.
        | - Tariff calculation precision/rounding belongs to tariff_rules.
        | - Service-specific configuration belongs to revenue services/fields.
        |
        | This table contains only GLOBAL revenue-management behavior
        | and operational configuration.
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
            | Annual Payment Due Date
            |--------------------------------------------------------------------------
            |
            | Recurring annual payment deadline in the Ethiopian calendar.
            |
            | Stored as MM-DD.
            |
            | Examples:
            |
            |     "03-30"
            |     "12-30"
            |     "13-06"
            |
            | The value represents:
            |
            |     Ethiopian month + Ethiopian day
            |
            | It is NOT a Gregorian date.
            |
            | The application/calendar service is responsible for converting
            | this recurring Ethiopian-calendar date into the actual Gregorian
            | date for the applicable Ethiopian year.
            |
            | NULL means that no annual payment deadline has been configured.
            |
            | Year-specific validation, especially Pagume day 6, belongs
            | to the Ethiopian calendar service.
            |
            */

            $table->string('annual_payment_due_date', 5)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest Global Switches
            |--------------------------------------------------------------------------
            |
            | These fields only enable/disable the corresponding engines
            | globally.
            |
            | They do NOT contain:
            |
            | - rates
            | - formulas
            | - grace periods
            | - escalation rules
            | - maximum penalties
            |
            | Those belong to:
            |
            | - penalty_rules
            | - interest_rules
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

            $table->boolean('assessment_allow_manual_adjustment')
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

            /*
            | Partial payment is intentionally NOT stored globally.
            |
            | If the municipality later requires a partial-payment policy,
            | it should be modeled at the appropriate invoice/payment level.
            */

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


            /*
            |--------------------------------------------------------------------------
            | Enabled Payment Methods
            |--------------------------------------------------------------------------
            |
            | Defines which payment methods are globally available.
            |
            | Example:
            |
            | [
            |     "CASH",
            |     "BANK",
            |     "MOBILE_MONEY"
            | ]
            |
            | Individual payment-method configuration should be modeled
            | separately if a payment method later requires additional
            | properties such as provider, account, merchant ID, etc.
            |
            */

            $table->jsonb('enabled_payment_methods')
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
            | Status
            |--------------------------------------------------------------------------
            |
            | Revenue settings are modeled as a singleton global configuration.
            |
            | PostgreSQL will enforce that only ONE row can have:
            |
            |     is_active = true
            |
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
        });


        /*
        |--------------------------------------------------------------------------
        | Annual Payment Due Date Format
        |--------------------------------------------------------------------------
        |
        | The value must be:
        |
        |     MM-DD
        |
        | Examples:
        |
        |     01-01
        |     03-30
        |     12-30
        |     13-06
        |
        | NULL means no annual payment deadline is configured.
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_annual_payment_due_date_format_check
            CHECK (
                annual_payment_due_date IS NULL
                OR annual_payment_due_date ~ '^(0[1-9]|1[0-3])-(0[1-9]|[12][0-9]|30)$'
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Ethiopian Calendar Annual Payment Due Date
        |--------------------------------------------------------------------------
        |
        | Ethiopian calendar rules:
        |
        | Months 1-12:
        |     Days 1-30
        |
        | Month 13 (Pagume):
        |     Days 1-6
        |
        | Because the value is stored as MM-DD, PostgreSQL can enforce
        | the month/day relationship directly.
        |
        | The special year-specific validity of Pagume day 6 is handled
        | by the application/calendar service.
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_annual_payment_due_date_ethiopian_check
            CHECK (
                annual_payment_due_date IS NULL
                OR
                (
                    (
                        substring(annual_payment_due_date, 1, 2)::integer
                        BETWEEN 1 AND 12
                        AND
                        substring(annual_payment_due_date, 4, 2)::integer
                        BETWEEN 1 AND 30
                    )
                    OR
                    (
                        substring(annual_payment_due_date, 1, 2)::integer = 13
                        AND
                        substring(annual_payment_due_date, 4, 2)::integer
                        BETWEEN 1 AND 6
                    )
                )
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Invoice Prefix Constraint
        |--------------------------------------------------------------------------
        |
        | Prevent empty or whitespace-only prefixes.
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_invoice_prefix_check
            CHECK (
                length(trim(invoice_prefix)) BETWEEN 1 AND 30
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Receipt Prefix Constraint
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_receipt_prefix_check
            CHECK (
                length(trim(receipt_prefix)) BETWEEN 1 AND 30
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Enabled Payment Methods - JSON Array
        |--------------------------------------------------------------------------
        |
        | The database guarantees that this field is an array.
        |
        | The application/service layer validates individual values
        | against the supported payment-method enum.
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_methods_array_check
            CHECK (
                jsonb_typeof(enabled_payment_methods) = 'array'
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Enabled Payment Methods - Not Empty
        |--------------------------------------------------------------------------
        |
        | At least one payment method must remain enabled.
        |
        */

        DB::statement("
            ALTER TABLE revenue_settings
            ADD CONSTRAINT revenue_settings_payment_methods_not_empty_check
            CHECK (
                jsonb_array_length(enabled_payment_methods) > 0
            )
        ");


        /*
        |--------------------------------------------------------------------------
        | Single Active Global Configuration
        |--------------------------------------------------------------------------
        |
        | PostgreSQL partial unique index.
        |
        | Guarantees:
        |
        |     Maximum one active configuration.
        |
        | Multiple historical/inactive records are technically allowed.
        |
        */

        DB::statement("
            CREATE UNIQUE INDEX revenue_settings_one_active_unique
            ON revenue_settings (is_active)
            WHERE is_active = true
        ");
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_settings');
    }
};
