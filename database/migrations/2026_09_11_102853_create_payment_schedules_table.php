<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Assessment Service
            |--------------------------------------------------------------------------
            |
            | The specific service obligation being paid over time.
            |
            */

            $table->foreignUuid('assessment_service_id')
                ->constrained('assessment_services')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Installment Number
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('installment_number');


            /*
            |--------------------------------------------------------------------------
            | Due Date
            |--------------------------------------------------------------------------
            */

            $table->date('due_date')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Amount Due
            |--------------------------------------------------------------------------
            */

            $table->decimal('amount_due', 18, 4);


            /*
            |--------------------------------------------------------------------------
            | Amount Paid
            |--------------------------------------------------------------------------
            */

            $table->decimal('amount_paid', 18, 4)
                ->default(0);


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'PENDING',
                'PARTIALLY_PAID',
                'PAID',
                'OVERDUE',
                'CANCELLED',
            ])
            ->default('PENDING')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Paid At
            |--------------------------------------------------------------------------
            */

            $table->timestampTz('paid_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            */

            $table->text('notes')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | System
            |--------------------------------------------------------------------------
            */

            $table->timestampsTz();


            /*
            |--------------------------------------------------------------------------
            | Constraints
            |--------------------------------------------------------------------------
            */

            $table->unique(
                [
                    'assessment_service_id',
                    'installment_number',
                ],
                'payment_schedules_service_installment_unique'
            );


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'assessment_service_id',
                    'status',
                ],
                'payment_schedules_service_status_index'
            );

            $table->index(
                [
                    'due_date',
                    'status',
                ],
                'payment_schedules_due_date_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};