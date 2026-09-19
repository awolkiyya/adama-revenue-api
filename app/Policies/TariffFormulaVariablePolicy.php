<?php

namespace App\Policies;

use App\Models\TariffFormulaVariable;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;

class TariffFormulaVariablePolicy
{
    use ChecksHierarchy;

    /**
     * View any tariff formula variables.
     *
     * Permission:
     *     tariff_formula.view
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'tariff_formula.view'
        );
    }

    /**
     * View a tariff formula variable.
     *
     * Permission:
     *     tariff_formula.view
     */
    public function view(
        User $user,
        TariffFormulaVariable $formulaVariable
    ): bool {
        return $this->hasPermission(
            $user,
            'tariff_formula.view'
        );
    }

    /**
     * Create a tariff formula variable.
     *
     * Permission:
     *     tariff_formula.create
     */
    public function create(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'tariff_formula.create'
        );
    }

    /**
     * Update a tariff formula variable.
     *
     * Permission:
     *     tariff_formula.update
     */
    public function update(
        User $user,
        TariffFormulaVariable $formulaVariable
    ): bool {
        return $this->hasPermission(
            $user,
            'tariff_formula.update'
        );
    }

    /**
     * Delete a tariff formula variable.
     *
     * Permission:
     *     tariff_formula.delete
     */
    public function delete(
        User $user,
        TariffFormulaVariable $formulaVariable
    ): bool {
        return $this->hasPermission(
            $user,
            'tariff_formula.delete'
        );
    }
}