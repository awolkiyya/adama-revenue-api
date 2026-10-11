
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            // Cash reconciliation uses a numeric primary key.
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Revenue Collector and Closing Period
            |--------------------------------------------------------------------------
            */

            // users.id is UUID, so use foreignUuid().
            $table->foreignUuid('collector_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('period_start');
            $table->dateTime('period_end');

            /*
            |--------------------------------------------------------------------------
            | Financial Amounts
            |--------------------------------------------------------------------------
            */

            // Calculated by the backend from eligible completed cash payments.
            $table->decimal('expected_amount', 15, 2)->default(0);

            // Physical cash counted by the revenue collector.
            $table->decimal('actual_amount', 15, 2)->nullable();

            // Calculated by the backend: actual_amount - expected_amount.
            $table->decimal('difference_amount', 15, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Submission and Discrepancy Explanation
            |--------------------------------------------------------------------------
            */

            $table->text('collector_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Finance Review
            |--------------------------------------------------------------------------
            */

            // users.id is UUID, so use foreignUuid() here as well.
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Workflow Status
            |--------------------------------------------------------------------------
            */

            $table->string('status', 30)->default('DRAFT');

            /*
            |--------------------------------------------------------------------------
            | Timestamps and Indexes
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->index(
                ['collector_id', 'period_start', 'period_end'],
                'cash_reconciliation_collector_period_idx'
            );

            $table->index(
                ['status', 'submitted_at'],
                'cash_reconciliation_status_submitted_idx'
            );

            $table->index(
                ['reviewed_by', 'reviewed_at'],
                'cash_reconciliation_reviewer_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};