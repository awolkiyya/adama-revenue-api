<?php

namespace App\Modules\Revenue\Services;

use App\Models\TariffRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TariffRuleService
{
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

            return $rule->load([
                'tariffVersion',
                'service',
                'baseField',
                'measurementUnit',
                'creator',
                'updater',
            ]);
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

        return $rule->fresh([
            'tariffVersion',
            'service',
            'baseField',
            'measurementUnit',
            'creator',
            'updater',
        ]);
    }

    /**
     * Delete a tariff rule.
     */
    public function delete(TariffRule $rule): void
    {
        $rule->delete();
    }

    /**
     * Generate rule code.
     */
    protected function generateCode(): string
    {
        do {

            $code = 'TR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

        } while (TariffRule::where('code', $code)->exists());

        return $code;
    }
}