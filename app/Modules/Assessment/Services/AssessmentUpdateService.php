<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Assessment\Requests\UpdateAssessmentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentUpdateService
{
    public function __construct(
        protected AssessmentServiceValueService $serviceValueService,
        protected AssessmentFileService $fileService,
        protected \App\Services\AssessmentCalculationService $calculationService,
    ) {
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     *
     * Responsibilities:
     *
     * - Validate editable lifecycle state
     * - Update only supplied assessment header fields
     * - Replace services when supplied
     * - Preserve existing values when fields are omitted
     * - Calculate when submitted
     * - Commit only after everything succeeds
     */
    public function update(
        Assessment $assessment,
        UpdateAssessmentRequest $request
    ): Assessment {
        $obsoleteFileUuids = [];

        $assessment = DB::transaction(function () use (
            $assessment,
            $request,
            &$obsoleteFileUuids
        ): Assessment {

            /*
            |--------------------------------------------------------------------------
            | Lock Assessment
            |--------------------------------------------------------------------------
            |
            | Prevent concurrent updates to the same assessment.
            |
            */

            $assessment = Assessment::query()
                ->lockForUpdate()
                ->findOrFail($assessment->id);

            /*
            |--------------------------------------------------------------------------
            | Validate Editable Status
            |--------------------------------------------------------------------------
            */

            $this->assertEditable($assessment);

            /*
            |--------------------------------------------------------------------------
            | Update Assessment Header
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | This is a partial update.
            |
            | If taxpayerId, notes, or status is not supplied,
            | the existing database value must remain unchanged.
            |
            */

            $headerData = [
                'updated_by' => auth()->id(),
            ];

            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            */

            if ($request->has('taxpayerId')) {
                $headerData['citizen_id'] =
                    $request->taxpayerId();
            }

            /*
            |--------------------------------------------------------------------------
            | Notes
            |--------------------------------------------------------------------------
            |
            | `has()` allows:
            |
            | - notes omitted → preserve existing notes
            | - notes = null   → clear notes
            | - notes = "..."  → update notes
            |
            */

            if ($request->has('notes')) {
                $headerData['notes'] =
                    $request->validated('notes');
            }

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            if ($request->has('status')) {

                $newStatus = $request->status();

                $headerData['status'] = $newStatus;

                /*
                |--------------------------------------------------------------------------
                | Submitted At
                |--------------------------------------------------------------------------
                |
                | When moving to PENDING_APPROVAL:
                |
                | - preserve an existing submitted_at
                | - otherwise create it now
                |
                | When moving back to DRAFT:
                |
                | - clear submitted_at
                |
                */

                $headerData['submitted_at'] =
                    $newStatus === 'PENDING_APPROVAL'
                        ? ($assessment->submitted_at ?? now())
                        : null;
            }

            /*
            |--------------------------------------------------------------------------
            | Persist Header Changes
            |--------------------------------------------------------------------------
            */

            $assessment->update($headerData);

            /*
            |--------------------------------------------------------------------------
            | Replace Services
            |--------------------------------------------------------------------------
            |
            | Services are replaced only when the request explicitly
            | contains the `services` property.
            |
            | Dynamic fields inside the services payload are keyed by:
            |
            |     RevenueField.id
            |
            | NOT:
            |
            |     RevenueField.key
            |
            */

            if ($request->has('services')) {

                /*
                |--------------------------------------------------------------------------
                | Collect Existing Files
                |--------------------------------------------------------------------------
                |
                | We need these before deleting the existing services
                | so that physical files can be cleaned up after commit.
                |
                */

                $existingFileUuids =
                    $this->fileService
                        ->collectAssessmentFiles($assessment);

                /*
                |--------------------------------------------------------------------------
                | Remove Existing Services
                |--------------------------------------------------------------------------
                |
                | Database records are removed inside the transaction.
                |
                | Physical files are NOT permanently deleted here.
                |
                */

                $this->fileService->removeAssessmentServices(
                    $assessment
                );

                /*
                |--------------------------------------------------------------------------
                | Store New Services
                |--------------------------------------------------------------------------
                |
                | Expected structure:
                |
                | services[]
                |     serviceId
                |     serviceCode
                |     fields[
                |         field.id => value
                |     ]
                |
                */

                $this->serviceValueService->storeServices(
                    $assessment,
                    $request->services()
                );

                /*
                |--------------------------------------------------------------------------
                | Collect New Files
                |--------------------------------------------------------------------------
                */

                $newFileUuids =
                    $this->fileService
                        ->collectAssessmentFiles($assessment);

                /*
                |--------------------------------------------------------------------------
                | Determine Obsolete Files
                |--------------------------------------------------------------------------
                */

                $obsoleteFileUuids = array_values(
                    array_diff(
                        $existingFileUuids,
                        $newFileUuids
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate When Submitted
            |--------------------------------------------------------------------------
            |
            | Calculation happens inside the database transaction.
            |
            | If calculation fails:
            |
            | - assessment header rolls back
            | - service replacement rolls back
            | - service values roll back
            | - calculation changes roll back
            |
            */

            if ($assessment->status === 'PENDING_APPROVAL') {

                $this->calculationService->calculate(
                    $assessment
                );
            }

            return $assessment;
        });

        /*
        |--------------------------------------------------------------------------
        | CLEANUP OBSOLETE FILES AFTER COMMIT
        |--------------------------------------------------------------------------
        |
        | Physical file deletion happens only after the database
        | transaction has successfully committed.
        |
        */

        if ($obsoleteFileUuids !== []) {

            DB::afterCommit(
                function () use ($obsoleteFileUuids): void {

                    $this->fileService->cleanupObsoleteFiles(
                        $obsoleteFileUuids
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN FRESH ASSESSMENT
        |--------------------------------------------------------------------------
        */

        return $assessment->fresh([
            'taxpayer',
            'creator',
            'updater',
            'decisionOfficer',
            'services',
            'services.service',
            'services.values',
            'services.values.files',
        ]);
    }

    /**
     * ============================================================
     * EDITABLE STATE
     * ============================================================
     *
     * Only DRAFT and RETURNED assessments may be edited.
     */
    protected function assertEditable(
        Assessment $assessment
    ): void {
        if (! in_array(
            $assessment->status,
            [
                'DRAFT',
                'RETURNED',
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'status' => [
                    'This assessment cannot be edited in its current status.',
                ],
            ]);
        }
    }
}