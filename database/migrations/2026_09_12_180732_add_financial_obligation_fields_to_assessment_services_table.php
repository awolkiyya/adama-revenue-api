<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_services', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Payment Due Date
            |--------------------------------------------------------------------------
            |
            | Final resolved payment deadline for this service.
            |
            | The due date is calculated according to the applicable
            | business/penalty rule.
            |
            | LIZZ:
            |
            |     AGREEMENT_DATE field value
            |          ↓
            |     Penalty Rule
            |          ↓
            |     due_date
            |
            | Default services:
            |
            |     FIXED_FISCAL_MONTH
            |          ↓
            |     Fiscal configuration
            |          ↓
            |     due_date
            |
            | The resolved date is stored here so historical
            | assessments are not affected by future rule changes.
            |
            */

            $table->date('due_date')
                ->nullable()
                ->after('computed_amount');


            /*
            |--------------------------------------------------------------------------
            | Applicable Penalty Rule
            |--------------------------------------------------------------------------
            |
            | Stores the penalty rule that was applied to this
            | assessment service.
            |
            */

            $table->foreignUuid('penalty_rule_id')
                ->nullable()
                ->after('due_date')
                ->constrained('penalty_rules')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Applicable Interest Rule
            |--------------------------------------------------------------------------
            |
            | Stores the interest rule that was applied to this
            | assessment service.
            |
            */

            $table->foreignUuid('interest_rule_id')
                ->nullable()
                ->after('penalty_rule_id')
                ->constrained('interest_rules')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Financial Index
            |--------------------------------------------------------------------------
            |
            | Supports queries for services based on their due date
            | and the applicable penalty rule.
            |
            */

            $table->index('due_date');

            $table->index([
                'penalty_rule_id',
                'interest_rule_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('assessment_services', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */

            $table->dropForeign([
                'penalty_rule_id',
            ]);

            $table->dropForeign([
                'interest_rule_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->dropIndex([
                'due_date',
            ]);

            $table->dropIndex([
                'penalty_rule_id',
                'interest_rule_id',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Columns
            |--------------------------------------------------------------------------
            */

            $table->dropColumn([
                'due_date',
                'penalty_rule_id',
                'interest_rule_id',
            ]);
        });
    }
};
