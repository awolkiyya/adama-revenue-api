<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PenaltyRule;
use App\Modules\Revenue\Requests\StorePenaltyRuleRequest;
use App\Modules\Revenue\Requests\UpdatePenaltyRuleRequest;
use App\Modules\Revenue\Resources\PenaltyRuleResource;
use App\Modules\Revenue\Services\PenaltyRuleService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PenaltyRuleController extends Controller
{
    public function __construct(
        private readonly PenaltyRuleService $penaltyRuleService
    ) {
    }

    /**
     * ============================================================
     * INDEX
     * ============================================================
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize(
            'viewAny',
            PenaltyRule::class
        );

        try {
            $perPage = min(
                max(
                    (int) $request->input(
                        'per_page',
                        15
                    ),
                    1
                ),
                100
            );

            $query = PenaltyRule::query()
                ->with('revenueService');

            /*
             * ----------------------------------------------------
             * Search
             * ----------------------------------------------------
             */
            if ($request->filled('search')) {
                $search = trim(
                    $request->input('search')
                );

                $query->where(function ($q) use (
                    $search
                ) {
                    $q
                        ->where(
                            'name',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'description',
                            'ILIKE',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'legal_reference',
                            'ILIKE',
                            "%{$search}%"
                        );
                });
            }

            /*
             * ----------------------------------------------------
             * Service
             * ----------------------------------------------------
             */
            if ($request->filled(
                'revenue_service_id'
            )) {
                $query->where(
                    'revenue_service_id',
                    $request->input(
                        'revenue_service_id'
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * Scope
             * ----------------------------------------------------
             */
            if (
                $request->input('scope')
                === 'GLOBAL'
            ) {
                $query->whereNull(
                    'revenue_service_id'
                );
            }

            if (
                $request->input('scope')
                === 'SERVICE_SPECIFIC'
            ) {
                $query->whereNotNull(
                    'revenue_service_id'
                );
            }

            /*
             * ----------------------------------------------------
             * Status
             * ----------------------------------------------------
             */
            if (
                $request->has('is_active')
            ) {
                $query->where(
                    'is_active',
                    filter_var(
                        $request->input('is_active'),
                        FILTER_VALIDATE_BOOLEAN
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * Calculation Type
             * ----------------------------------------------------
             */
            if ($request->filled(
                'calculation_type'
            )) {
                $query->where(
                    'calculation_type',
                    $request->input(
                        'calculation_type'
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * Sorting
             * ----------------------------------------------------
             */
            $sortBy = $request->input(
                'sort_by',
                'effective_from'
            );

            $allowedSorts = [
                'name',
                'calculation_type',
                'effective_from',
                'effective_to',
                'created_at',
                'updated_at',
            ];

            if (! in_array(
                $sortBy,
                $allowedSorts,
                true
            )) {
                $sortBy = 'effective_from';
            }

            $sortDirection =
                strtolower(
                    $request->input(
                        'sort_direction',
                        'desc'
                    )
                ) === 'asc'
                    ? 'asc'
                    : 'desc';

            $query->orderBy(
                $sortBy,
                $sortDirection
            );

            /*
             * ----------------------------------------------------
             * Pagination
             * ----------------------------------------------------
             */
            $rules = $query->paginate(
                $perPage
            );

            return ApiResponse::success(
                PenaltyRuleResource::collection(
                    $rules
                ),
                'Penalty rules retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to retrieve penalty rules.',
                500
            );
        }
    }

    /**
     * ============================================================
     * SHOW
     * ============================================================
     */
    public function show(
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'view',
            $penaltyRule
        );

        try {
            $penaltyRule->load(
                'revenueService'
            );

            return ApiResponse::success(
                new PenaltyRuleResource(
                    $penaltyRule
                ),
                'Penalty rule retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to retrieve penalty rule.',
                500
            );
        }
    }

    /**
     * ============================================================
     * STORE
     * ============================================================
     */
    public function store(
        StorePenaltyRuleRequest $request
    ): JsonResponse {
        try {
            $penaltyRule =
                $this->penaltyRuleService->create(
                    $request->validated(),
                    $request->user()->id
                );

            return ApiResponse::success(
                new PenaltyRuleResource(
                    $penaltyRule
                ),
                'Penalty rule created successfully.',
                201
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to create penalty rule.',
                500
            );
        }
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */
    public function update(
        UpdatePenaltyRuleRequest $request,
        PenaltyRule $penaltyRule
    ): JsonResponse {
        try {
            $penaltyRule =
                $this->penaltyRuleService->update(
                    $penaltyRule,
                    $request->validated(),
                    $request->user()->id
                );

            return ApiResponse::success(
                new PenaltyRuleResource(
                    $penaltyRule
                ),
                'Penalty rule updated successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to update penalty rule.',
                500
            );
        }
    }

    /**
     * ============================================================
     * ACTIVATE
     * ============================================================
     */
    public function activate(
        Request $request,
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'activate',
            $penaltyRule
        );

        try {
            $penaltyRule =
                $this->penaltyRuleService->activate(
                    $penaltyRule,
                    $request->user()->id
                );

            return ApiResponse::success(
                new PenaltyRuleResource(
                    $penaltyRule
                ),
                'Penalty rule activated successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to activate penalty rule.',
                500
            );
        }
    }

    /**
     * ============================================================
     * DEACTIVATE
     * ============================================================
     */
    public function deactivate(
        Request $request,
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'deactivate',
            $penaltyRule
        );

        try {
            $penaltyRule =
                $this->penaltyRuleService->deactivate(
                    $penaltyRule,
                    $request->user()->id
                );

            return ApiResponse::success(
                new PenaltyRuleResource(
                    $penaltyRule
                ),
                'Penalty rule deactivated successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Failed to deactivate penalty rule.',
                500
            );
        }
    }

    /**
     * ============================================================
     * HISTORY
     * ============================================================
     */
    public function history(
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'viewHistory',
            $penaltyRule
        );

        /*
         * This endpoint assumes you later add a dedicated
         * penalty_rule_histories / audit mechanism.
         *
         * Do not pretend the current PenaltyRule table itself
         * represents historical versions.
         */

        return ApiResponse::success(
            [],
            'Penalty rule history retrieved successfully.'
        );
    }
}