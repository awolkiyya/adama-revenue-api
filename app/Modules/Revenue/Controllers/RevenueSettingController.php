<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevenueSettingRequest;
use App\Http\Resources\RevenueSettingResource;
use App\Models\RevenueSetting;
use App\Services\ApiResponse;
use App\Services\RevenueSettingService;
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
     * - update()
     *
     * Intentionally no:
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
     * Get the active global revenue configuration.
     *
     * GET /api/revenue-settings
     */
    public function show(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize('viewAny', RevenueSetting::class);


        /*
        |--------------------------------------------------------------------------
        | Retrieve Active Configuration
        |--------------------------------------------------------------------------
        */

        $revenueSetting = $this->revenueSettingService->getActive();


        /*
        |--------------------------------------------------------------------------
        | Configuration Not Found
        |--------------------------------------------------------------------------
        */

        if (!$revenueSetting) {
            return ApiResponse::notFound(
                'Revenue settings have not been configured.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Success Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data: new RevenueSettingResource($revenueSetting),
            message: 'Revenue settings retrieved successfully.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update the global revenue configuration.
     *
     * PUT /api/revenue-settings/{revenueSetting}
     */
    public function update(
        RevenueSettingRequest $request,
        RevenueSetting $revenueSetting
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        |
        | RevenueSettingRequest::authorize() already checks:
        |
        |     $user->can('update', $revenueSetting)
        |
        | Therefore authorization is not duplicated here.
        |
        */


        /*
        |--------------------------------------------------------------------------
        | Update Configuration
        |--------------------------------------------------------------------------
        */

        $revenueSetting = $this->revenueSettingService->update(
            revenueSetting: $revenueSetting,
            data: $request->validated(),
            user: $request->user()
        );


        /*
        |--------------------------------------------------------------------------
        | Success Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::updated(
            data: new RevenueSettingResource($revenueSetting),
            message: 'Revenue settings updated successfully.'
        );
    }
}