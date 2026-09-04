<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PenaltyRule;
use App\Modules\Revenue\Requests\StorePenaltyRuleRequest;
use App\Modules\Revenue\Requests\UpdatePenaltyRuleRequest;
use App\Modules\Revenue\Resources\PenaltyRuleResource;
use App\Modules\Revenue\Services\PenaltyRuleService;
use App\Services\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PenaltyRuleController extends Controller
{
    public function __construct(
        private readonly PenaltyRuleService $penaltyRuleService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    /**
     * Get a paginated list of penalty rules.
     *
     * GET /api/revenue/penalty-rules
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize(
            'viewAny',
            PenaltyRule::class
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $perPage = min(
                max(
                    (int) $request->input('per_page', 15),
                    1
                ),
                100
            );

            /*
            |--------------------------------------------------------------------------
            | Base Query
            |--------------------------------------------------------------------------
            */

            $query = PenaltyRule::query();

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->input('search')
                );

                $query->where(function ($q) use ($search) {
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
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            */

            if ($request->has('is_active')) {
                $isActive = filter_var(
                    $request->input('is_active'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($isActive !== null) {
                    $query->where(
                        'is_active',
                        $isActive
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Start Type
            |--------------------------------------------------------------------------
            */

            if ($request->filled('start_type')) {
                $query->where(
                    'start_type',
                    $request->input('start_type')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Calculation Basis
            |--------------------------------------------------------------------------
            */

            if ($request->filled('calculation_basis')) {
                $query->where(
                    'calculation_basis',
                    $request->input('calculation_basis')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Increment Period
            |--------------------------------------------------------------------------
            */

            if ($request->filled('increment_period')) {
                $query->where(
                    'increment_period',
                    $request->input('increment_period')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            $sortBy = $request->input(
                'sort_by',
                'effective_from'
            );

            $allowedSorts = [
                'name',
                'initial_rate',
                'increment_rate',
                'maximum_rate',
                'start_type',
                'increment_period',
                'calculation_basis',
                'effective_from',
                'effective_to',
                'is_active',
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

            $sortDirection = strtolower(
                (string) $request->input(
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
            |--------------------------------------------------------------------------
            | Stable Secondary Sort
            |--------------------------------------------------------------------------
            */

            if ($sortBy !== 'id') {
                $query->orderBy(
                    'id',
                    'desc'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $rules = $query->paginate(
                $perPage
            );

            return ApiResponse::success(
                data: PenaltyRuleResource::collection(
                    $rules
                ),
                message: 'Penalty rules retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to retrieve penalty rules.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    /**
     * Get a single penalty rule.
     *
     * GET /api/revenue/penalty-rules/{penaltyRule}
     */
    public function show(
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'view',
            $penaltyRule
        );

        try {
            return ApiResponse::success(
                data: new PenaltyRuleResource(
                    $penaltyRule
                ),
                message: 'Penalty rule retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to retrieve penalty rule.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new penalty rule.
     *
     * POST /api/revenue/penalty-rules
     */
    public function store(
        StorePenaltyRuleRequest $request
    ): JsonResponse {
        try {
            $penaltyRule =
                $this->penaltyRuleService->create(
                    data: $request->validated(),
                    userId: $request->user()->id
                );

            return ApiResponse::created(
                data: new PenaltyRuleResource(
                    $penaltyRule
                ),
                message: 'Penalty rule created successfully.'
            );
        } catch (QueryException $e) {
            report($e);

            if ($this->isOverlapViolation($e)) {
                return ApiResponse::conflict(
                    message: $this->overlapMessage(),
                    errors: [
                        'effective_from' => [
                            'The effective period overlaps another active penalty rule with the same commencement type.'
                        ],
                    ]
                );
            }

            return ApiResponse::serverError(
                'Failed to create penalty rule.',
                $e
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to create penalty rule.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    /**
     * Update an existing penalty rule.
     *
     * PATCH /api/revenue/penalty-rules/{penaltyRule}
     */
    public function update(
        UpdatePenaltyRuleRequest $request,
        PenaltyRule $penaltyRule
    ): JsonResponse {
        try {
            $penaltyRule =
                $this->penaltyRuleService->update(
                    penaltyRule: $penaltyRule,
                    data: $request->validated(),
                    userId: $request->user()->id
                );

            return ApiResponse::updated(
                data: new PenaltyRuleResource(
                    $penaltyRule
                ),
                message: 'Penalty rule updated successfully.'
            );
        } catch (QueryException $e) {
            report($e);

            if ($this->isOverlapViolation($e)) {
                return ApiResponse::conflict(
                    message: $this->overlapMessage(),
                    errors: [
                        'effective_from' => [
                            'The effective period overlaps another active penalty rule with the same commencement type.'
                        ],
                    ]
                );
            }

            return ApiResponse::serverError(
                'Failed to update penalty rule.',
                $e
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to update penalty rule.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Activate a penalty rule.
     *
     * PATCH /api/revenue/penalty-rules/{penaltyRule}/activate
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
                    penaltyRule: $penaltyRule,
                    userId: $request->user()->id
                );

            return ApiResponse::updated(
                data: new PenaltyRuleResource(
                    $penaltyRule
                ),
                message: 'Penalty rule activated successfully.'
            );
        } catch (QueryException $e) {
            report($e);

            if ($this->isOverlapViolation($e)) {
                return ApiResponse::conflict(
                    message: $this->overlapMessage(),
                    errors: [
                        'is_active' => [
                            'This penalty rule cannot be activated because its effective period overlaps another active rule with the same commencement type.'
                        ],
                    ]
                );
            }

            return ApiResponse::serverError(
                'Failed to activate penalty rule.',
                $e
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to activate penalty rule.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE
    |--------------------------------------------------------------------------
    */

    /**
     * Deactivate a penalty rule.
     *
     * PATCH /api/revenue/penalty-rules/{penaltyRule}/deactivate
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
                    penaltyRule: $penaltyRule,
                    userId: $request->user()->id
                );

            return ApiResponse::updated(
                data: new PenaltyRuleResource(
                    $penaltyRule
                ),
                message: 'Penalty rule deactivated successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                'Failed to deactivate penalty rule.',
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */

    /**
     * Get the audit history of a penalty rule.
     *
     * GET /api/revenue/penalty-rules/{penaltyRule}/history
     */
    public function history(
        PenaltyRule $penaltyRule
    ): JsonResponse {
        $this->authorize(
            'viewHistory',
            $penaltyRule
        );

        /*
        |--------------------------------------------------------------------------
        | History
        |--------------------------------------------------------------------------
        |
        | The central AuditLog is responsible for audit history.
        |
        | A dedicated history query should be implemented through
        | AuditService / AuditLog rather than storing history inside
        | penalty_rules.
        |
        */

        return ApiResponse::success(
            data: [],
            message: 'Penalty rule history retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONFLICT HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the QueryException was caused by the
     * PostgreSQL exclusion constraint.
     */
    private function isOverlapViolation(
        QueryException $exception
    ): bool {
        if ($exception->getCode() !== '23P01') {
            return false;
        }

        return str_contains(
            $exception->getMessage(),
            'penalty_rules_no_overlapping_periods'
        );
    }

    /**
     * Friendly domain message for an overlapping penalty rule.
     */
    private function overlapMessage(): string
    {
        return
            'The effective period overlaps another active penalty rule with the same commencement type.';
    }
}
