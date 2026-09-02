<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Revenue Service
            |--------------------------------------------------------------------------
            |
            | NULL     = default penalty rule for all revenue services.
            |
            | NOT NULL = service-specific penalty rule / override.
            |
            | Examples:
            |
            | NULL       → Standard/default penalty policy
            | LIZZ_ID    → Lizz-specific penalty policy
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

            $table->string('name');

            /*
            |--------------------------------------------------------------------------
            | Calculation Type
            |--------------------------------------------------------------------------
            |
            | FIXED
            | PERCENTAGE
            | PROGRESSIVE
            |
            */

            $table->string('calculation_type', 30);

            /*
            |--------------------------------------------------------------------------
            | Initial Penalty Rate
            |--------------------------------------------------------------------------
            |
            | Stored as percentage.
            |
            | Example:
            |
            | 5.0000 = 5%
            |
            */

            $table->decimal('initial_rate', 10, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Increment Rate
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | 2.0000 = +2%
            |
            */

            $table->decimal('increment_rate', 10, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Maximum Penalty Rate
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | 25.0000 = maximum 25%
            |
            */

            $table->decimal('maximum_rate', 10, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Penalty Start Type
            |--------------------------------------------------------------------------
            |
            | DUE_DATE
            | AGREEMENT_START
            | AFTER_GRACE_PERIOD
            | FISCAL_YEAR_START
            |
            */

            $table->string('start_type', 30)
                ->default('AFTER_GRACE_PERIOD');

            /*
            |--------------------------------------------------------------------------
            | Grace / Start Offset
            |--------------------------------------------------------------------------
            |
            | Examples:
            |
            | 7 MONTH
            | 30 DAY
            | 1 YEAR
            |
            */

            $table->unsignedInteger('grace_period_value')
                ->default(0);

            $table->string('grace_period_unit', 20)
                ->default('MONTH');

            /*
            |--------------------------------------------------------------------------
            | Increment Period
            |--------------------------------------------------------------------------
            |
            | DAY
            | MONTH
            | YEAR
            |
            */

            $table->string('increment_period', 20)
                ->default('MONTH');

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
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
            | effective_from = first date the rule applies.
            |
            | effective_to = last date the rule applies.
            |
            | NULL effective_to means the rule has no defined end date.
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
            | Effective dates determine the legal/business applicability.
            |
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

            $table->string('legal_reference')
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
            | Indexes
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

            $table->index('is_active');

            $table->index('effective_from');

            $table->index('effective_to');
        });

        /*
        |--------------------------------------------------------------------------
        | Valid Effective Period
        |--------------------------------------------------------------------------
        |
        | effective_to cannot be earlier than effective_from.
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
        | PostgreSQL GiST Support
        |--------------------------------------------------------------------------
        |
        | Required for combining equality comparison with date-range
        | exclusion constraints.
        |
        */

        DB::statement(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Prevent Overlapping Default Policies
        |--------------------------------------------------------------------------
        |
        | revenue_service_id IS NULL means the rule applies to all services.
        |
        | Only one active default policy can apply to a given date.
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
        | A service can have multiple historical penalty policies, but their
        | active effective periods cannot overlap.
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
        Schema::dropIfExists('penalty_rules');
    }
};
