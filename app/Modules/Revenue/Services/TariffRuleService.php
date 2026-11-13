<?php

namespace App\Modules\Revenue\Services;

use App\Models\TariffRule;
use App\Models\TariffVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TariffRuleService
{
    public function __construct(
        protected TariffFormulaVariableService $formulaVariableService
    ) {}

    /**
     * Create a tariff rule.
     */
    public function create(array $data): TariffRule
    {
        return DB::transaction(function () use ($data) {
            $rule = TariffRule::create([
                'tariff_version_id' => $data['tariff_version_id'],
                'service_id' => $data['service_id'],
                'code' => $this->generateCode(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'calculation_type' => $data['calculation_type'],
                'base_field_id' => $data['base_field_id'] ?? null,
                'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                'priority' => $data['priority'],
                'execution_order' => $data['execution_order'],
                'min_value' => $data['min_value'] ?? null,
                'max_value' => $data['max_value'] ?? null,
                'amount' => $data['amount'] ?? null,
                'percentage' => $data['percentage'] ?? null,
                'minimum_amount' => $data['minimum_amount'] ?? null,
                'maximum_amount' => $data['maximum_amount'] ?? null,
                'formula' => $data['formula'] ?? null,
                'conditions' => $data['conditions'] ?? [],
                'rounding_rule' => $data['rounding_rule'] ?? 'NONE',
                'is_active' => $data['is_active'] ?? true,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return $rule->load($this->defaultRelations());
        });
    }

    /**
     * Update a tariff rule.
     */
    public function update(TariffRule $rule, array $data): TariffRule
    {
        DB::transaction(function () use ($rule, $data) {
            $rule->update([
                'tariff_version_id' => $data['tariff_version_id'],
                'service_id' => $data['service_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'calculation_type' => $data['calculation_type'],
                'base_field_id' => $data['base_field_id'] ?? null,
                'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                'priority' => $data['priority'],
                'execution_order' => $data['execution_order'],
                'min_value' => $data['min_value'] ?? null,
                'max_value' => $data['max_value'] ?? null,
                'amount' => $data['amount'] ?? null,
                'percentage' => $data['percentage'] ?? null,
                'minimum_amount' => $data['minimum_amount'] ?? null,
                'maximum_amount' => $data['maximum_amount'] ?? null,
                'formula' => $data['formula'] ?? null,
                'conditions' => $data['conditions'] ?? [],
                'rounding_rule' => $data['rounding_rule'] ?? 'NONE',
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => Auth::id(),
            ]);
        });

        return $rule->fresh($this->defaultRelations());
    }

    /**
     * Copy selected formula configuration from a source rule
     * to a destination rule within the same tariff version.
     *
     * Supported options:
     * - variables
     * - expression
     * - conditions
     */
    public function copyFormula(
        TariffVersion $tariffVersion,
        TariffRule $destinationRule,
        array $data
    ): TariffRule {
        $options = $data['options'] ?? [];

        $copyVariables = (bool) ($options['variables'] ?? false);
        $copyExpression = (bool) ($options['expression'] ?? false);
        $copyConditions = (bool) ($options['conditions'] ?? false);

        $overwrite = (bool) ($data['overwrite'] ?? false);

        /*
        |--------------------------------------------------------------------------
        | Validate selected options
        |--------------------------------------------------------------------------
        */

        if (! $copyVariables && ! $copyExpression && ! $copyConditions) {
            throw ValidationException::withMessages([
                'options' => [
                    'Select at least one formula configuration option to copy.',
                ],
            ]);
        }

        $sourceRuleId = $data['source_rule_id'] ?? null;

        if (! $sourceRuleId) {
            throw ValidationException::withMessages([
                'source_rule_id' => [
                    'A source tariff rule is required.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $tariffVersion,
            $destinationRule,
            $sourceRuleId,
            $copyVariables,
            $copyExpression,
            $copyConditions,
            $overwrite
        ) {
            /*
            |--------------------------------------------------------------------------
            | Retrieve and lock destination rule
            |--------------------------------------------------------------------------
            */

            $destination = TariffRule::query()
                ->whereKey($destinationRule->getKey())
                ->where('tariff_version_id', $tariffVersion->getKey())
                ->lockForUpdate()
                ->first();

            if (! $destination) {
                throw ValidationException::withMessages([
                    'destination_rule_id' => [
                        'The destination rule does not belong to this tariff version.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Retrieve and lock source rule
            |--------------------------------------------------------------------------
            */

            $source = TariffRule::query()
                ->whereKey($sourceRuleId)
                ->where('tariff_version_id', $tariffVersion->getKey())
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw ValidationException::withMessages([
                    'source_rule_id' => [
                        'The source rule does not belong to this tariff version.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent copying a rule onto itself
            |--------------------------------------------------------------------------
            */

            if ($source->is($destination)) {
                throw ValidationException::withMessages([
                    'destination_rule_id' => [
                        'You cannot copy formula configuration to the same rule.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate calculation types
            |--------------------------------------------------------------------------
            */

            if (
                strtoupper((string) $source->calculation_type) !== 'FORMULA'
                || strtoupper((string) $destination->calculation_type) !== 'FORMULA'
            ) {
                throw ValidationException::withMessages([
                    'calculation_type' => [
                        'Both source and destination rules must use FORMULA calculation type.',
                    ],
                ]);
            }

            $updates = [];

            /*
            |--------------------------------------------------------------------------
            | Copy formula expression
            |--------------------------------------------------------------------------
            */

            if ($copyExpression) {
                $destinationHasFormula = ! empty(
                    trim((string) $destination->formula)
                );

                if ($destinationHasFormula && ! $overwrite) {
                    throw ValidationException::withMessages([
                        'overwrite' => [
                            'The destination already has a formula expression. '
                            . 'Enable overwrite to replace it.',
                        ],
                    ]);
                }

                $updates['formula'] = $source->formula;
            }

            /*
            |--------------------------------------------------------------------------
            | Copy conditions
            |--------------------------------------------------------------------------
            */

            if ($copyConditions) {
                $destinationConditions = $destination->conditions ?? [];

                if (! empty($destinationConditions) && ! $overwrite) {
                    throw ValidationException::withMessages([
                        'overwrite' => [
                            'The destination already has conditions. '
                            . 'Enable overwrite to replace them.',
                        ],
                    ]);
                }

                $updates['conditions'] = $source->conditions ?? [];
            }

            /*
            |--------------------------------------------------------------------------
            | Update destination rule fields
            |--------------------------------------------------------------------------
            */

            if (! empty($updates) || $copyVariables) {
                $updates['updated_by'] = Auth::id();

                $destination->update($updates);
            }

            /*
            |--------------------------------------------------------------------------
            | Copy formula variables
            |--------------------------------------------------------------------------
            */

            if ($copyVariables) {
                $this->formulaVariableService->copy(
                    $source,
                    $destination,
                    $overwrite
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Return updated destination rule
            |--------------------------------------------------------------------------
            */

            return $destination->fresh($this->defaultRelations());
        });
    }

    /**
     * Delete a tariff rule.
     */
    public function delete(TariffRule $rule): void
    {
        $rule->delete();
    }

    /**
     * Default relationships loaded for tariff rules.
     */
    protected function defaultRelations(): array
    {
        return [
            'tariffVersion',
            'service',
            'baseField',
            'measurementUnit',
            'creator',
            'updater',
        ];
    }

    /**
     * Generate a unique tariff rule code.
     */
    protected function generateCode(): string
    {
        do {
            $code = 'TR-' . now()->format('Ymd') . '-'
                . strtoupper(Str::random(6));
        } while (TariffRule::where('code', $code)->exists());

        return $code;
    }
}
