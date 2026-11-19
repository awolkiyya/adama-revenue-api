<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\DirectCollection\Services\DirectCollectionDashboardService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectCollectionDashboardController extends Controller
{
    public function __construct(
        private readonly DirectCollectionDashboardService $dashboardService
    ) {
    }

    /**
     * Get the authenticated cash collector's direct collection dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dashboard = $this->dashboardService->getDashboard($user);

        return ApiResponse::success(
            data: $dashboard,
            message: 'Direct collection dashboard retrieved successfully.'
        );
    }
}