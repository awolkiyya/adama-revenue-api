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
        | A penalty rule defines the late-payment penalty policy applied
        | to overdue revenue assessments.
        |
        |--------------------------------------------------------------------------
        | Penalty Commencement Types
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH
        |     Penalty commencement is determined by a configured
        |     Ethiopian fiscal month.
        |
        | AGREEMENT_DATE
        |     Penalty commencement is determined by the agreement date.
        |
        |--------------------------------------------------------------------------
        | Active Rules
        |--------------------------------------------------------------------------
        |
        | Multiple active penalty rules are allowed.
        |
        | However, two ACTIVE rules with the SAME start_type may not have
        | overlapping effective periods.
        |
        | Rules with DIFFERENT start_type values may overlap.
        |
        | Example:
        |
        |     FIXED_FISCAL_MONTH
        |     2025-09-11 → NULL
        |
        |     AGREEMENT_DATE
        |     2026-09-04 → NULL
        |
        | This is VALID because the commencement mechanisms are different.
        |
        | But:
        |
        |     AGREEMENT_DATE
        |     2025-09-11 → NULL
        |
        |     AGREEMENT_DATE
        |     2026-09-04 → NULL
        |
        | is NOT VALID because both rules use the same commencement
        | mechanism and overlap.
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
            |     Month 1 = 5%
            |     Month 2 = 7%
            |     Month 3 = 9%
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
            |     Penalty starts according to a configured Ethiopian
            |     fiscal month.
            |
            | AGREEMENT_DATE
            |     Penalty starts according to the agreement date.
            |
            */

            $table->string('start_type', 30)
                ->default('FIXED_FISCAL_MONTH');

            /*
            |--------------------------------------------------------------------------
            | Fixed Fiscal Month
            |--------------------------------------------------------------------------
            |
            | Required when:
            |
            |     start_type = FIXED_FISCAL_MONTH
            |
            | Valid values:
            |
            |     1 - 13
            |
            | Must be NULL when:
            |
            |     start_type = AGREEMENT_DATE
            |
            */

            $table->unsignedTinyInteger('start_fiscal_month')
                ->nullable();

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
            |     First date on which this rule is applicable.
            |
            | effective_to:
            |     Last date on which this rule is applicable.
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
        | Fiscal Month Constraint
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH:
        |
        |     start_fiscal_month is required
        |     and must be between 1 and 13.
        |
        | AGREEMENT_DATE:
        |
        |     start_fiscal_month must be NULL.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_start_month_check
            CHECK (
                (
                    start_type = 'FIXED_FISCAL_MONTH'
                    AND start_fiscal_month BETWEEN 1 AND 13
                )
                OR
                (
                    start_type = 'AGREEMENT_DATE'
                    AND start_fiscal_month IS NULL
                )
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
        | Prevent Overlapping Rules Of The Same Start Type
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | This constraint intentionally includes start_type.
        |
        | Therefore:
        |
        |     FIXED_FISCAL_MONTH
        |
        | and:
        |
        |     AGREEMENT_DATE
        |
        | are independent rule families and MAY be active at the
        | same time with overlapping effective periods.
        |
        |--------------------------------------------------------------------------
        | Valid:
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH
        | 2025-09-11 → NULL
        |
        | AGREEMENT_DATE
        | 2026-09-04 → NULL
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