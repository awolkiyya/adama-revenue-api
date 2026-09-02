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
            | Stored as percentage.
            |
            | Example:
            |
            | 24.7250 = 24.725%
            |
            | The calculation engine converts this to decimal form:
            |
            | 24.725 / 100 = 0.24725
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
            | Defines the amount against which interest is calculated.
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
            | rule applies.
            |
            | effective_to = NULL means the rule has no defined end date.
            |
            | Example:
            |
            | effective_from: 2026-07-08
            | effective_to:   NULL
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
            | council decision, or other legal instrument defining the
            | applicable interest rate.
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
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'effective_from',
                    'effective_to',
                ],
                'interest_rules_effective_index'
            );

            $table->index('is_active');

            $table->index('effective_from');

            $table->index('effective_to');
        });

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
        | Prevents overlapping active interest rules.
        |
        | Example:
        |
        | 2026-07-08 → 2027-07-07
        | 2027-07-08 → 2028-07-07
        |
        | Valid.
        |
        | But:
        |
        | 2026-07-08 → 2027-07-07
        | 2027-01-01 → 2027-12-31
        |
        | Invalid because the periods overlap.
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
