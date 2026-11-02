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
    public function __construct(
        protected ExistingLizzService $existingLizzService
    ) {
    }

    /**
     * Register a historical LIZZ agreement.
     *
     * POST /existing-lizz
     */
    public function store(
        StoreExistingLizzRequest $request
    ): JsonResponse {
        /*
         * Retrieve validated scalar and dynamic-field data.
         * Uploaded files are handled separately.
         */
        $validated = $request->validated();

        /*
         * File structure:
         *
         * [
         *     'SERVICE_UUID' => [
         *         'FIELD_UUID' => UploadedFile,
         *     ],
         * ]
         */
        $files = $request->serviceFiles();

        $assessment = $this->existingLizzService->create(
            $validated,
            $files
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
     * Update a historical LIZZ agreement.
     *
     * PUT/PATCH /existing-lizz/{assessment}
     */
    public function update(
        UpdateExistingLizzRequest $request,
        Assessment $assessment
    ): JsonResponse {
        /*
         * Retrieve only validated request data.
         */
        $validated = $request->validated();

        /*
         * An empty array means no new files were uploaded.
         *
         * Existing attachments should remain unchanged unless the
         * application service receives an explicit removal request.
         */
        $files = $request->serviceFiles();

        $assessment = $this->existingLizzService->update(
            $assessment,
            $validated,
            $files
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
