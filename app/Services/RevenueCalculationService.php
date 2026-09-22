<?php

namespace App\Services;

use App\Models\AssessmentService;
use App\Models\RevenueService as RevenueServiceModel;
use App\Services\Calculations\DueDateResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * RevenueCalculationService
 *
 * Shared revenue calculation orchestration service.
 *
 * This service is responsible for:
 *
 * - Resolving the applicable tariff version
 * - Resolving the applicable tariff rule
 * - Calculating the original/principal amount
 * - Resolving penalty configuration
 * - Resolving interest configuration
 * - Resolving the payment due date
 * - Returning an immutable calculation result
 *
 * This service DOES NOT:
 *
 * - Create invoices
 * - Issue invoices
 * - Approve assessments
 * - Reject assessments
 * - Collect payments
 * - Calculate accrued penalties
 * - Calculate accrued interest
 * - Calculate outstanding balances
 *
 * AssessmentCalculationService and DirectCollectionService are
 * responsible for adapting their respective workflows to this service.
 */
class RevenueCalculationService
{
    public function __construct(
        private readonly TariffResolver $resolver,
        private readonly TariffCalculator $calculator,
        private readonly DueDateResolver $dueDateResolver,
    ) {
    }

    /**
     * Calculate revenue for an AssessmentService.
     *
     * This method preserves the existing Assessment calculation
     * behavior while moving the actual calculation orchestration
     * into the shared RevenueCalculationService.
     *
     * @return array{
     *     amount: float,
     *     currency_code: ?string,
     *     tariff_version_id: string|int|null,
     *     tariff_rule_id: string|int|null,
     *     calculation_type: string|null,
     *     penalty_rule_id: string|int|null,
     *     interest_rule_id: string|int|null,
     *     due_date: string|null,
     *     metadata: array
     * }
     */
    public function calculateAssessmentService(
        AssessmentService $assessmentService
    ): array {
        return DB::transaction(function () use ($assessmentService): array {
            $assessmentService->loadMissing([
                'assessment',
                'service',
                'values',
            ]);

            if (! $assessmentService->service) {
                throw new RuntimeException(
                    'Revenue service could not be found for assessment service.'
                );
            }

            return $this->calculateUsingAssessmentService(
                $assessmentService
            );
        });
    }

    /**
     * Internal AssessmentService calculation.
     *
     * This method is intentionally separate from the transaction wrapper
     * so callers such as AssessmentCalculationService can execute multiple
     * service calculations inside their existing transaction.
     */
    public function calculateUsingAssessmentService(
        AssessmentService $assessmentService
    ): array {
        $version = $this->resolver->resolveVersion(
            $assessmentService
        );

        if (! $version) {
            throw new RuntimeException(
                "No applicable tariff version found for assessment service {$assessmentService->id}."
            );
        }

        $rule = $this->resolver->resolveRule(
            $version,
            $assessmentService
        );

        if (! $rule) {
            throw new RuntimeException(
                "No applicable tariff rule found for assessment service {$assessmentService->id}."
            );
        }

        $result = $this->calculator->calculate(
            $rule,
            $assessmentService
        );

        if (! $result->successful) {
            throw new RuntimeException(
                $result->error
                    ?? "Revenue calculation failed for assessment service {$assessmentService->id}."
            );
        }

        $penaltyRule = $this->resolver->resolvePenaltyRule(
            $assessmentService
        );

        if (! $penaltyRule) {
            throw new RuntimeException(
                "No applicable penalty rule found for assessment service {$assessmentService->id}."
            );
        }

        $interestRule = $this->resolver->resolveInterestRule(
            $assessmentService
        );

        $dueDate = $this->dueDateResolver->resolve(
            $assessmentService,
            $penaltyRule
        );

        $metadata = [
            'tariff_version_id' => $version->id ?? null,
            'tariff_rule_id' => $rule->id ?? null,
            'calculation_type' => $result->calculationType ?? null,
            'penalty_rule_id' => $penaltyRule->id ?? null,
            'interest_rule_id' => $interestRule?->id,
            'due_date' => $this->normalizeDate($dueDate),
            'calculated_at' => now()->toISOString(),
        ];

        if (is_array($result->metadata ?? null)) {
            $metadata = array_merge(
                $metadata,
                $result->metadata
            );
        }

        return [
            'amount' => $this->normalizeAmount(
                $result->amount
            ),

            'currency_code' => $result->currencyCode
                ?? $this->resolveCurrency($version),

            'tariff_version_id' => $version->id ?? null,

            'tariff_rule_id' => $rule->id ?? null,

            'calculation_type' => $result->calculationType
                ?? null,

            'penalty_rule_id' => $penaltyRule->id
                ?? null,

            'interest_rule_id' => $interestRule?->id,

            'due_date' => $this->normalizeDate($dueDate),

            'metadata' => $metadata,
        ];
    }

    /**
     * Build a normalized calculation snapshot.
     *
     * This is useful when storing the calculation result in:
     *
     * invoice_items.calculation_snapshot
     *
     * The returned snapshot should be treated as historical data and
     * should not be recalculated when an invoice is later viewed.
     */
    public function buildCalculationSnapshot(
        array $calculation
    ): array {
        return [
            'amount' => $calculation['amount'] ?? 0,

            'currency_code' => $calculation['currency_code']
                ?? 'ETB',

            'tariff_version_id' => $calculation[
                'tariff_version_id'
            ] ?? null,

            'tariff_rule_id' => $calculation[
                'tariff_rule_id'
            ] ?? null,

            'calculation_type' => $calculation[
                'calculation_type'
            ] ?? null,

            'penalty_rule_id' => $calculation[
                'penalty_rule_id'
            ] ?? null,

            'interest_rule_id' => $calculation[
                'interest_rule_id'
            ] ?? null,

            'due_date' => $calculation[
                'due_date'
            ] ?? null,

            'calculated_at' => now()->toISOString(),

            'metadata' => $calculation[
                'metadata'
            ] ?? [],
        ];
    }

    /**
     * Return the principal amount only.
     *
     * Important:
     *
     * The amount returned by the tariff calculator represents the
     * original financial obligation.
     *
     * It must NOT include future/accrued penalty or interest.
     */
    public function principalAmount(
        array $calculation
    ): float {
        return $this->normalizeAmount(
            $calculation['amount'] ?? 0
        );
    }

    /**
     * Resolve the currency from a tariff version.
     */
    private function resolveCurrency(
        object $version
    ): ?string {
        return $version->currency_code ?? null;
    }

    /**
     * Normalize financial amount.
     *
     * Invoice/database precision is handled by the database layer,
     * but calculations should always work with a numeric value.
     */
    private function normalizeAmount(
        mixed $amount
    ): float {
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        if (! is_numeric($amount)) {
            throw new RuntimeException(
                'Revenue calculation returned an invalid amount.'
            );
        }

        return round(
            (float) $amount,
            4
        );
    }

    /**
     * Normalize due date into a database-friendly string.
     */
    private function normalizeDate(
        mixed $date
    ): ?string {
        if ($date === null) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->toDateString();
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date) && trim($date) !== '') {
            return Carbon::parse($date)->toDateString();
        }

        return null;
    }
}