<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InterestRule;
use App\Modules\Revenue\Requests\IndexInterestRuleRequest;
use App\Modules\Revenue\Requests\StoreInterestRuleRequest;
use App\Modules\Revenue\Requests\UpdateInterestRuleRequest;
use App\Modules\Revenue\Resources\InterestRuleResource;
use App\Modules\Revenue\Services\InterestRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

class InterestRuleController extends Controller
{
    public function __construct(
        private readonly InterestRuleService $interestRuleService
    ) {
    }

    /**
     * =========================================================================
     * INDEX
     * =========================================================================
     *
     * List interest rules using validated filters.
     */
    public function index(
        IndexInterestRuleRequest $request
    ): AnonymousResourceCollection {
        $filters = $request->validated();

        $rules = $this->interestRuleService->paginate(
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['per_page'] ?? 15),
            search: $filters['search'] ?? null,
            isActive: $filters['is_active'] ?? null,
            ratePeriod: $filters['rate_period'] ?? null,
            calculationMethod: $filters['calculation_method'] ?? null,
            calculationBasis: $filters['calculation_basis'] ?? null,
            sortBy: $filters['sort_by'] ?? 'effective_from',
            sortDirection: $filters['sort_direction'] ?? 'desc',
        );

        return InterestRuleResource::collection($rules);
    }

    /**
     * =========================================================================
     * STORE
     * =========================================================================
     *
     * Store a new interest rule.
     */
    public function store(
        StoreInterestRuleRequest $request
    ): InterestRuleResource {
        $this->authorize(
            'create',
            InterestRule::class
        );

        $rule = $this->interestRuleService->create(
            $request->validated(),
            $request->user()?->id
        );

        return new InterestRuleResource($rule);
    }

    /**
     * =========================================================================
     * SHOW
     * =========================================================================
     *
     * Display a single interest rule.
     */
    public function show(
        InterestRule $interestRule
    ): InterestRuleResource {
        $this->authorize(
            'view',
            $interestRule
        );

        return new InterestRuleResource(
            $interestRule->load([
                'createdBy',
                'updatedBy',
            ])
        );
    }

    /**
     * =========================================================================
     * UPDATE
     * =========================================================================
     *
     * Update an existing interest rule.
     */
    public function update(
        UpdateInterestRuleRequest $request,
        InterestRule $interestRule
    ): InterestRuleResource {
        $this->authorize(
            'update',
            $interestRule
        );

        try {
            $rule = $this->interestRuleService->update(
                $interestRule,
                $request->validated(),
                $request->user()?->id
            );

            return new InterestRuleResource($rule);
        } catch (Throwable $exception) {
            return $this->handlePersistenceException(
                $exception
            );
        }
    }

    /**
     * =========================================================================
     * ACTIVATE
     * =========================================================================
     *
     * Activate an interest rule.
     */
    public function activate(
        InterestRule $interestRule
    ): InterestRuleResource {
        $this->authorize(
            'activate',
            $interestRule
        );

        try {
            $rule = $this->interestRuleService->activate(
                $interestRule,
                request()->user()?->id
            );

            return new InterestRuleResource($rule);
        } catch (Throwable $exception) {
            return $this->handlePersistenceException(
                $exception
            );
        }
    }

    /**
     * =========================================================================
     * DEACTIVATE
     * =========================================================================
     *
     * Deactivate an interest rule.
     */
    public function deactivate(
        InterestRule $interestRule
    ): InterestRuleResource {
        $this->authorize(
            'deactivate',
            $interestRule
        );

        try {
            $rule = $this->interestRuleService->deactivate(
                $interestRule,
                request()->user()?->id
            );

            return new InterestRuleResource($rule);
        } catch (Throwable $exception) {
            return $this->handlePersistenceException(
                $exception
            );
        }
    }

    /**
     * =========================================================================
     * APPLICABLE
     * =========================================================================
     *
     * Find the active interest rule applicable to a specific date.
     *
     * Example:
     *
     * ?effective_date=2026-09-07
     *
     * If effective_date is omitted, today's date is used.
     */
    public function applicable(
        IndexInterestRuleRequest $request
    ): InterestRuleResource|JsonResponse {
        $date = $request->input(
            'effective_date',
            now()->toDateString()
        );

        $rule = $this->interestRuleService->getApplicableRule(
            $date
        );

        if (! $rule) {
            return response()->json([
                'message' =>
                    'No active interest rule applies to the specified date.',

                'effective_date' =>
                    $date,
            ], 404);
        }

        return new InterestRuleResource($rule);
    }

    /**
     * =========================================================================
     * DELETE
     * =========================================================================
     *
     * Interest rules must not be physically deleted.
     *
     * Historical/legal configuration must remain available for
     * audit and historical calculations.
     */
    public function destroy(
        InterestRule $interestRule
    ): never {
        $this->interestRuleService->delete(
            $interestRule
        );
    }

    /**
     * =========================================================================
     * PERSISTENCE EXCEPTION HANDLING
     * =========================================================================
     */
    private function handlePersistenceException(
        Throwable $exception
    ): never {
        $message = $exception->getMessage();

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL exclusion constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_no_overlapping_periods'
            )
        ) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The selected effective period overlaps another '
                    . 'active interest rule.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_rate_non_negative'
            )
        ) {
            throw ValidationException::withMessages([
                'rate' => [
                    'The interest rate cannot be negative.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Rate Period constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_rate_period_check'
            )
        ) {
            throw ValidationException::withMessages([
                'rate_period' => [
                    'The selected rate period is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Method constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_calculation_method_check'
            )
        ) {
            throw ValidationException::withMessages([
                'calculation_method' => [
                    'The selected calculation method is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculation Basis constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_calculation_basis_check'
            )
        ) {
            throw ValidationException::withMessages([
                'calculation_basis' => [
                    'The selected calculation basis is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Effective Period constraint
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $message,
                'interest_rules_valid_effective_period'
            )
        ) {
            throw ValidationException::withMessages([
                'effective_to' => [
                    'The effective end date must be after or equal to '
                    . 'the effective start date.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Unknown exception
        |--------------------------------------------------------------------------
        */

        throw $exception;
    }
}