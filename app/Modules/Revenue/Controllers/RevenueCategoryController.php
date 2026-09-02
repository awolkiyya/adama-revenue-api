<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RevenueCategory;
use App\Modules\Revenue\Requests\StoreRevenueCategoryRequest;
use App\Modules\Revenue\Requests\UpdateRevenueCategoryRequest;
use App\Modules\Revenue\Resources\RevenueCategoryResource;
use App\Modules\Revenue\Services\RevenueCategoryService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RevenueCategoryController extends Controller
{
    public function __construct(
        private readonly RevenueCategoryService $service
    ) {
        /**
         * ========================================================
         * RESOURCE AUTHORIZATION
         * ========================================================
         *
         * Laravel maps controller actions to:
         *
         * index   → viewAny()
         * store   → create()
         * show    → view()
         * update  → update()
         * destroy → delete()
         *
         * Policy:
         *
         *     App\Policies\RevenueCategoryPolicy
         */
        $this->authorizeResource(
            RevenueCategory::class,
            'category'
        );
    }

    /**
     * ============================================================
     * INDEX
     * ============================================================
     *
     * Display a paginated list of revenue categories.
     *
     * Policy:
     *
     *     RevenueCategoryPolicy::viewAny()
     *
     * Permission:
     *
     *     revenue_categories.view
     */
    public function index(Request $request): JsonResponse
    {
        try {
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

        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * ============================================================
     * STORE
     * ============================================================
     *
     * Store a newly created revenue category with its
     * revenue codes.
     *
     * Policy:
     *
     *     RevenueCategoryPolicy::create()
     *
     * Permission:
     *
     *     revenue_categories.create
     */
    public function store(
        StoreRevenueCategoryRequest $request
    ): JsonResponse {
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

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * ============================================================
     * SHOW
     * ============================================================
     *
     * Display the specified revenue category.
     *
     * Policy:
     *
     *     RevenueCategoryPolicy::view()
     *
     * Permission:
     *
     *     revenue_categories.view
     */
    public function show(
        RevenueCategory $category
    ): JsonResponse {
        try {
            $category = $this->service->find(
                $category
            );

            return ApiResponse::success(
                new RevenueCategoryResource($category),
                'Revenue category retrieved successfully.'
            );

        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     *
     * Update the specified revenue category and synchronize
     * its revenue codes.
     *
     * Policy:
     *
     *     RevenueCategoryPolicy::update()
     *
     * Permission:
     *
     *     revenue_categories.update
     */
    public function update(
        UpdateRevenueCategoryRequest $request,
        RevenueCategory $category
    ): JsonResponse {
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

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    /**
     * ============================================================
     * DELETE
     * ============================================================
     *
     * Remove the specified revenue category.
     *
     * Policy:
     *
     *     RevenueCategoryPolicy::delete()
     *
     * Permission:
     *
     *     revenue_categories.delete
     *
     * The service layer remains responsible for determining
     * whether the category can actually be deleted.
     */
    public function destroy(
        RevenueCategory $category
    ): JsonResponse {
        try {
            $this->service->delete(
                $category
            );

            return ApiResponse::deleted();

        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}