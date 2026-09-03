<?php

namespace App\Modules\Revenue\Services;

use App\Models\PenaltyRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PenaltyRuleService
{
    /**
     * ============================================================
     * CREATE
     * ============================================================
     */
    public function create(
        array $data,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $data,
            $userId
        ) {
            $this->validateBusinessRules($data);

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            $data['is_active'] =
                $data['is_active'] ?? false;

            $rule = PenaltyRule::create($data);

            /*
             * Check application-level overlap as well.
             *
             * PostgreSQL exclusion constraints remain the final
             * concurrency protection.
             */
            if ($rule->is_active) {
                $this->ensureNoOverlappingActiveRule($rule);
            }

            return $rule->fresh([
                'revenueService',
            ]);
        });
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */
    public function update(
        PenaltyRule $penaltyRule,
        array $data,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $data,
            $userId
        ) {
            $this->validateBusinessRules($data);

            $data['updated_by'] = $userId;

            /*
             * If the frontend does not send is_active during an
             * update, preserve the existing status.
             */
            if (! array_key_exists(
                'is_active',
                $data
            )) {
                $data['is_active'] =
                    $penaltyRule->is_active;
            }

            $penaltyRule->update($data);

            $penaltyRule->refresh();

            if ($penaltyRule->is_active) {
                $this->ensureNoOverlappingActiveRule(
                    $penaltyRule
                );
            }

            return $penaltyRule->load(
                'revenueService'
            );
        });
    }

    /**
     * ============================================================
     * ACTIVATE
     * ============================================================
     */
    public function activate(
        PenaltyRule $penaltyRule,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $userId
        ) {
            if ($penaltyRule->is_active) {
                return $penaltyRule->load(
                    'revenueService'
                );
            }

            $this->validateActivation(
                $penaltyRule
            );

            /*
             * Check before activation.
             */
            $this->ensureNoOverlappingActiveRule(
                $penaltyRule
            );

            $penaltyRule->update([
                'is_active' => true,
                'updated_by' => $userId,
            ]);

            return $penaltyRule->fresh([
                'revenueService',
            ]);
        });
    }

    /**
     * ============================================================
     * DEACTIVATE
     * ============================================================
     */
    public function deactivate(
        PenaltyRule $penaltyRule,
        string $userId
    ): PenaltyRule {
        return DB::transaction(function () use (
            $penaltyRule,
            $userId
        ) {
            if (! $penaltyRule->is_active) {
                return $penaltyRule->load(
                    'revenueService'
                );
            }

            $penaltyRule->update([
                'is_active' => false,
                'updated_by' => $userId,
            ]);

            return $penaltyRule->fresh([
                'revenueService',
            ]);
        });
    }

    /**
     * ============================================================
     * BUSINESS VALIDATION
     * ============================================================
     */
    private function validateBusinessRules(
        array $data
    ): void {
        $calculationType =
            $data['calculation_type'] ?? null;

        /*
         * --------------------------------------------------------
         * FIXED
         * --------------------------------------------------------
         */
        if ($calculationType === 'FIXED') {
            if (
                ! isset($data['fixed_amount'])
                || (float) $data['fixed_amount'] < 0
            ) {
                throw ValidationException::withMessages([
                    'fixed_amount' =>
                        'Fixed amount is required for a fixed penalty.',
                ]);
            }
        }

        /*
         * --------------------------------------------------------
         * PERCENTAGE
         * --------------------------------------------------------
         */
        if ($calculationType === 'PERCENTAGE') {
            if (
                ! isset($data['initial_rate'])
                || (float) $data['initial_rate'] < 0
            ) {
                throw ValidationException::withMessages([
                    'initial_rate' =>
                        'Initial rate is required for a percentage penalty.',
                ]);
            }
        }

        /*
         * --------------------------------------------------------
         * PROGRESSIVE
         * --------------------------------------------------------
         */
        if ($calculationType === 'PROGRESSIVE') {
            $initial =
                (float) ($data['initial_rate'] ?? 0);

            $increment =
                (float) ($data['increment_rate'] ?? 0);

            $maximum =
                $data['maximum_rate'] !== null
                    ? (float) $data['maximum_rate']
                    : null;

            if ($initial < 0) {
                throw ValidationException::withMessages([
                    'initial_rate' =>
                        'Initial rate cannot be negative.',
                ]);
            }

            if ($increment < 0) {
                throw ValidationException::withMessages([
                    'increment_rate' =>
                        'Increment rate cannot be negative.',
                ]);
            }

            if (
                $maximum !== null
                && $maximum < $initial
            ) {
                throw ValidationException::withMessages([
                    'maximum_rate' =>
                        'Maximum rate must be greater than or equal to the initial rate.',
                ]);
            }
        }

        /*
         * --------------------------------------------------------
         * START OFFSET
         * --------------------------------------------------------
         */
        $startType =
            $data['start_type'] ?? null;

        $offset =
            (int) ($data['start_offset_value'] ?? 0);

        if ($startType === 'AFTER_GRACE_PERIOD') {
            if ($offset < 1) {
                throw ValidationException::withMessages([
                    'start_offset_value' =>
                        'A grace period must be at least one unit.',
                ]);
            }
        } else {
            if ($offset !== 0) {
                throw ValidationException::withMessages([
                    'start_offset_value' =>
                        'Start offset must be zero unless AFTER_GRACE_PERIOD is selected.',
                ]);
            }
        }

        /*
         * --------------------------------------------------------
         * EFFECTIVE DATES
         * --------------------------------------------------------
         */
        if (
            isset($data['effective_from'])
            && isset($data['effective_to'])
            && $data['effective_to'] !== null
        ) {
            if (
                $data['effective_to']
                < $data['effective_from']
            ) {
                throw ValidationException::withMessages([
                    'effective_to' =>
                        'Effective end date must be on or after effective start date.',
                ]);
            }
        }
    }

    /**
     * ============================================================
     * ACTIVATION VALIDATION
     * ============================================================
     */
    private function validateActivation(
        PenaltyRule $penaltyRule
    ): void {
        if (
            $penaltyRule->effective_to !== null
            && $penaltyRule->effective_to
                ->lt($penaltyRule->effective_from)
        ) {
            throw ValidationException::withMessages([
                'effective_to' =>
                    'The penalty rule has an invalid effective period.',
            ]);
        }
    }

    /**
     * ============================================================
     * OVERLAP VALIDATION
     * ============================================================
     *
     * Prevent:
     *
     * Global:
     *
     *     Rule A: 2026-01-01 → 2026-12-31
     *     Rule B: 2026-06-01 → 2027-01-01
     *
     * Service-specific:
     *
     *     Service A + overlapping dates
     *
     * But:
     *
     *     Global + Service-specific
     *
     * is allowed because service-specific rules override global
     * rules during resolution.
     */
    private function ensureNoOverlappingActiveRule(
        PenaltyRule $penaltyRule
    ): void {
        $query = PenaltyRule::query()
            ->where('id', '!=', $penaltyRule->id)
            ->where('is_active', true);

        /*
         * Same scope.
         */
        if ($penaltyRule->revenue_service_id === null) {
            $query->whereNull(
                'revenue_service_id'
            );
        } else {
            $query->where(
                'revenue_service_id',
                $penaltyRule->revenue_service_id
            );
        }

        /*
         * Effective period overlap:
         *
         * existing.from <= current.to
         *
         * AND
         *
         * existing.to >= current.from
         *
         * NULL effective_to means open-ended.
         */
        $query->whereDate(
            'effective_from',
            '<=',
            $penaltyRule->effective_to
                ?? '9999-12-31'
        );

        $query->where(function (Builder $query) use (
            $penaltyRule
        ) {
            $query
                ->whereNull('effective_to')
                ->orWhereDate(
                    'effective_to',
                    '>=',
                    $penaltyRule->effective_from
                );
        });

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' =>
                    'Another active penalty rule already exists for the same scope and overlapping effective period.',
            ]);
        }
    }
}