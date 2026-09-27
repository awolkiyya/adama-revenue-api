<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentApprovalService
{
    public function __construct(
        protected AssessmentPostApprovalService $postApprovalService,
    ) {
    }

    /**
     * Approve an assessment.
     *
     * Approval is responsible only for the assessment decision.
     *
     * Post-approval financial processing is delegated to
     * AssessmentPostApprovalService.
     *
     * The post-approval service determines, per assessment service,
     * whether the service:
     *
     *     1. Requires a payment schedule
     *     2. Belongs to the immediate invoice
     */
    public function approve(
        string $assessmentId
    ): Assessment {
        Log::info(
            'Assessment approval workflow started.',
            [
                'assessment_id' => $assessmentId,
            ]
        );

        try {
            return DB::transaction(
                function () use ($assessmentId): Assessment {

                    /*
                    |--------------------------------------------------------------------------
                    | 1. LOAD + LOCK ASSESSMENT
                    |--------------------------------------------------------------------------
                    */

                    $assessment = Assessment::query()
                        ->with([
                            'services',
                            'citizen',
                        ])
                        ->lockForUpdate()
                        ->findOrFail($assessmentId);

                    $logContext = [
                        'assessment_id' => $assessment->id,
                        'assessment_status' => $assessment->status,
                        'service_count' => $assessment->services->count(),
                    ];

                    Log::info(
                        'Assessment loaded and locked for approval.',
                        $logContext
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 2. VALIDATE STATUS
                    |--------------------------------------------------------------------------
                    */

                    if (! $assessment->isPendingApproval()) {
                        throw ValidationException::withMessages([
                            'status' => [
                                'Only assessments pending approval can be approved.',
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 3. AUTHENTICATED USER
                    |--------------------------------------------------------------------------
                    */

                    $userId = Auth::id();

                    if (! $userId) {
                        throw ValidationException::withMessages([
                            'authorization' => [
                                'An authenticated user is required to approve an assessment.',
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 4. RECORD APPROVAL
                    |--------------------------------------------------------------------------
                    */

                    $now = now();

                    $assessment->update([
                        'status' => 'APPROVED',
                        'decision' => 'APPROVED',
                        'decision_notes' => null,
                        'decided_by' => $userId,
                        'decided_at' => $now,
                        'approved_at' => $now,
                        'rejected_at' => null,
                    ]);

                    Log::info(
                        'Assessment approved successfully.',
                        [
                            ...$logContext,
                            'approved_by' => $userId,
                            'approved_at' => $now->toISOString(),
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 5. POST-APPROVAL FINANCIAL WORKFLOW
                    |--------------------------------------------------------------------------
                    |
                    | This service decides what happens to each assessment
                    | service.
                    |
                    | Example:
                    |
                    | Service A
                    |     → schedule
                    |
                    | Service B
                    |     → invoice item
                    |
                    | Service C
                    |     → invoice item
                    |
                    | The post-approval service then creates:
                    |
                    |     Payment schedules for A
                    |
                    |     ONE invoice containing:
                    |         - B
                    |         - C
                    |
                    */

                    $this->postApprovalService->process(
                        $assessment
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 6. REFRESH
                    |--------------------------------------------------------------------------
                    */

                    $freshAssessment = $assessment->fresh([
                        'services',
                        'citizen',
                    ]);

                    Log::info(
                        'Assessment approval workflow completed successfully.',
                        [
                            'assessment_id' => $freshAssessment?->id,
                            'final_status' => $freshAssessment?->status,
                        ]
                    );

                    return $freshAssessment;
                }
            );
        } catch (Throwable $exception) {

            Log::error(
                'Assessment approval workflow failed.',
                [
                    'assessment_id' => $assessmentId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ]
            );

            throw $exception;
        }
    }
}