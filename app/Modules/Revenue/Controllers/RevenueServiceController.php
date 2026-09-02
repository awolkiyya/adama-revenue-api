<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RevenueService;
use App\Modules\Revenue\Requests\StoreRevenueServiceRequest;
use App\Modules\Revenue\Requests\UpdateRevenueServiceRequest;
use App\Modules\Revenue\Resources\RevenueServiceResource;
use App\Modules\Revenue\Services\RevenueServiceService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RevenueServiceController extends Controller
{
    /**
     * ============================================================
     * CONSTRUCTOR
     * ============================================================
     *
     * authorizeResource() automatically connects controller
     * actions to RevenueServicePolicy.
     *
     * Controller action → Policy ability:
     *
     * index()   → viewAny()
     * store()   → create()
     * show()    → view()
     * update()  → update()
     * destroy() → delete()
     *
     * The route parameter is explicitly "service" so it matches:
     *
     *     RevenueService $service
     *
     * and:
     *
     *     authorizeResource(RevenueService::class, 'service')
     */
    public function __construct(
        protected RevenueServiceService $revenueServiceService
    ) {
        $this->authorizeResource(
            RevenueService::class,
            'service'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Relations required by RevenueServiceResource.
     */
    private function relations(): array
    {
        return [
            'revenueCode',
            'fields.baseField.measurementUnit',
            'fields.baseField.options',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    /**
     * Display a paginated list of revenue services.
     *
     * Policy:
     *     RevenueServicePolicy::viewAny()
     *
     * Permission:
     *     revenue_services.view
     */
    public function index(Request $request): JsonResponse
    {
        try {
            /*
             * Pass the HTTP request to the service because
             * RevenueServiceService::paginate() uses query
             * parameters for filtering and pagination.
             */
            $services = $this->revenueServiceService->paginate(
                $request
            );

            return ApiResponse::success(
                RevenueServiceResource::collection($services),
                'Revenue services retrieved successfully.',
                summary: $this->revenueServiceService->summary()

            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new revenue service.
     *
     * Policy:
     *     RevenueServicePolicy::create()
     *
     * Permission:
     *     revenue_services.create
     */
    public function store(
        StoreRevenueServiceRequest $request
    ): JsonResponse {
        try {
            $service = $this->revenueServiceService->create(
                $request->validated()
            );

            $service->load(
                $this->relations()
            );

            return ApiResponse::created(
                new RevenueServiceResource($service),
                'Revenue service created successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    /**
     * Display a specific revenue service.
     *
     * Policy:
     *     RevenueServicePolicy::view()
     *
     * Permission:
     *     revenue_services.view
     */
    public function show(
        RevenueService $service
    ): JsonResponse {
        try {
            $service->load(
                $this->relations()
            );

            return ApiResponse::success(
                new RevenueServiceResource($service),
                'Revenue service retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing revenue service.
     *
     * Policy:
     *     RevenueServicePolicy::update()
     *
     * Permission:
     *     revenue_services.update
     */
    public function update(
        UpdateRevenueServiceRequest $request,
        RevenueService $service
    ): JsonResponse {
        try {
            $service = $this->revenueServiceService->update(
                $service,
                $request->validated()
            );

            $service->load(
                $this->relations()
            );

            return ApiResponse::updated(
                new RevenueServiceResource($service),
                'Revenue service updated successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    /**
     * Delete an existing revenue service.
     *
     * Policy:
     *     RevenueServicePolicy::delete()
     *
     * Permission:
     *     revenue_services.delete
     *
     * Business/dependency validation remains inside:
     *
     *     RevenueServiceService::delete()
     */
    public function destroy(
        RevenueService $service
    ): JsonResponse {
        try {
            $this->revenueServiceService->delete(
                $service
            );

            return ApiResponse::deleted(
                'Revenue service deleted successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}
