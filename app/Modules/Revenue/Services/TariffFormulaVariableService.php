<?php

namespace App\Modules\Revenue\Services;

use App\Models\TariffRule;
use App\Models\TariffFormulaVariable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TariffFormulaVariableService
{
  /**
     * Get all formula variables for a tariff rule.
     */
    public function index(TariffRule $tariffRule): Collection
    {
        return $tariffRule
            ->formulaVariables()
            ->with('baseField')
            ->get();
    }
    /**
     * Get a single variable.
     */
    public function show(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): TariffFormulaVariable {
        $this->ensureBelongsToTariffRule(
            $tariffRule,
            $formulaVariable
        );

        return $formulaVariable->load('baseField');
    }

    /**
     * Create a formula variable.
     */
    public function store(
        TariffRule $tariffRule,
        array $data
    ): TariffFormulaVariable {
        return DB::transaction(function () use (
            $tariffRule,
            $data
        ) {
            /*
            |--------------------------------------------------------------------------
            | Never trust client-provided code
            |--------------------------------------------------------------------------
            */

            unset($data['code']);

            /*
            |--------------------------------------------------------------------------
            | Assign parent tariff rule
            |--------------------------------------------------------------------------
            */

            $data['tariff_rule_id'] = $tariffRule->id;

            /*
            |--------------------------------------------------------------------------
            | Generate system code
            |--------------------------------------------------------------------------
            */

            $data['code'] = $this->generateCode(
                $tariffRule,
                $data['variable_name']
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize source-specific values
            |--------------------------------------------------------------------------
            */

            if ($data['source_type'] === 'BASE_FIELD') {
                $data['default_value'] = null;
            }

            if ($data['source_type'] === 'CONSTANT') {
                $data['base_field_id'] = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Automatically assign sort order
            |--------------------------------------------------------------------------
            */

            if (!isset($data['sort_order'])) {
                $data['sort_order'] = (
                    $tariffRule
                        ->formulaVariables()
                        ->max('sort_order') ?? -1
                ) + 1;
            }

            /*
            |--------------------------------------------------------------------------
            | Create variable
            |--------------------------------------------------------------------------
            */

            $variable = TariffFormulaVariable::create($data);

            return $variable->load('baseField');
        });
    }

    /**
     * Update a formula variable.
     */
    public function update(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable,
        array $data
    ): TariffFormulaVariable {
        $this->ensureBelongsToTariffRule(
            $tariffRule,
            $formulaVariable
        );

        return DB::transaction(function () use (
            $formulaVariable,
            $data
        ) {
            /*
            |--------------------------------------------------------------------------
            | Code is system-generated and immutable
            |--------------------------------------------------------------------------
            */

            unset($data['code']);

            /*
            |--------------------------------------------------------------------------
            | Normalize source-specific values
            |--------------------------------------------------------------------------
            */

            if (
                isset($data['source_type']) &&
                $data['source_type'] === 'BASE_FIELD'
            ) {
                $data['default_value'] = null;
            }

            if (
                isset($data['source_type']) &&
                $data['source_type'] === 'CONSTANT'
            ) {
                $data['base_field_id'] = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            $formulaVariable->update($data);

            return $formulaVariable
                ->refresh()
                ->load('baseField');
        });
    }

    /**
     * Delete a formula variable.
     */
    public function destroy(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): void {
        $this->ensureBelongsToTariffRule(
            $tariffRule,
            $formulaVariable
        );

        DB::transaction(function () use (
            $formulaVariable
        ) {
            $formulaVariable->delete();
        });
    }

    /**
     * Generate a unique code for a variable
     * within the current tariff rule.
     *
     * Example:
     *
     * land_area -> LAND_AREA
     * land area -> LAND_AREA
     * second land_area -> LAND_AREA_2
     */
    private function generateCode(
        TariffRule $tariffRule,
        string $variableName
    ): string {
        $baseCode = Str::upper(
            Str::slug(
                $variableName,
                '_'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        */

        if ($baseCode === '') {
            $baseCode = 'VARIABLE';
        }

        $code = $baseCode;
        $counter = 1;

        while (
            $tariffRule
                ->formulaVariables()
                ->where('code', $code)
                ->exists()
        ) {
            $counter++;

            $code = "{$baseCode}_{$counter}";
        }

        return $code;
    }

    /**
     * Ensure variable belongs to the tariff rule.
     */
    private function ensureBelongsToTariffRule(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): void {
        if (
            $formulaVariable->tariff_rule_id !==
            $tariffRule->id
        ) {
            abort(
                404,
                'Formula variable not found for this tariff rule.'
            );
        }
    }

    /**
     * Copy formula variables from one tariff rule to another.
     *
     * When overwrite is true, existing destination variables
     * are deleted before copying the source variables.
     */
    public function copy(
        TariffRule $sourceRule,
        TariffRule $destinationRule,
        bool $overwrite = false
    ): Collection {
        return DB::transaction(function () use (
            $sourceRule,
            $destinationRule,
            $overwrite
        ) {
            if ($sourceRule->id === $destinationRule->id) {
                throw ValidationException::withMessages([
                    'source_rule_id' => [
                        'Source and destination tariff rules must be different.',
                    ],
                ]);
            }

            $sourceVariables = $sourceRule
                ->formulaVariables()
                ->get();

            $destinationVariables = $destinationRule
                ->formulaVariables();

            /*
            |--------------------------------------------------------------------------
            | Check existing destination variables
            |--------------------------------------------------------------------------
            */

            if (!$overwrite && $destinationVariables->exists()) {
                throw ValidationException::withMessages([
                    'overwrite' => [
                        'The destination tariff rule already has formula variables. Enable overwrite to replace them.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Remove existing destination variables
            |--------------------------------------------------------------------------
            */

            if ($overwrite) {
                $destinationVariables->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | Copy source variables
            |--------------------------------------------------------------------------
            */

            foreach ($sourceVariables as $variable) {
                $destinationRule->formulaVariables()->create([
                    'id' => Str::uuid(),
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

            return $destinationRule
                ->formulaVariables()
                ->with('baseField')
                ->get();
        });
    }
}