<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TariffFormulaVariable;
use App\Models\TariffRule;
use App\Modules\Revenue\Requests\TariffFormulaVariableRequest;
use App\Modules\Revenue\Resources\TariffFormulaVariableResource;
use App\Modules\Revenue\Services\TariffFormulaVariableService;
use Illuminate\Http\JsonResponse;

class TariffFormulaVariableController extends Controller
{
    public function __construct(
        protected TariffFormulaVariableService $service
    ) {
    }

    /**
     * List formula variables.
     *
     * Permission:
     *     tariff_formula.view
     */
    public function index(
        TariffRule $tariffRule
    ): JsonResponse {
        $this->authorize(
            'viewAny',
            TariffFormulaVariable::class
        );

        $variables = $this->service->index($tariffRule);

        return response()->json([
            'success' => true,
            'message' => 'Tariff formula variables retrieved successfully.',
            'data' => TariffFormulaVariableResource::collection(
                $variables
            ),
        ]);
    }

    /**
     * Show formula variable.
     *
     * Permission:
     *     tariff_formula.view
     */
    public function show(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
        $this->authorize(
            'view',
            $formulaVariable
        );

        $variable = $this->service->show(
            $tariffRule,
            $formulaVariable
        );

        return response()->json([
            'success' => true,
            'message' => 'Tariff formula variable retrieved successfully.',
            'data' => new TariffFormulaVariableResource(
                $variable
            ),
        ]);
    }

    /**
     * Create formula variable.
     *
     * Permission:
     *     tariff_formula.create
     */
    public function store(
        TariffFormulaVariableRequest $request,
        TariffRule $tariffRule
    ): JsonResponse {
        $this->authorize(
            'create',
            TariffFormulaVariable::class
        );

        $variable = $this->service->store(
            $tariffRule,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tariff formula variable created successfully.',
            'data' => new TariffFormulaVariableResource(
                $variable
            ),
        ], 201);
    }

    /**
     * Update formula variable.
     *
     * Permission:
     *     tariff_formula.update
     */
    public function update(
        TariffFormulaVariableRequest $request,
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
        $this->authorize(
            'update',
            $formulaVariable
        );

        $variable = $this->service->update(
            $tariffRule,
            $formulaVariable,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tariff formula variable updated successfully.',
            'data' => new TariffFormulaVariableResource(
                $variable
            ),
        ]);
    }

    /**
     * Delete formula variable.
     *
     * Permission:
     *     tariff_formula.delete
     */
    public function destroy(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
        $this->authorize(
            'delete',
            $formulaVariable
        );

        $this->service->destroy(
            $tariffRule,
            $formulaVariable
        );

        return response()->json([
            'success' => true,
            'message' => 'Tariff formula variable deleted successfully.',
            'data' => null,
        ]);
    }
}