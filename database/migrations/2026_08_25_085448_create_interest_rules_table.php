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
            | Annual Bank Interest Rate
            |--------------------------------------------------------------------------
            |
            | Global annual interest rate applicable to all revenue services.
            |
            | Stored as a percentage.
            |
            | Example:
            |
            | 24.7250 = 24.725% per year
            |
            | The calculation engine converts this to decimal form:
            |
            | 24.7250 / 100 = 0.24725
            |
            | Monthly rate is derived by the calculation engine:
            |
            | 24.7250 / 12 = 2.0604167% per month
            |
            | Do NOT store the monthly rate separately.
            |
            */

            $table->decimal('rate', 10, 4);

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
            | Defines the legal/business period during which this annual
            | interest rate applies.
            |
            | effective_to = NULL means the rule has no defined end date.
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
            | Historical rules remain stored in the database.
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
            | defining the applicable annual interest rate.
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
            |
            | Supports finding active rules within their effective period.
            |
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
        | Negative interest rates are not permitted.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE interest_rules
            ADD CONSTRAINT interest_rules_rate_non_negative
            CHECK (rate >= 0)
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Validate Calculation Basis
        |--------------------------------------------------------------------------
        |
        | Only these two monetary bases are supported.
        |
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
        |
        | effective_to cannot be earlier than effective_from.
        |
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
        | Because effective_to is business-inclusive, we add one day and
        | create a PostgreSQL half-open range:
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
        | But overlapping periods are rejected.
        |
        | NULL effective_to represents an open-ended rule.
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