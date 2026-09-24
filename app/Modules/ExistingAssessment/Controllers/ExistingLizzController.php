<?php

namespace App\Modules\ExistingAssessment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Modules\ExistingAssessment\Requests\StoreExistingLizzRequest;
use App\Modules\ExistingAssessment\Requests\UpdateExistingLizzRequest;
use App\Modules\ExistingAssessment\Services\ExistingLizzService;
use Illuminate\Http\JsonResponse;

class ExistingLizzController extends Controller
{
    /**
     * ----------------------------------------------------------------------
     * CONSTRUCTOR
     * ----------------------------------------------------------------------
     */
    public function __construct(
        protected ExistingLizzService $existingLizzService
    ) {
    }

    /**
     * ----------------------------------------------------------------------
     * STORE
     * ----------------------------------------------------------------------
     *
     * POST /existing-lizz
     *
     * Register a historical LIZZ agreement.
     */
    public function store(
        StoreExistingLizzRequest $request
    ): JsonResponse {
        $assessment = $this->existingLizzService->create(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Existing LIZZ agreement registered successfully.',
            'data' => [
                'id' => $assessment->id,
                'assessment_number' => $assessment->assessment_number,
            ],
        ], 201);
    }

    /**
     * ----------------------------------------------------------------------
     * UPDATE
     * ----------------------------------------------------------------------
     *
     * PUT/PATCH /existing-lizz/{assessment}
     *
     * Update a historical LIZZ agreement.
     */
    public function update(
        UpdateExistingLizzRequest $request,
        Assessment $assessment
    ): JsonResponse {
        $assessment = $this->existingLizzService->update(
            $assessment,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Existing LIZZ agreement updated successfully.',
            'data' => [
                'id' => $assessment->id,
                'assessment_number' => $assessment->assessment_number,
            ],
        ]);
    }
}
