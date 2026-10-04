<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_amendments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();


            /*
            |--------------------------------------------------------------------------
            | Lease Agreement
            |--------------------------------------------------------------------------
            |
            | The original lease agreement being amended.
            |
            */

            // $table->foreignUuid('lease_agreement_id')
            //     ->constrained('lease_agreements')
            //     ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Amendment Number
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | AMND-2026-000001
            |
            */

            $table->string('amendment_number', 100)
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Amendment Type
            |--------------------------------------------------------------------------
            |
            | NAME_TRANSFER
            | LAND_AREA_CHANGE
            | LAND_PARTIAL_TRANSFER
            | LAND_MERGE
            | OTHER
            |
            */

            $table->enum('amendment_type', [
                'NAME_TRANSFER',
                'LAND_AREA_CHANGE',
                'LAND_PARTIAL_TRANSFER',
                'LAND_MERGE',
                'OTHER',
            ])
            ->index();


            /*
            |--------------------------------------------------------------------------
            | Effective Date
            |--------------------------------------------------------------------------
            |
            | The date from which the amendment legally/business-wise
            | becomes effective.
            |
            */

            $table->date('effective_date')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | DRAFT
            | PENDING_APPROVAL
            | APPROVED
            | REJECTED
            | CANCELLED
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
            | Previous / New Taxpayer
            |--------------------------------------------------------------------------
            |
            | Mainly useful for NAME_TRANSFER.
            |
            | Example:
            |
            | Old taxpayer -> Abebe
            | New taxpayer -> Kebede
            |
            */

            $table->foreignUuid('previous_citizen_id')
                ->nullable()
                ->constrained('citizens')
                ->restrictOnDelete();

            $table->foreignUuid('new_citizen_id')
                ->nullable()
                ->constrained('citizens')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Previous / New Land Area
            |--------------------------------------------------------------------------
            |
            | Mainly useful for area changes.
            |
            | Example:
            |
            | Previous: 200 M2
            | New:      500 M2
            |
            */

            $table->decimal('previous_land_area', 18, 4)
                ->nullable();

            $table->decimal('new_land_area', 18, 4)
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Measurement Unit
            |--------------------------------------------------------------------------
            |
            | Normally M2 for land area.
            |
            */

            $table->foreignUuid('measurement_unit_id')
                ->nullable()
                ->constrained('measurement_units')
                ->nullOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Reason
            |--------------------------------------------------------------------------
            |
            | Explanation for the amendment.
            |
            */

            $table->text('reason')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Supporting Document
            |--------------------------------------------------------------------------
            |
            | Stores the reference/path to the legal document
            | supporting the amendment.
            |
            */

            $table->string('document_path', 500)
                ->nullable();

            $table->string('document_number', 200)
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
            | Approval / Decision
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('decision_notes')
                ->nullable();

            $table->timestamp('decided_at')
                ->nullable();

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('rejected_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Assessment Replacement
            |--------------------------------------------------------------------------
            |
            | If this amendment causes an existing assessment to be
            | replaced, store the relationship here.
            |
            | Example:
            |
            | Old assessment:
            | ASM-2026-000001
            |
            | New assessment:
            | ASM-2026-000025
            |
            */

            $table->foreignUuid('previous_assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->restrictOnDelete();

            $table->foreignUuid('new_assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->restrictOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Amendment Metadata
            |--------------------------------------------------------------------------
            |
            | Allows future amendment types to store additional
            | structured information without changing the schema
            | immediately.
            |
            | Example:
            |
            | {
            |     "old_owner_name": "Abebe",
            |     "new_owner_name": "Kebede",
            |     "old_area": 800,
            |     "new_area": 200
            | }
            |
            */

            $table->json('metadata')
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

            // $table->index([
            //     'lease_agreement_id',
            //     'status',
            // ]);

            // $table->index([
            //     'lease_agreement_id',
            //     'effective_date',
            // ]);

            $table->index([
                'amendment_type',
                'status',
            ]);

            $table->index([
                'previous_citizen_id',
                'new_citizen_id',
            ]);

            $table->index([
                'created_by',
                'created_at',
            ]);

            $table->index([
                'decided_by',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_amendments');
    }
};