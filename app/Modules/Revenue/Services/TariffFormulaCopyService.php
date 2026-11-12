<?php

namespace App\Modules\Tariff\Services;

use App\Models\TariffFormulaVariable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TariffFormulaCopyService
{
    public function copyVariables(
        string $sourceRuleId,
        string $destinationRuleId
    ): void {

        DB::transaction(function () use (
            $sourceRuleId,
            $destinationRuleId
        ) {

            $variables = TariffFormulaVariable::query()
                ->where('tariff_rule_id', $sourceRuleId)
                ->get();

            foreach ($variables as $variable) {

                TariffFormulaVariable::create([
                    'id' => Str::uuid(),

                    'tariff_rule_id' => $destinationRuleId,

                    'code' => $variable->code,

                    'variable_name' => $variable->variable_name,

                    'label' => $variable->label,

                    'source_type' => $variable->source_type,

                    'base_field_id' => $variable->base_field_id,

                    'default_value' => $variable->default_value,

                    'data_type' => $variable->data_type,

                    'is_required' => $variable->is_required,

                    'sort_order' => $variable->sort_order,
                ]);
            }

        });
    }
}