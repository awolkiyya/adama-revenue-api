<?php

namespace App\Modules\Revenue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Resources\TariffRuleResource;
use App\Models\TariffRule;
use App\Models\TariffVersion;
use App\Modules\Revenue\Requests\StoreTariffRuleRequest;
use App\Modules\Revenue\Requests\UpdateTariffRuleRequest;
use App\Modules\Revenue\Services\TariffRuleService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TariffRuleController extends Controller
{
    public function __construct(
        protected TariffRuleService $service
    ) {
    }


    /**
     * List tariff rules
     */
    public function index(Request $request): JsonResponse
    {
        $rules = TariffRule::query()
            ->with([
                'tariffVersion',
                'service.revenueCode',   // nest it under service
                'baseField',
                'measurementUnit',
                'creator',
                'updater',
            ])

            ->when(
                $request->filled('tariff_version_id'),
                fn ($query) =>
                    $query->where(
                        'tariff_version_id',
                        $request->tariff_version_id
                    )
            )

            ->when(
                $request->filled('service_id'),
                fn ($query) =>
                    $query->where(
                        'service_id',
                        $request->service_id
                    )
            )

            ->when(
                $request->filled('is_active'),
                fn ($query) =>
                    $query->where(
                        'is_active',
                        $request->boolean('is_active')
                    )
            )

            ->orderBy('priority')
            ->orderBy('execution_order')
            ->paginate(
                $request->integer('per_page', 15)
            );

        return ApiResponse::success(
            TariffRuleResource::collection($rules),
            'Tariff rules retrieved successfully'
        );
    }



    /**
     * Store tariff rule
     */
    public function store(
        StoreTariffRuleRequest $request
    ): JsonResponse {

        $rule = $this->service->create(
            $request->validated()
        );


        return ApiResponse::created(
            new TariffRuleResource($rule),
            'Tariff rule created successfully'
        );
    }



    /**
     * Show tariff rule
     */
    public function show(
        TariffVersion $tariffVersion,
        TariffRule $tariffRule
    ): JsonResponse {
    
        $tariffRule->load([
            'tariffVersion',
            'service.revenueCode',
            'baseField',
            'measurementUnit',
            'creator',
            'updater',
            'relatedRules',
            // 'formulaVariables.baseField',
        ]);
    
    
        return ApiResponse::success(
            new TariffRuleResource($tariffRule),
            'Tariff rule retrieved successfully'
        );
    }



    /**
     * Update tariff rule
     */
    public function update(
        UpdateTariffRuleRequest $request,
        TariffRule $tariffRule
    ): JsonResponse {

        $rule = $this->service->update(
            $tariffRule,
            $request->validated()
        );


        return ApiResponse::updated(
            new TariffRuleResource($rule),
            'Tariff rule updated successfully'
        );
    }



    /**
     * Delete tariff rule
     */
    public function destroy(
        TariffRule $tariffRule
    ): JsonResponse {

        $this->service->delete($tariffRule);


        return ApiResponse::deleted(
            'Tariff rule deleted successfully'
        );
    }



    /**
     * Activate tariff rule
     */
    public function activate(
        TariffRule $tariffRule
    ): JsonResponse {

        $tariffRule->update([
            'is_active' => true,
        ]);


        return ApiResponse::updated(
            new TariffRuleResource(
                $tariffRule->fresh()
            ),
            'Tariff rule activated successfully'
        );
    }



    /**
     * Deactivate tariff rule
     */
    public function deactivate(
        TariffRule $tariffRule
    ): JsonResponse {

        $tariffRule->update([
            'is_active' => false,
        ]);


        return ApiResponse::updated(
            new TariffRuleResource(
                $tariffRule->fresh()
            ),
            'Tariff rule deactivated successfully'
        );
    }
}