<?php

namespace App\Modules\RevenueReport\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RevenueReport\Requests\RevenueCollectionReportRequest;
use App\Modules\RevenueReport\Services\RevenueCollectionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class RevenueCollectionReportController extends Controller
{
    public function __construct(
        protected RevenueCollectionReportService $service
    ) {}

    /**
     * Generate the Revenue Collection Report.
     *
     * GET /api/v1/revenue-reports/collection
     *
     * Authorization is enforced by the route middleware.
     *
     * Supported filters:
     * - search
     * - start_date
     * - end_date
     * - payment_method
     * - payment_provider
     * - status
     * - payment_source
     * - revenue_domain
     * - revenue_category_id
     * - service_id
     * - administrative_unit_id
     * - sort_by
     * - sort_direction
     * - page
     * - per_page
     */
    public function collection(
        RevenueCollectionReportRequest $request
    ): JsonResponse {
        $filters = $request->validated();

        try {
            $report = $this->service->generate($filters);

            return response()->json([
                'message' => 'Revenue collection report generated successfully.',
                'data' => $report,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to generate revenue collection report.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return response()->json([
                'message' => 'Unable to generate the revenue collection report. Please try again later.',
            ], 500);
        }
    }
}