<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Requests\StoreRevenueCategoryRequest;
use App\Modules\Revenue\Requests\UpdateRevenueCategoryRequest;
use App\Models\RevenueCategory;
use App\Modules\Revenue\Resources\RevenueCategoryResource;
use App\Modules\Revenue\Services\RevenueCategoryService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class RevenueCategoryController extends Controller
{
    public function __construct(
        private readonly RevenueCategoryService $service
    ) {
    }

   /**
     * Display a paginated list of revenue categories.
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'revenue_domain',
            'is_active',
        ]);


        $categories = $this->service->all(
            $filters
        );


        $summary = $this->service->summary(
            $filters
        );


        return ApiResponse::success(

            RevenueCategoryResource::collection(
                $categories
            ),

            'Revenue categories retrieved successfully.',

            [],

            200,

            $summary

        );
    }

    /**
     * Store a newly created revenue category with its revenue codes.
     */
    public function store(StoreRevenueCategoryRequest $request)
    {
        try {

            $category = $this->service->create(
                $request->validated()
            );

            return ApiResponse::created(
                new RevenueCategoryResource($category),
                'Revenue category created successfully.'
            );

        } catch (Throwable $e) {

            report($e);

            return ApiResponse::serverError(exception: $e);

        }
    }

    /**
     * Display the specified revenue category.
     */
    public function show(RevenueCategory $category)
    {
        try {

            $category = $this->service->find($category);

            return ApiResponse::success(
                new RevenueCategoryResource($category),
                'Revenue category retrieved successfully.'
            );

        } catch (Throwable $e) {

            report($e);

            return ApiResponse::serverError(exception: $e);

        }
    }

    /**
     * Update the specified revenue category and synchronize its codes.
     */
    public function update(
        UpdateRevenueCategoryRequest $request,
        RevenueCategory $category
    ) {
        try {

            $category = $this->service->update(
                $category,
                $request->validated()
            );

            return ApiResponse::updated(
                new RevenueCategoryResource($category),
                'Revenue category updated successfully.'
            );

        } catch (Throwable $e) {

            report($e);

            return ApiResponse::serverError(exception: $e);

        }
    }

    /**
     * Remove the specified revenue category.
     */
    public function destroy(RevenueCategory $category)
    {
        try {

            $this->service->delete($category);

            return ApiResponse::deleted();

        } catch (Throwable $e) {

            report($e);

            return ApiResponse::serverError(exception: $e);

        }
    }
}