<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCode;
use App\Models\RevenueCodePaymentScheduleRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueCodePaymentScheduleRuleService
{
    /**
     * Paginate payment schedule rules.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = RevenueCodePaymentScheduleRule::query()
            ->with([
                'revenueCode:id,code,name',
            ]);

        /*
         * Search by revenue code or revenue code name.
         */
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->whereHas(
                'revenueCode',
                function ($revenueCodeQuery) use ($search): void {
                    $revenueCodeQuery
                        ->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%");
                }
            );
        }

        /*
         * Filter by active/inactive state.
         */
        if (array_key_exists('is_enabled', $filters)) {
            $query->where(
                'is_enabled',
                filter_var(
                    $filters['is_enabled'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                )
            );
        }

        /*
         * Filter by revenue code.
         */
        if (! empty($filters['revenue_code_id'])) {
            $query->where(
                'revenue_code_id',
                $filters['revenue_code_id']
            );
        }

        /*
         * Filter rules where a first-installment percentage
         * has or has not been configured.
         */
        if (array_key_exists('has_first_installment_percentage', $filters)) {
            $hasPercentage = filter_var(
                $filters['has_first_installment_percentage'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($hasPercentage === true) {
                $query->whereNotNull(
                    'first_installment_percentage'
                );
            }

            if ($hasPercentage === false) {
                $query->whereNull(
                    'first_installment_percentage'
                );
            }
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = strtolower(
            $filters['sort_direction'] ?? 'desc'
        );

        $allowedSortColumns = [
            'created_at',
            'updated_at',
            'is_enabled',
            'first_installment_percentage',
        ];

        if (! in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'created_at';
        }

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query
            ->orderBy($sortBy, $sortDirection);

        return $query->paginate(
            max(1, min(
                (int) ($filters['per_page'] ?? 20),
                100
            ))
        );
    }

    /**
     * Return summary statistics.
     */
    public function summary(): array
    {
        $query = RevenueCodePaymentScheduleRule::query();

        return [
            'total' => (clone $query)->count(),

            'active' => (clone $query)
                ->where('is_enabled', true)
                ->count(),

            'inactive' => (clone $query)
                ->where('is_enabled', false)
                ->count(),

            'percentage_configured' => (clone $query)
                ->whereNotNull('first_installment_percentage')
                ->count(),

            'percentage_not_configured' => (clone $query)
                ->whereNull('first_installment_percentage')
                ->count(),
        ];
    }

    /**
     * Find a rule by ID.
     */
    public function find(string $id): RevenueCodePaymentScheduleRule
    {
        return RevenueCodePaymentScheduleRule::query()
            ->with([
                'revenueCode:id,code,name',
            ])
            ->findOrFail($id);
    }

    /**
     * Create a payment schedule rule.
     */
    public function create(array $data): RevenueCodePaymentScheduleRule
    {
        return DB::transaction(function () use ($data) {
            $revenueCode = RevenueCode::query()
                ->find($data['revenue_code_id']);

            if (! $revenueCode) {
                throw ValidationException::withMessages([
                    'revenue_code_id' => [
                        'The selected revenue code does not exist.',
                    ],
                ]);
            }

            /*
             * One payment schedule rule per revenue code.
             */
            $existingRule = RevenueCodePaymentScheduleRule::query()
                ->where(
                    'revenue_code_id',
                    $revenueCode->id
                )
                ->first();

            if ($existingRule) {
                throw ValidationException::withMessages([
                    'revenue_code_id' => [
                        'A payment schedule rule already exists for this revenue code.',
                    ],
                ]);
            }

            $rule = RevenueCodePaymentScheduleRule::query()->create([
                'revenue_code_id' => $revenueCode->id,
                'is_enabled' => $data['is_enabled'] ?? false,
                'first_installment_percentage' =>
                    $this->normalizePercentage(
                        $data['first_installment_percentage'] ?? null
                    ),
            ]);

            return $rule->fresh([
                'revenueCode:id,code,name',
            ]);
        });
    }

    /**
     * Update a payment schedule rule.
     */
    public function update(
        RevenueCodePaymentScheduleRule $rule,
        array $data
    ): RevenueCodePaymentScheduleRule {
        return DB::transaction(function () use ($rule, $data) {
            /*
             * Revenue code is intentionally not changed here.
             *
             * The rule is uniquely identified by its revenue code,
             * so changing it after creation would effectively move
             * the configuration to another revenue code.
             */
            $rule->update([
                'is_enabled' => $data['is_enabled'] ??
                    $rule->is_enabled,

                'first_installment_percentage' =>
                    array_key_exists(
                        'first_installment_percentage',
                        $data
                    )
                        ? $this->normalizePercentage(
                            $data['first_installment_percentage']
                        )
                        : $rule->first_installment_percentage,
            ]);

            return $rule->fresh([
                'revenueCode:id,code,name',
            ]);
        });
    }

    /**
     * Activate a payment schedule rule.
     */
    public function activate(
        RevenueCodePaymentScheduleRule $rule
    ): RevenueCodePaymentScheduleRule {
        return DB::transaction(function () use ($rule) {
            $rule->update([
                'is_enabled' => true,
            ]);

            return $rule->fresh([
                'revenueCode:id,code,name',
            ]);
        });
    }

    /**
     * Deactivate a payment schedule rule.
     */
    public function deactivate(
        RevenueCodePaymentScheduleRule $rule
    ): RevenueCodePaymentScheduleRule {
        return DB::transaction(function () use ($rule) {
            $rule->update([
                'is_enabled' => false,
            ]);

            return $rule->fresh([
                'revenueCode:id,code,name',
            ]);
        });
    }

    /**
     * Normalize an optional percentage.
     */
    private function normalizePercentage(
        mixed $percentage
    ): ?float {
        if ($percentage === null) {
            return null;
        }

        if (is_string($percentage)) {
            $percentage = trim($percentage);

            if ($percentage === '') {
                return null;
            }
        }

        return (float) $percentage;
    }
}