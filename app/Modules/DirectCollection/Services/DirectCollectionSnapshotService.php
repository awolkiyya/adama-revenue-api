<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\InterestRule;
use App\Models\PenaltyRule;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use App\Models\TariffRule;
use App\Models\TariffVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DirectCollectionSnapshotService
{
    /**
     * Build a snapshot of the submitted fields.
     *
     * @param Collection<int, RevenueServiceField> $fields
     * @param array<string,mixed> $inputs
     */
    public function buildInputSnapshot(
        Collection $fields,
        array $inputs
    ): array {
        $snapshot = [];

        foreach ($fields as $field) {
            $fieldId =
                (string) $field->id;

            if (
                ! array_key_exists(
                    $fieldId,
                    $inputs
                )
            ) {
                continue;
            }

            $snapshot[$fieldId] = [
                'field_id' =>
                    $fieldId,

                'field_code' =>
                    $field->field_code
                    ?? null,

                'field_label' =>
                    $field->field_label
                    ?? $field->label
                    ?? null,

                'data_type' =>
                    $field->data_type
                    ?? null,

                'input_type' =>
                    $field->input_type
                    ?? null,

                'value' =>
                    $inputs[$fieldId],
            ];
        }

        return $snapshot;
    }

    /**
     * Build the authoritative calculation snapshot.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $values
     * @param array<string,mixed> $inputs
     */
    public function buildCalculationSnapshot(
        array $result,
        array $values,
        array $inputs,
        TariffVersion $version,
        TariffRule $rule,
        ?PenaltyRule $penaltyRule,
        ?InterestRule $interestRule,
        ?Carbon $dueDate,
        string $amount,
        string $currency,
    ): array {
        return [
            'calculation_type' =>
                $result['calculation_type']
                ?? $rule->calculation_type
                ?? null,

            'amount' =>
                $amount,

            'currency' =>
                $currency,

            'tariff_version_id' =>
                (string) $version->id,

            'tariff_rule_id' =>
                (string) $rule->id,

            'penalty_rule_id' =>
                $penaltyRule?->id
                    ? (string) $penaltyRule->id
                    : null,

            'interest_rule_id' =>
                $interestRule?->id
                    ? (string) $interestRule->id
                    : null,

            'due_date' =>
                $dueDate?->toDateString(),

            'quantity' =>
                $result['quantity']
                ?? null,

            'unit' =>
                $result['unit']
                ?? null,

            'unit_price' =>
                isset($result['unit_price'])
                    ? $this->decimal(
                        $result['unit_price']
                    )
                    : null,

            'minimum_amount' =>
                isset($result['minimum_amount'])
                    ? $this->decimal(
                        $result['minimum_amount']
                    )
                    : null,

            'maximum_amount' =>
                isset($result['maximum_amount'])
                    ? $this->decimal(
                        $result['maximum_amount']
                    )
                    : null,

            'rounding_rule' =>
                $result['rounding_rule']
                ?? null,

            'formula' =>
                $result['formula']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Original submitted values
            |--------------------------------------------------------------------------
            */

            'inputs' =>
                $inputs,

            /*
            |--------------------------------------------------------------------------
            | Values resolved for tariff calculation
            |--------------------------------------------------------------------------
            */

            'resolved_values' =>
                $values,

            /*
            |--------------------------------------------------------------------------
            | Calculator metadata
            |--------------------------------------------------------------------------
            */

            'provider_metadata' =>
                $result['metadata']
                ?? null,

            'calculated_at' =>
                $result['calculated_at']
                ?? now()->toISOString(),
        ];
    }

    protected function decimal(
        mixed $value
    ): string {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Expected a numeric decimal value.'
            );
        }

        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }
}