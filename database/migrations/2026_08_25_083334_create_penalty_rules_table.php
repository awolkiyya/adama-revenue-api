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
        | PostgreSQL GiST Extension
        |--------------------------------------------------------------------------
        |
        | Required for the PostgreSQL exclusion constraint used below.
        |
        */

        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS btree_gist'
        );

        /*
        |--------------------------------------------------------------------------
        | Penalty Rules
        |--------------------------------------------------------------------------
        |
        | A penalty rule defines the legal/business policy used to determine
        | penalties on overdue revenue assessments.
        |
        |--------------------------------------------------------------------------
        | IMPORTANT ARCHITECTURAL RULE
        |--------------------------------------------------------------------------
        |
        | This table defines HOW penalty commencement is determined.
        |
        | It does NOT store the global payment-period dates.
        |
        | Global payment-period configuration belongs to:
        |
        |     revenue_settings
        |
        | Specifically:
        |
        |     payment_start_month
        |     payment_start_day
        |     payment_end_month
        |     payment_end_day
        |
        |--------------------------------------------------------------------------
        | Penalty Commencement Types
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH
        |     Penalty commencement is determined from the global revenue
        |     payment-period configuration in revenue_settings.
        |
        |     The calculation engine reads:
        |
        |         revenue_settings.payment_start_month
        |         revenue_settings.payment_start_day
        |
        |     Therefore, no fiscal month is duplicated in this table.
        |
        | AGREEMENT_DATE
        |     Penalty commencement is determined from the applicable
        |     agreement date.
        |
        |--------------------------------------------------------------------------
        | Example
        |--------------------------------------------------------------------------
        |
        | penalty_rules:
        |
        |     start_type = FIXED_FISCAL_MONTH
        |
        | revenue_settings:
        |
        |     payment_start_month = 2
        |     payment_start_day   = 1
        |
        | The penalty engine uses the configured Ethiopian fiscal
        | payment-period start when determining commencement.
        |
        |--------------------------------------------------------------------------
        | Active Rules
        |--------------------------------------------------------------------------
        |
        | Multiple active rules are allowed only when their commencement
        | strategies are different.
        |
        | Two ACTIVE rules using the SAME start_type may not have overlapping
        | effective periods.
        |
        | Example:
        |
        |     FIXED_FISCAL_MONTH
        |     2025-09-11 → NULL
        |
        |     AGREEMENT_DATE
        |     2025-09-11 → NULL
        |
        | VALID:
        |
        | They represent different commencement strategies.
        |
        |--------------------------------------------------------------------------
        | Invalid Example
        |--------------------------------------------------------------------------
        |
        |     AGREEMENT_DATE
        |     2025-09-11 → NULL
        |
        |     AGREEMENT_DATE
        |     2026-09-04 → NULL
        |
        | INVALID:
        |
        | Both belong to the same commencement strategy and their effective
        | periods overlap.
        |
        */

        Schema::create('penalty_rules', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Rule Identification
            |--------------------------------------------------------------------------
            */

            $table->string('name', 150);

            /*
            |--------------------------------------------------------------------------
            | Progressive Penalty Rates
            |--------------------------------------------------------------------------
            |
            | Rates are stored as percentage points.
            |
            | Example:
            |
            |     initial_rate   = 5.0000
            |     increment_rate = 2.0000
            |     maximum_rate   = 25.0000
            |
            | Result:
            |
            |     Period 1 = 5%
            |     Period 2 = 7%
            |     Period 3 = 9%
            |     ...
            |     Maximum  = 25%
            |
            */

            $table->decimal('initial_rate', 10, 4)
                ->default(5.0000);

            $table->decimal('increment_rate', 10, 4)
                ->default(2.0000);

            $table->decimal('maximum_rate', 10, 4)
                ->default(25.0000);

            /*
            |--------------------------------------------------------------------------
            | Increment Period
            |--------------------------------------------------------------------------
            |
            | Current business/legal configuration supports MONTH only.
            |
            */

            $table->string('increment_period', 20)
                ->default('MONTH');

            /*
            |--------------------------------------------------------------------------
            | Penalty Commencement Type
            |--------------------------------------------------------------------------
            |
            | FIXED_FISCAL_MONTH
            |     Uses the global payment-period configuration from
            |     revenue_settings.
            |
            | AGREEMENT_DATE
            |     Uses the applicable agreement date.
            |
            | IMPORTANT:
            |
            | No payment month/day is stored here.
            |
            | revenue_settings is the single source of truth for the
            | global payment period.
            |
            */

            $table->string('start_type', 30)
                ->default('FIXED_FISCAL_MONTH');

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            |
            | PRINCIPAL
            |     Calculate penalty against the original assessed amount.
            |
            | OUTSTANDING
            |     Calculate penalty against the remaining unpaid amount.
            |
            */

            $table->string('calculation_basis', 30)
                ->default('PRINCIPAL');

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            |
            | effective_from:
            |     First date on which this penalty rule is applicable.
            |
            | effective_to:
            |     Last date on which this penalty rule is applicable.
            |
            | NULL:
            |     No defined end date.
            |
            */

            $table->date('effective_from');

            $table->date('effective_to')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Administrative Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | Legal / Description
            |--------------------------------------------------------------------------
            */

            $table->text('description')
                ->nullable();

            $table->string('legal_reference', 500)
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
            | Query Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                'start_type',
                'penalty_rules_start_type_index'
            );

            $table->index(
                [
                    'start_type',
                    'effective_from',
                    'effective_to',
                ],
                'penalty_rules_effective_index'
            );

            $table->index(
                [
                    'start_type',
                    'is_active',
                ],
                'penalty_rules_active_index'
            );

            $table->index(
                [
                    'is_active',
                    'start_type',
                    'effective_from',
                    'effective_to',
                ],
                'penalty_rules_active_effective_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Increment Period Constraint
        |--------------------------------------------------------------------------
        |
        | Current system contract supports MONTH only.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_increment_period_check
            CHECK (
                increment_period = 'MONTH'
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Start Type Constraint
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH:
        |
        |     Uses revenue_settings.payment_start_month and
        |     revenue_settings.payment_start_day.
        |
        | AGREEMENT_DATE:
        |
        |     Uses the applicable agreement date.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_start_type_check
            CHECK (
                start_type IN (
                    'FIXED_FISCAL_MONTH',
                    'AGREEMENT_DATE'
                )
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis Constraint
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_calculation_basis_check
            CHECK (
                calculation_basis IN (
                    'PRINCIPAL',
                    'OUTSTANDING'
                )
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Rate Range Constraint
        |--------------------------------------------------------------------------
        |
        | Percentage rates must be between 0% and 100%.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_rate_range_check
            CHECK (
                initial_rate BETWEEN 0 AND 100
                AND increment_rate BETWEEN 0 AND 100
                AND maximum_rate BETWEEN 0 AND 100
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Rate Relationship Constraint
        |--------------------------------------------------------------------------
        |
        | The maximum penalty rate cannot be lower than the initial rate.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_rate_relationship_check
            CHECK (
                maximum_rate >= initial_rate
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Effective Period Constraint
        |--------------------------------------------------------------------------
        |
        | effective_to is optional.
        |
        | If provided:
        |
        |     effective_to >= effective_from
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_valid_effective_period
            CHECK (
                effective_to IS NULL
                OR effective_to >= effective_from
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Prevent Overlapping Active Rules Of The Same Start Type
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The start_type is intentionally part of the exclusion constraint.
        |
        | This means:
        |
        |     FIXED_FISCAL_MONTH
        |
        | and:
        |
        |     AGREEMENT_DATE
        |
        | are independent rule families.
        |
        | They may therefore have overlapping effective periods.
        |
        |--------------------------------------------------------------------------
        | Valid:
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH
        | 2025-09-11 → NULL
        |
        | AGREEMENT_DATE
        | 2025-09-11 → NULL
        |
        |--------------------------------------------------------------------------
        | Invalid:
        |--------------------------------------------------------------------------
        |
        | AGREEMENT_DATE
        | 2025-09-11 → NULL
        |
        | AGREEMENT_DATE
        | 2026-09-04 → NULL
        |
        | PostgreSQL daterange uses a half-open interval:
        |
        |     [start, end)
        |
        | Because effective_to is inclusive from the business perspective,
        | one day is added to the upper bound.
        |
        | Example:
        |
        |     2026-01-01 → 2026-12-31
        |
        | becomes:
        |
        |     [2026-01-01, 2027-01-01)
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_no_overlapping_periods
            EXCLUDE USING gist (
                start_type WITH =,
                daterange(
                    effective_from,
                    COALESCE(
                        effective_to + 1,
                        'infinity'::date
                    ),
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
        /*
        |--------------------------------------------------------------------------
        | Drop Penalty Rules
        |--------------------------------------------------------------------------
        |
        | The btree_gist extension is intentionally retained because it may
        | be required by exclusion constraints in other tables.
        |
        */

        Schema::dropIfExists('penalty_rules');
    }
};
