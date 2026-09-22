<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Citizen;
use App\Models\InterestRule;
use App\Models\PenaltyRule;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use App\Models\TariffRule;
use App\Services\Calculations\DueDateResolver;
use App\Services\TariffCalculator;
use App\Services\TariffResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DirectCollectionCalculator
{
    private const MAX_AMOUNT = 999999999999.9999;

    public function __construct(
        protected TariffResolver $resolver,
        protected TariffCalculator $calculator,
        protected DueDateResolver $dueDateResolver,
        protected DirectCollectionSnapshotService $snapshotService,
    ) {
    }

    /**
     * Calculate the direct collection amount.
     *
     * @param Collection<int, RevenueServiceField> $fields
     * @param array<string,mixed> $inputs
     *
     * @return array<string,mixed>
     */
    public function calculate(
        Citizen $taxpayer,
        RevenueService $service,
        Collection $fields,
        array $inputs,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Collection Date
        |--------------------------------------------------------------------------
        |
        | Use ONE reference date for the entire calculation.
        |
        | This ensures tariff version, tariff rule, penalty, interest
        | and due-date resolution all use the same point in time.
        |
        */

        $collectionDate = now();

        /*
        |--------------------------------------------------------------------------
        | 2. Resolve Tariff Values
        |--------------------------------------------------------------------------
        |
        | Input keys are RevenueServiceField UUIDs.
        |
        | TariffResolver expands them into the value map required by
        | tariff rule matching and formula calculation.
        |
        */

        $values = $this->resolver->buildRevenueServiceValueMap(
            $service,
            $inputs,
        );

        /*
        |--------------------------------------------------------------------------
        | 3. Resolve Tariff Version
        |--------------------------------------------------------------------------
        */

        $version = $this->resolver->resolveVersionForRevenueService(
            $service,
            $collectionDate,
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Resolve Tariff Rule
        |--------------------------------------------------------------------------
        */

        $rule = $this->resolver->resolveRuleForRevenueService(
            $version,
            (string) $service->id,
            $values,
        );

        /*
        |--------------------------------------------------------------------------
        | 5. Calculate Principal
        |--------------------------------------------------------------------------
        |
        | TariffCalculator is responsible only for calculating the
        | already-resolved tariff rule.
        |
        | It does NOT resolve the tariff version or tariff rule.
        |
        */

        $result = $this->calculator->calculateForRevenueService(
            $rule,
            $values,
        );

        /*
        |--------------------------------------------------------------------------
        | 6. Normalize Result
        |--------------------------------------------------------------------------
        */

        $calculationResult = $this->normalizeCalculationResult(
            $result,
            $rule,
        );

        /*
        |--------------------------------------------------------------------------
        | 7. Validate Result
        |--------------------------------------------------------------------------
        */

        $this->validateCalculationResult(
            $calculationResult,
        );

        /*
        |--------------------------------------------------------------------------
        | 8. Financial Values
        |--------------------------------------------------------------------------
        */

        $amount = $this->decimal(
            $calculationResult['amount'],
        );

        $currency = strtoupper(
            (string) (
                $calculationResult['currency_code']
                ?? $version->currency_code
                ?? $service->currency_code
                ?? 'ETB'
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | 9. Resolve Penalty Rule
        |--------------------------------------------------------------------------
        */

        $penaltyRule = $this->resolver->resolvePenaltyRuleForRevenueService(
            $collectionDate,
            $values,
            sprintf(
                'direct collection for revenue service %s',
                $service->id,
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | 10. Resolve Interest Rule
        |--------------------------------------------------------------------------
        */

        $interestRule = $this->resolver->resolveInterestRuleForRevenueService(
            $collectionDate,
            sprintf(
                'direct collection for revenue service %s',
                $service->id,
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | 11. Resolve Due Date
        |--------------------------------------------------------------------------
        |
        | Use the SAME collection date that was used for tariff,
        | penalty and interest resolution.
        |
        */

        $dueDate = $this->resolveDueDate(
            $service,
            $penaltyRule,
            $values,
            $collectionDate,
        );

        /*
        |--------------------------------------------------------------------------
        | 12. Build Input Snapshot
        |--------------------------------------------------------------------------
        */

        $inputSnapshot = $this->snapshotService->buildInputSnapshot(
            $fields,
            $inputs,
        );

        /*
        |--------------------------------------------------------------------------
        | 13. Build Calculation Snapshot
        |--------------------------------------------------------------------------
        */

        $calculationSnapshot = $this->snapshotService->buildCalculationSnapshot(
            result: $calculationResult,
            values: $values,
            inputs: $inputs,
            version: $version,
            rule: $rule,
            penaltyRule: $penaltyRule,
            interestRule: $interestRule,
            dueDate: $dueDate,
            amount: $amount,
            currency: $currency,
        );

        /*
        |--------------------------------------------------------------------------
        | 14. Return Calculation
        |--------------------------------------------------------------------------
        */

        return [
            'taxpayer_id' => (string) $taxpayer->id,

            'revenue_service_id' => (string) $service->id,

            'service' => [
                'id' => (string) $service->id,

                'code' => $service->code ?? null,

                'name' =>
                    $service->name
                    ?? $service->service_name
                    ?? null,
            ],

            'amount' => $amount,

            'currency' => $currency,

            'quantity' => $this->extractQuantity(
                $calculationResult,
            ),

            'unit' => $this->extractUnit(
                $calculationResult,
                $service,
            ),

            'unit_price' => $this->extractUnitPrice(
                $calculationResult,
            ),

            'tariff_version_id' => (string) $version->id,

            'tariff_rule_id' => (string) $rule->id,

            'penalty_rule_id' =>
                $penaltyRule?->id
                    ? (string) $penaltyRule->id
                    : null,

            'interest_rule_id' =>
                $interestRule?->id
                    ? (string) $interestRule->id
                    : null,

            'due_date' => $dueDate?->toDateString(),

            'calculation_type' =>
                $calculationResult['calculation_type']
                ?? $rule->calculation_type
                ?? null,

            'input_snapshot' => $inputSnapshot,

            'calculation_snapshot' => $calculationSnapshot,
        ];
    }

    /**
     * Resolve direct collection due date.
     *
     * The supplied reference date must be the same date used by
     * tariff, penalty and interest resolution.
     */
    protected function resolveDueDate(
        RevenueService $service,
        ?PenaltyRule $penaltyRule,
        array $values,
        Carbon $referenceDate,
    ): ?Carbon {
        /*
        |--------------------------------------------------------------------------
        | Penalty-Based Due Date
        |--------------------------------------------------------------------------
        */
    
        if ($penaltyRule instanceof PenaltyRule) {
            return $this->dueDateResolver->resolveForRevenueService(
                penaltyRule: $penaltyRule,
                collectionDate: $referenceDate,
                values: $values,
            );
        }
    
        /*
        |--------------------------------------------------------------------------
        | Service-Level Due Date
        |--------------------------------------------------------------------------
        */
    
        if (
            isset($service->due_date)
            && $service->due_date !== null
            && trim((string) $service->due_date) !== ''
        ) {
            return Carbon::parse(
                $service->due_date,
            )->startOfDay();
        }
    
        /*
        |--------------------------------------------------------------------------
        | No Due Date
        |--------------------------------------------------------------------------
        */
    
        return null;
    }

    /**
     * Normalize calculator result.
     *
     * @return array<string,mixed>
     */
    protected function normalizeCalculationResult(
        mixed $result,
        TariffRule $rule,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Object Result
        |--------------------------------------------------------------------------
        */

        if (is_object($result)) {
            if (
                method_exists(
                    $result,
                    'isSuccessful',
                )
                && ! $result->isSuccessful()
            ) {
                throw new RuntimeException(
                    $result->error
                    ?? 'Direct collection calculation failed.',
                );
            }

            $metadata = is_array(
                $result->metadata ?? null,
            )
                ? $result->metadata
                : [];

            return [
                'amount' =>
                    $result->amount
                    ?? null,

                'currency_code' =>
                    $result->currencyCode
                    ?? $metadata['currency_code']
                    ?? null,

                'calculation_type' =>
                    $metadata['calculation_type']
                    ?? $rule->calculation_type
                    ?? null,

                'quantity' =>
                    $metadata['quantity']
                    ?? null,

                'unit' =>
                    $metadata['unit']
                    ?? null,

                'unit_price' =>
                    $metadata['unit_price']
                    ?? null,

                'minimum_amount' =>
                    $metadata['minimum_amount']
                    ?? null,

                'maximum_amount' =>
                    $metadata['maximum_amount']
                    ?? null,

                'rounding_rule' =>
                    $metadata['rounding_rule']
                    ?? null,

                'formula' =>
                    $metadata['formula']
                    ?? null,

                'metadata' =>
                    $metadata,

                'calculated_at' =>
                    $metadata['calculated_at']
                    ?? now()->toISOString(),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Array Result
        |--------------------------------------------------------------------------
        */

        if (is_array($result)) {
            return [
                'amount' =>
                    $result['amount']
                    ?? null,

                'currency_code' =>
                    $result['currency_code']
                    ?? $result['currency']
                    ?? null,

                'calculation_type' =>
                    $result['calculation_type']
                    ?? $rule->calculation_type
                    ?? null,

                'quantity' =>
                    $result['quantity']
                    ?? null,

                'unit' =>
                    $result['unit']
                    ?? null,

                'unit_price' =>
                    $result['unit_price']
                    ?? null,

                'minimum_amount' =>
                    $result['minimum_amount']
                    ?? null,

                'maximum_amount' =>
                    $result['maximum_amount']
                    ?? null,

                'rounding_rule' =>
                    $result['rounding_rule']
                    ?? null,

                'formula' =>
                    $result['formula']
                    ?? null,

                'metadata' =>
                    $result['metadata']
                    ?? null,

                'calculated_at' =>
                    $result['calculated_at']
                    ?? now()->toISOString(),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Invalid Result
        |--------------------------------------------------------------------------
        */

        throw new RuntimeException(
            'TariffCalculator returned an invalid direct collection calculation result.',
        );
    }

    /**
     * Validate calculation result.
     */
    protected function validateCalculationResult(
        array $result,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Amount Exists
        |--------------------------------------------------------------------------
        */

        if (! array_key_exists('amount', $result)) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The tariff calculator did not return an amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Amount Is Numeric
        |--------------------------------------------------------------------------
        */

        if (! is_numeric($result['amount'])) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The tariff calculator returned an invalid amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Amount Is Finite
        |--------------------------------------------------------------------------
        */

        $amount = (float) $result['amount'];

        if (! is_finite($amount)) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The tariff calculator returned a non-finite amount.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Amount Cannot Be Negative
        |--------------------------------------------------------------------------
        */

        if ($amount < 0) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The calculated amount cannot be negative.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum Amount
        |--------------------------------------------------------------------------
        */

        if ($amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The calculated amount exceeds the allowed limit.',
                ],
            ]);
        }
    }

    /**
     * Extract quantity from calculation result.
     */
    protected function extractQuantity(
        array $result,
    ): ?string {
        if (
            ! array_key_exists('quantity', $result)
            || $result['quantity'] === null
        ) {
            return null;
        }

        return $this->decimal(
            $result['quantity'],
        );
    }

    /**
     * Extract unit from calculation result or service.
     */
    protected function extractUnit(
        array $result,
        RevenueService $service,
    ): ?string {
        return $result['unit']
            ?? $service->unit
            ?? null;
    }

    /**
     * Extract unit price from calculation result.
     */
    protected function extractUnitPrice(
        array $result,
    ): ?string {
        if (
            ! array_key_exists('unit_price', $result)
            || $result['unit_price'] === null
        ) {
            return null;
        }

        return $this->decimal(
            $result['unit_price'],
        );
    }

    /**
     * Format numeric values as fixed-point decimals.
     */
    protected function decimal(
        mixed $value,
    ): string {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Expected a numeric decimal value.',
            );
        }

        return number_format(
            (float) $value,
            4,
            '.',
            '',
        );
    }
}