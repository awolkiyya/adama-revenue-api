<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Dashboard\Requests\DashboardSummaryRequest;
use App\Modules\Dashboard\Resources\DashboardSummaryResource;
use App\Modules\Dashboard\Services\DashboardService;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    private const MODULE = 'Dashboard';

    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly SystemLogService $systemLogService,
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
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        $from = isset($validated['from'])
            ? (string) $validated['from']
            : null;

        $to = isset($validated['to'])
            ? (string) $validated['to']
            : null;

        $summary = $this->dashboardService->summarize(
            user: $user,
            from: $from,
            to: $to,
        );

        $this->systemLogService->log(
            action: 'VIEW',
            module: self::MODULE,
            description: 'Accessed dashboard summary.',
            metadata: [
                'from' => $from,
                'to' => $to,
            ],
            actor: $user,
        );

        return ApiResponse::success(
            data: new DashboardSummaryResource($summary),
            message: 'Dashboard summary retrieved successfully.',
        );
    }
}
