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
        | Required by exclusion constraints used to prevent overlapping
        | active penalty-rule periods.
        |
        */

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        /*
        |--------------------------------------------------------------------------
        | Penalty Rules
        |--------------------------------------------------------------------------
        |
        | A penalty rule defines the percentage-based late-payment penalty
        | policy applied to overdue revenue assessments.
        |
        | Scope:
        |
        | revenue_service_id = NULL
        |     Default policy for all revenue services.
        |
        | revenue_service_id = UUID
        |     Service-specific override.
        |
        | Resolution:
        |
        |     1. Active service-specific rule
        | |   2. Active default rule
        | |   3. No rule = no penalty
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
            | Revenue Service Scope
            |--------------------------------------------------------------------------
            |
            | NULL:
            |     Default / All Services
            |
            | UUID:
            |     Service-specific override.
            |
            */

            $table->foreignUuid('revenue_service_id')
                ->nullable()
                ->constrained('revenue_services')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Rule Identification
            |--------------------------------------------------------------------------
            */

            $table->string('name', 150);

            /*
            |--------------------------------------------------------------------------
            | Penalty Rates
            |--------------------------------------------------------------------------
            |
            | Rates are stored as percentage points.
            |
            | Example:
            |
            | initial_rate   = 5.0000
            | increment_rate = 2.0000
            | maximum_rate   = 25.0000
            |
            | Result:
            |
            | 5%, 7%, 9%, 11%, ... 25%
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
            | Penalty Increment Period
            |--------------------------------------------------------------------------
            |
            | Current legal/business rule:
            |
            |     +2% for each late-payment month.
            |
            | MONTH is therefore the normal configuration.
            |
            | Keeping this configurable allows future policy changes without
            | changing the schema.
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
            |     Penalty starts from a configured Ethiopian fiscal/calendar
            |     month, currently month 7.
            |
            | AGREEMENT_DATE
            |     Penalty commencement is determined from the agreement date.
            |
            */

            $table->string('start_type', 30)
                ->default('FIXED_FISCAL_MONTH');

            /*
            |--------------------------------------------------------------------------
            | Fixed Fiscal Month
            |--------------------------------------------------------------------------
            |
            | Used only when:
            |
            |     start_type = FIXED_FISCAL_MONTH
            |
            | Example:
            |
            |     7 = penalty starts from Ethiopian fiscal/calendar month 7.
            |
            | NULL when:
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
            | PRINCIPAL:
            |     Calculate the percentage against the original assessed amount.
            |
            | OUTSTANDING:
            |     Calculate the percentage against the remaining unpaid amount.
            |
            */

            $table->string('calculation_basis', 30)
                ->default('PRINCIPAL');

            /*
            |--------------------------------------------------------------------------
            | Effective Period
            |--------------------------------------------------------------------------
            |
            | effective_from = first date the policy is applicable.
            |
            | effective_to = last date the policy is applicable.
            |
            | NULL = no defined end date.
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
            | Legal / Reference Information
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
                'revenue_service_id',
                'penalty_rules_service_index'
            );

            $table->index(
                [
                    'revenue_service_id',
                    'effective_from',
                    'effective_to',
                ],
                'penalty_rules_service_effective_index'
            );

            $table->index(
                [
                    'revenue_service_id',
                    'is_active',
                ],
                'penalty_rules_service_active_index'
            );

            $table->index(
                [
                    'is_active',
                    'effective_from',
                    'effective_to',
                ],
                'penalty_rules_active_effective_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Increment Period Validation
        |--------------------------------------------------------------------------
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_increment_period_check
            CHECK (
                increment_period IN (
                    'DAY',
                    'MONTH',
                    'YEAR'
                )
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Start Type Validation
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
        | Calculation Basis Validation
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
        | Rate Validation
        |--------------------------------------------------------------------------
        |
        | All rates must be non-negative.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_non_negative_rates_check
            CHECK (
                initial_rate >= 0
                AND increment_rate >= 0
                AND maximum_rate >= 0
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Maximum Rate Validation
        |--------------------------------------------------------------------------
        |
        | Maximum penalty cannot be lower than the initial penalty rate.
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
        | Fiscal Month Validation
        |--------------------------------------------------------------------------
        |
        | FIXED_FISCAL_MONTH:
        |     start_fiscal_month is required and must be 1-13.
        |
        | AGREEMENT_DATE:
        |     start_fiscal_month must be NULL.
        |
        | Ethiopia's traditional calendar has 13 months, so the database
        | allows 1 through 13.
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
        | Effective Period Validation
        |--------------------------------------------------------------------------
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
        | Prevent Overlapping Global Policies
        |--------------------------------------------------------------------------
        |
        | Only one ACTIVE default policy may apply to a particular date.
        |
        | effective_to is inclusive in business terms, therefore +1 day is
        | used when constructing PostgreSQL's half-open daterange.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_no_overlapping_default_periods
            EXCLUDE USING gist (
                daterange(
                    effective_from,
                    COALESCE(effective_to + 1, 'infinity'::date),
                    '[)'
                ) WITH &&
            )
            WHERE (
                is_active = true
                AND revenue_service_id IS NULL
            )
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Prevent Overlapping Service-Specific Policies
        |--------------------------------------------------------------------------
        |
        | A revenue service cannot have two ACTIVE penalty rules with
        | overlapping effective periods.
        |
        */

        DB::statement(<<<'SQL'
            ALTER TABLE penalty_rules
            ADD CONSTRAINT penalty_rules_no_overlapping_service_periods
            EXCLUDE USING gist (
                revenue_service_id WITH =,
                daterange(
                    effective_from,
                    COALESCE(effective_to + 1, 'infinity'::date),
                    '[)'
                ) WITH &&
            )
            WHERE (
                is_active = true
                AND revenue_service_id IS NOT NULL
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
        | btree_gist is intentionally retained because another table or
        | constraint may depend on the extension.
        |
        */

        Schema::dropIfExists('penalty_rules');
    }
};