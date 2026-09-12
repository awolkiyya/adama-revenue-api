<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Modules\Assessment\Requests\StoreAssessmentRequest;
use App\Modules\Assessment\Requests\UpdateAssessmentRequest;
use App\Modules\Assessment\Requests\ReturnAssessmentRequest;
use App\Modules\Assessment\Requests\CancelAssessmentRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AssessmentService
{
    public function __construct(
        protected AssessmentQueryService $queryService,
        protected AssessmentCreationService $creationService,
        protected AssessmentUpdateService $updateService,
        protected AssessmentWorkflowService $workflowService,
    ) {
    }

    /**
     * ============================================================
     * QUERY
     * ============================================================
     */

    /**
     * Paginate assessments.
     */
    public function paginate(): LengthAwarePaginator
    {
        return $this->queryService->paginate();
    }

    /**
     * Get assessment summary.
     */
    public function summary(): array
    {
        return $this->queryService->summary();
    }

    /**
     * Find an assessment or fail.
     */
    public function findOrFail(string $id): Assessment
    {
        return $this->queryService->findOrFail($id);
    }

    /**
     * ============================================================
     * CREATE
     * ============================================================
     */

    /**
     * Create a new assessment.
     */
    public function create(StoreAssessmentRequest $request): Assessment
    {
        return $this->creationService->create($request);
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */

    /**
     * Update an existing assessment.
     */
    public function update(
        Assessment $assessment,
        UpdateAssessmentRequest $request
    ): Assessment {
        return $this->updateService->update(
            $assessment,
            $request
        );
    }

    /**
     * ============================================================
     * WORKFLOW
     * ============================================================
     */

    /**
     * Return an assessment for correction.
     */
    public function returnAssessment(
        Assessment $assessment,
        ReturnAssessmentRequest $request
    ): Assessment {
        return $this->workflowService->returnAssessment(
            $assessment,
            $request
        );
    }

    /**
     * Cancel an assessment.
     */
    public function cancel(
        Assessment $assessment,
        CancelAssessmentRequest $request
    ): Assessment {
        return $this->workflowService->cancel(
            $assessment,
            $request
        );
    }


    /**
     * Submit an assessment for approval.
     *
     * Business transition:
     *
     * DRAFT
     *     ↓
     * PENDING_APPROVAL
     *
     * RETURNED
     *     ↓
     * PENDING_APPROVAL
     *
     * Financial calculation is performed by
     * AssessmentWorkflowService inside the transaction.
     */
    public function submit(
        Assessment $assessment
    ): Assessment {
        return $this->workflowService->submit(
            $assessment
        );
    }
}