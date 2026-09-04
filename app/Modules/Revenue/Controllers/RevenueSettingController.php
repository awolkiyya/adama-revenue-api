<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Requests\RevenueSettingRequest;
use App\Modules\Revenue\Resources\RevenueSettingResource;
use App\Modules\Revenue\Services\RevenueSettingService;
use App\Models\RevenueSetting;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueSettingController extends Controller
{
    /**
     * Revenue Setting Controller.
     *
     * Revenue settings are global singleton-style configuration.
     *
     * Supported operations:
     *
     * - show()
     * - save()
     *
     * There is intentionally no normal:
     *
     * - create()
     * - store()
     * - destroy()
     * - activate()
     * - deactivate()
     */
    public function __construct(
        private readonly RevenueSettingService $revenueSettingService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    /**
     * Get the global revenue configuration.
     *
     * GET /api/revenue-settings
     */
    public function show(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Class-Level Authorization
        |--------------------------------------------------------------------------
        |
        | viewAny() is appropriate because this is a global/singleton
        | configuration resource.
        |
        */

        $this->authorize(
            'viewAny',
            RevenueSetting::class
        );

        $revenueSetting = $this->revenueSettingService->getActive();

        /*
        |--------------------------------------------------------------------------
        | Not Yet Configured
        |--------------------------------------------------------------------------
        */

        if (!$revenueSetting) {
            return ApiResponse::notFound(
                'Revenue settings have not been configured yet.'
            );
        }

        return ApiResponse::success(
            data: new RevenueSettingResource($revenueSetting),
            message: 'Revenue settings retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    /**
     * Create the initial configuration or update the existing configuration.
     *
     * PUT /api/revenue-settings
     */
    public function save(
        RevenueSettingRequest $request
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Class-Level Authorization
        |--------------------------------------------------------------------------
        |
        | This endpoint can create the initial configuration.
        |
        | Therefore an actual RevenueSetting model cannot be required here,
        | because it may not exist yet.
        |
        */

        $this->authorize(
            'update',
            RevenueSetting::class
        );

        $revenueSetting = $this->revenueSettingService->save(
            data: $request->validated(),
            user: $request->user()
        );

        return ApiResponse::success(
            data: new RevenueSettingResource($revenueSetting),
            message: 'Revenue settings saved successfully.'
        );
    }
}