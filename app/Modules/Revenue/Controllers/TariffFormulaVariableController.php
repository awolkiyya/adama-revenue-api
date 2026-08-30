<?php

namespace App\Modules\Revenue\Controllers;
use App\Http\Controllers\Controller;


use App\Modules\Revenue\Requests\TariffFormulaVariableRequest;
use App\Modules\Revenue\Resources\TariffFormulaVariableResource;
use App\Models\TariffFormulaVariable;
use App\Models\TariffRule;
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
     */
    public function index(
        TariffRule $tariffRule
    ): JsonResponse {
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
     */
    public function show(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
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
     */
    public function store(
        TariffFormulaVariableRequest $request,
        TariffRule $tariffRule
    ): JsonResponse {
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
     */
    public function update(
        TariffFormulaVariableRequest $request,
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
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
     */
    public function destroy(
        TariffRule $tariffRule,
        TariffFormulaVariable $formulaVariable
    ): JsonResponse {
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