<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Assessment\Requests\UpdateAssessmentRequest;
use App\Services\AssessmentCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentUpdateService
{
    public function __construct(
        protected AssessmentServiceValueService $serviceValueService,
        protected AssessmentFileService $fileService,
        protected AssessmentCalculationService $calculationService,
    ) {
    }

    /**
     * Update an existing assessment.
     *
     * Responsibilities:
     * - Validate the assessment lifecycle state.
     * - Update only supplied header fields.
     * - Replace services when services are supplied.
     * - Preserve existing FILE and MULTI_FILE values.
     * - Calculate the assessment when submitted.
     * - Clean up obsolete physical files after commit.
     */
    public function update(
        Assessment $assessment,
        UpdateAssessmentRequest $request
    ): Assessment {
        return DB::transaction(function () use (
            $assessment,
            $request
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
            | Update Assessment Header
            |--------------------------------------------------------------------------
            */

            $headerData = [
                'updated_by' => auth()->id(),
            ];

            if ($request->has('taxpayerId')) {
                $headerData['citizen_id'] = $request->taxpayerId();
            }

            // Omitted = preserve, null = clear, value = update.
            if ($request->has('notes')) {
                $headerData['notes'] = $request->validated('notes');
            }

            if ($request->has('status')) {
                $newStatus = $request->status();

                $headerData['status'] = $newStatus;

                $headerData['submitted_at'] =
                    $newStatus === 'PENDING_APPROVAL'
                        ? ($assessment->submitted_at ?? now())
                        : null;
            }

            $assessment->update($headerData);

            /*
            |--------------------------------------------------------------------------
            | Replace Services
            |--------------------------------------------------------------------------
            */

            if ($request->has('services')) {

                /*
                |--------------------------------------------------------------------------
                | Snapshot Existing Files
                |--------------------------------------------------------------------------
                |
                | Both collections must be captured before removing
                | the existing assessment service records.
                |
                */

                $existingFileUuids =
                    $this->fileService->collectAssessmentFiles(
                        $assessment
                    );

                $existingFileValues =
                    $this->fileService->collectAssessmentFileValues(
                        $assessment
                    );

                /*
                |--------------------------------------------------------------------------
                | Remove Existing Service Records
                |--------------------------------------------------------------------------
                |
                | Physical files are not deleted here.
                |
                */

                $this->fileService->removeAssessmentServices(
                    $assessment
                );

                /*
                |--------------------------------------------------------------------------
                | Store Replacement Services
                |--------------------------------------------------------------------------
                |
                | Existing file values are passed to the value service.
                | It must restore previous file references when no
                | replacement upload is submitted.
                |
                */

                $this->serviceValueService->storeServices(
                    $assessment,
                    $request->services(),
                    $existingFileValues
                );

                /*
                |--------------------------------------------------------------------------
                | Collect Replacement File IDs
                |--------------------------------------------------------------------------
                */

                $newFileUuids =
                    $this->fileService->collectAssessmentFiles(
                        $assessment
                    );

                /*
                |--------------------------------------------------------------------------
                | Identify Obsolete Files
                |--------------------------------------------------------------------------
                */

                $obsoleteFileUuids = array_values(
                    array_diff(
                        $existingFileUuids,
                        $newFileUuids
                    )
                );

                /*
                |--------------------------------------------------------------------------
                | Schedule Physical File Cleanup
                |--------------------------------------------------------------------------
                |
                | The callback executes only after a successful commit.
                | A rollback will not trigger the cleanup.
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
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate When Submitted
            |--------------------------------------------------------------------------
            */

            if ($assessment->status === 'PENDING_APPROVAL') {
                $this->calculationService->calculate($assessment);
            }

            return $assessment;
        });
    }

    /**
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
