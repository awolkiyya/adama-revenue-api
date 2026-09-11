<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RevenueService;
use App\Models\ServiceAccessRule;
use App\Modules\Revenue\Requests\StoreServiceAccessRuleRequest;
use App\Modules\Revenue\Requests\UpdateServiceAccessRuleRequest;
use App\Modules\Revenue\Resources\ServiceAccessRuleResource;
use App\Modules\Revenue\Services\ServiceAccessRuleService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Throwable;
use Illuminate\Http\JsonResponse;




class ServiceAccessRuleController extends Controller
{
    public function __construct(
        private ServiceAccessRuleService $service
    ) {}

    /**
     * List service access rules.
     *
     * Permission:
     * service_access_rules.view
     *
     * GET /revenue/services/{service}/access-rules
     */
    public function index(
        RevenueService $service,
        Request $request
    ): JsonResponse {
        try {
            $rules = $this->service->all(
                $service,
                $request->all()
            );
    
            return ApiResponse::success(
                ServiceAccessRuleResource::collection($rules),
                'Service access rules retrieved successfully.',
                summary: $this->service->summary(
                    $service,
                    $request->all()
                )
            );
        } catch (Throwable $e) {
            report($e);
    
            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * Synchronize all sector access rules for a revenue service.
     *
     * Permission:
     * service_access_rules.update
     *
     * PUT /revenue/services/{service}/access-rules
     */
    public function sync(
        UpdateServiceAccessRuleRequest $request,
        RevenueService $service
    ) {
        try {
            $this->authorize(
                'sync',
                [
                    ServiceAccessRule::class,
                    $service,
                ]
            );

            $rules = $this->service->sync(
                $service,
                $request->validated()
            );

            return ApiResponse::updated(
                ServiceAccessRuleResource::collection(
                    $rules
                ),
                'Service access rules synchronized successfully'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * Show a single service access rule.
     *
     * Permission:
     * service_access_rules.view
     *
     * GET /revenue/services/{service}/access-rules/{rule}
     */
    public function show(
        ServiceAccessRule $rule
    ) {
        try {
            $this->authorize(
                'view',
                $rule
            );

            return ApiResponse::success(
                new ServiceAccessRuleResource(
                    $this->service->find($rule)
                ),
                'Service access rule retrieved successfully'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * Update a single service access rule.
     *
     * Permission:
     * service_access_rules.update
     *
     * PATCH /revenue/services/{service}/access-rules/{rule}
     */
    public function update(
        UpdateServiceAccessRuleRequest $request,
        ServiceAccessRule $rule
    ) {
        try {
            $this->authorize(
                'update',
                $rule
            );

            $updatedRule = $this->service->update(
                $rule,
                $request->validated()
            );

            return ApiResponse::updated(
                new ServiceAccessRuleResource(
                    $updatedRule
                ),
                'Service access rule updated successfully'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * Change the active status of a service access rule.
     *
     * Permission:
     * service_access_rules.activate
     * service_access_rules.deactivate
     *
     * PATCH /revenue/services/{service}/access-rules/{rule}/status
     */
    public function changeStatus(
        Request $request,
        ServiceAccessRule $rule
    ) {
        try {
            $isActive = $request->boolean('is_active');

            $this->authorize(
                $isActive
                    ? 'activate'
                    : 'deactivate',
                $rule
            );

            $updatedRule = $this->service->update(
                $rule,
                [
                    'is_active' => $isActive,
                ]
            );

            return ApiResponse::updated(
                new ServiceAccessRuleResource(
                    $updatedRule
                ),
                $isActive
                    ? 'Service access rule activated successfully'
                    : 'Service access rule deactivated successfully'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}