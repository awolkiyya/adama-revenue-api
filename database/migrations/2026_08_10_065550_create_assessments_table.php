<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Assessment Reference
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | ASM-2026-000001
            |
            */

            $table->string('assessment_number', 100)
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Taxpayer / Citizen
            |--------------------------------------------------------------------------
            |
            | The taxpayer/citizen for whom this assessment is created.
            |
            */

            $table->foreignUuid('citizen_id')
                ->constrained('citizens')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            |
            | The office / administrative unit responsible for
            | processing this assessment.
            |
            */

            $table->foreignUuid('administrative_unit_id')
                ->nullable()
                ->constrained('administrative_units')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Assessment Date
            |--------------------------------------------------------------------------
            |
            | Business date on which the assessment was created.
            |
            */

            $table->date('assessment_date')
                ->default(now()->toDateString())
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Assessment Status
            |--------------------------------------------------------------------------
            |
            | DRAFT
            |   Assessment is being prepared.
            |
            | PENDING_APPROVAL
            |   Submitted and waiting for decision.
            |
            | APPROVED
            |   Assessment has been approved.
            |
            | REJECTED
            |   Assessment has been rejected.
            |
            | CANCELLED
            |   Assessment has been cancelled.
            |
            */

            $table->enum('status', [
                'DRAFT',
                'PENDING_APPROVAL',
                'APPROVED',
                'REJECTED',
                'CANCELLED',
            ])
            ->default('DRAFT')
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Assessment Notes
            |--------------------------------------------------------------------------
            */

            $table->text('notes')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Submission
            |--------------------------------------------------------------------------
            */

            $table->timestamp('submitted_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Decision
            |--------------------------------------------------------------------------
            |
            | The authoritative decision made for the assessment.
            |
            */

            $table->enum('decision', [
                'APPROVED',
                'REJECTED',
            ])
            ->nullable()
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Decision Officer
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Decision Notes
            |--------------------------------------------------------------------------
            |
            | Especially important when the assessment is rejected.
            |
            */

            $table->text('decision_notes')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Decision Metadata
            |--------------------------------------------------------------------------
            |
            | Optional structured information produced by the
            | decision/calculation process.
            |
            | Example:
            |
            | {
            |     "engine": "tariff_decision_provider",
            |     "version": "1.0",
            |     "processed_services": 3
            | }
            |
            */

            $table->json('decision_metadata')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Decision Date
            |--------------------------------------------------------------------------
            */

            $table->timestamp('decided_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Approval / Rejection Dates
            |--------------------------------------------------------------------------
            */

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('rejected_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Audit Users
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
            | System
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'citizen_id',
                'status',
            ]);

            $table->index([
                'administrative_unit_id',
                'status',
            ]);

            $table->index([
                'decided_by',
                'decision',
            ]);

            $table->index([
                'created_by',
                'created_at',
            ]);

            $table->index([
                'submitted_at',
                'status',
            ]);

            $table->index([
                'assessment_date',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};