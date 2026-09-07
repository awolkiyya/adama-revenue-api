<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_rules', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Interest Rate
            |--------------------------------------------------------------------------
            |
            | The configured interest rate.
            |
            | The meaning of the rate is determined by rate_period.
            |
            | Examples:
            |
            | 24.7250 + YEAR  = 24.725% per year
            | 2.0000   + MONTH = 2% per month
            | 0.0500   + DAY   = 0.05% per day
            |
            | Stored as a percentage, not a decimal.
            |
            | Example:
            |
            | 24.7250 / 100 = 0.24725
            |
            */

            $table->decimal('rate', 10, 4);

            /*
            |--------------------------------------------------------------------------
            | Rate Period
            |--------------------------------------------------------------------------
            |
            | Defines the period represented by the configured rate.
            |
            | YEAR
            | MONTH
            | DAY
            |
            */

            $table->string('rate_period', 20)
                ->default('YEAR');

            /*
            |--------------------------------------------------------------------------
            | Calculation Method
            |--------------------------------------------------------------------------
            |
            | Defines how interest is accumulated.
            |
            | SIMPLE
            | COMPOUND
            |
            */

            $table->string('calculation_method', 30)
                ->default('SIMPLE');

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            |
            | Defines the monetary amount against which interest is calculated.
            |
            | PRINCIPAL
            | OUTSTANDING
            |
            */

            $table->string('calculation_basis', 30)
                ->default('PRINCIPAL');

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            |
            | Defines the legal/business period during which this interest
            | rule is applicable.
            |
            | effective_to = NULL means no defined end date.
            |
            */

            $table->date('effective_from');

            $table->date('effective_to')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Administrative Status
            |--------------------------------------------------------------------------
            |
            | is_active controls whether the configuration is administratively
            | enabled.
            |
            | effective_from / effective_to determine legal applicability.
            |
            | Historical rules remain stored.
            |
            */

            $table->boolean('is_active')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | Legal Reference
            |--------------------------------------------------------------------------
            |
            | Reference to the law, regulation, directive, proclamation,
            | council decision, bank directive, or other legal instrument
            | defining the applicable interest rule.
            |
            */

            $table->string('legal_reference')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Description
            |--------------------------------------------------------------------------
            */

            $table->text('description')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit Ownership
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
            | Query Index
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'is_active',
                    'effective_from',
                    'effective_to',
                ],
                'interest_rules_active_effective_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Validate Interest Rate
        |--------------------------------------------------------------------------
        |
        | Interest rate cannot be negative.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_rate_non_negative
            CHECK (rate >= 0)
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Validate Rate Period
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_rate_period_check
            CHECK (
                rate_period IN ('YEAR', 'MONTH', 'DAY')
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Validate Calculation Method
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_calculation_method_check
            CHECK (
                calculation_method IN ('SIMPLE', 'COMPOUND')
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Validate Calculation Basis
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_calculation_basis_check
            CHECK (
                calculation_basis IN ('PRINCIPAL', 'OUTSTANDING')
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Validate Effective Period
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_valid_effective_period
            CHECK (
                effective_to IS NULL
                OR effective_to >= effective_from
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL Exclusion Constraint
        |--------------------------------------------------------------------------
        |
        | Prevents overlapping ACTIVE interest rules.
        |
        | effective_to is business-inclusive.
        |
        | Example:
        |
        | 2026-07-08 → 2027-07-07
        |
        | becomes:
        |
        | [2026-07-08, 2027-07-08)
        |
        | Therefore:
        |
        | 2026-07-08 → 2027-07-07
        | 2027-07-08 → 2028-07-07
        |
        | are valid.
        |
        | Overlapping active periods are rejected.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_no_overlapping_periods
            EXCLUDE USING gist (
                daterange(
                    effective_from,
                    COALESCE(effective_to + 1, 'infinity'::date),
                    '[)'
                ) WITH &&
            )
            WHERE (
                is_active = true
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_rules');
    }
};