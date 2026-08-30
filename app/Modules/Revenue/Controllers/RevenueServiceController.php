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
use Throwable;

class RevenueServiceController extends Controller
{
    public function __construct(
        protected RevenueServiceService $service
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
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

    public function index(): JsonResponse
    {
        try {
            return ApiResponse::success(
                RevenueServiceResource::collection(
                    $this->service->paginate()
                ),
                'Revenue services retrieved successfully',
                summary: $this->service->summary()
            );
        } catch (Throwable $e) {
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

    public function store(
        StoreRevenueServiceRequest $request
    ): JsonResponse {
        try {
            $service = $this->service->create(
                $request->validated()
            );

            $service->load(
                $this->relations()
            );

            return ApiResponse::created(
                new RevenueServiceResource($service),
                'Revenue service created successfully'
            );
        } catch (Throwable $e) {
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

    public function show(
        RevenueService $service
    ): JsonResponse {
        try {
            $service->load(
                $this->relations()
            );

            return ApiResponse::success(
                new RevenueServiceResource($service),
                'Revenue service retrieved successfully'
            );
        } catch (Throwable $e) {
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

    public function update(
        UpdateRevenueServiceRequest $request,
        RevenueService $service
    ): JsonResponse {
        try {
            $service = $this->service->update(
                $service,
                $request->validated()
            );

            $service->load(
                $this->relations()
            );

            return ApiResponse::updated(
                new RevenueServiceResource($service),
                'Revenue service updated successfully'
            );
        } catch (Throwable $e) {
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

    public function destroy(
        RevenueService $service
    ): JsonResponse {
        try {
            $this->service->delete($service);

            return ApiResponse::deleted(
                'Revenue service deleted successfully'
            );
        } catch (Throwable $e) {
            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}