<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Modules\Dashboard\Requests\DashboardSummaryRequest;
use App\Modules\Dashboard\Resources\DashboardSummaryResource;
use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
    }

    /**
     * Get the dashboard summary.
     *
     * GET /api/v1/dashboard/summary
     */
    public function summary(
        DashboardSummaryRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $summary = $this->dashboardService->summarize(
            isset($validated['from'])
                ? (string) $validated['from']
                : null,
            isset($validated['to'])
                ? (string) $validated['to']
                : null,
        );

        $resource = new DashboardSummaryResource($summary);

        return ApiResponse::success(
            data: $resource,
            message: 'Dashboard summary retrieved successfully.',
        );
    }
}
