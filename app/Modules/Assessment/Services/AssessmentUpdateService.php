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
     * - Update assessment header
     * - Replace services when supplied
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
            | Update Header
            |--------------------------------------------------------------------------
            */

            $newStatus = $request->status();

            $assessment->update([
                'status' => $newStatus,

                'notes' =>
                    $request->validated('notes'),

                'submitted_at' =>
                    $newStatus === 'PENDING_APPROVAL'
                        ? ($assessment->submitted_at ?? now())
                        : null,

                'updated_by' =>
                    auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Replace Services
            |--------------------------------------------------------------------------
            */

            if ($request->has('services')) {

                $existingFileUuids =
                    $this->fileService
                        ->collectAssessmentFiles($assessment);

                /*
                |--------------------------------------------------------------------------
                | Remove Existing Services
                |--------------------------------------------------------------------------
                */

                $this->fileService->removeAssessmentServices(
                    $assessment
                );

                /*
                |--------------------------------------------------------------------------
                | Store New Services
                |--------------------------------------------------------------------------
                */

                $this->serviceValueService->storeServices(
                    $assessment,
                    $request->services()
                );

                /*
                |--------------------------------------------------------------------------
                | Determine Obsolete Files
                |--------------------------------------------------------------------------
                */

                $newFileUuids =
                    $this->fileService
                        ->collectAssessmentFiles($assessment);

                $obsoleteFileUuids = array_values(
                    array_diff(
                        $existingFileUuids,
                        $newFileUuids
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CALCULATE BEFORE COMMIT
            |--------------------------------------------------------------------------
            |
            | This is critical.
            |
            | If calculation throws an exception:
            |
            | - assessment update rolls back
            | - service replacement rolls back
            | - service values roll back
            | - status change rolls back
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
        | Physical file deletion must happen only after the database
        | transaction successfully commits.
        |
        */

        if ($obsoleteFileUuids !== []) {
            DB::afterCommit(function () use ($obsoleteFileUuids) {
                $this->fileService->cleanupObsoleteFiles(
                    $obsoleteFileUuids
                );
            });
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