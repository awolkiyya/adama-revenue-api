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
use App\Models\BaseField;

class TariffRuleController extends Controller
{
    public function __construct(
        protected TariffRuleService $service
    ) {
    }


/**
 * List tariff rules for a tariff version.
 */
public function index(
    Request $request,
    string $tariffVersionId
): JsonResponse {
    $perPage = $request->integer('per_page', 15);
    $perPage = max(1, min($perPage, 100));

    $rules = TariffRule::query()
        ->with([
            'tariffVersion',
            'service.revenueCode',
            'baseField',
            'measurementUnit',
            'creator',
            'updater',
        ])
        ->where('tariff_version_id', $tariffVersionId)
        ->when(
            $request->filled('service_id'),
            fn ($query) => $query->where(
                'service_id',
                $request->input('service_id')
            )
        )
        ->when(
            $request->filled('is_active'),
            fn ($query) => $query->where(
                'is_active',
                $request->boolean('is_active')
            )
        )
        ->orderBy('priority')
        ->orderBy('execution_order')
        ->paginate($perPage);

    /*
    |--------------------------------------------------------------------------
    | Resolve Condition Field Metadata
    |--------------------------------------------------------------------------
    |
    | Conditions store field IDs in JSON. Resolve all unique IDs with one
    | query rather than querying separately for every condition.
    |
    */

    $conditionFieldIds = $rules->getCollection()
        ->flatMap(function (TariffRule $rule) {
            $conditions = $rule->conditions;

            if (is_string($conditions)) {
                $conditions = json_decode($conditions, true);
            }

            if (! is_array($conditions)) {
                return [];
            }

            return collect($conditions)
                ->filter(fn ($condition) => is_array($condition))
                ->pluck('fieldId');
        })
        ->filter(fn ($id) => is_string($id) && $id !== '')
        ->unique()
        ->values();

    $resolvedConditionFields = $conditionFieldIds->isEmpty()
        ? collect()
        : BaseField::query()
            ->whereIn('id', $conditionFieldIds)
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

    /*
    |--------------------------------------------------------------------------
    | Attach Resolved Fields
    |--------------------------------------------------------------------------
    */

    $rules->getCollection()->each(
        function (TariffRule $rule) use ($resolvedConditionFields) {
            $rule->setAttribute(
                'resolvedConditionFields',
                $resolvedConditionFields
            );
        }
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
 * Show tariff rule.
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
    ]);

    /*
    |--------------------------------------------------------------------------
    | Resolve Condition Field Metadata
    |--------------------------------------------------------------------------
    |
    | Conditions store field IDs in JSON. Resolve all referenced fields
    | in a single query, consistent with the index() method.
    |
    */

    $conditions = $tariffRule->conditions;

    if (is_string($conditions)) {
        $conditions = json_decode($conditions, true);
    }

    $conditionFieldIds = collect(is_array($conditions) ? $conditions : [])
        ->filter(fn ($condition) => is_array($condition))
        ->pluck('fieldId')
        ->filter(fn ($id) => is_string($id) && $id !== '')
        ->unique()
        ->values();

    $resolvedConditionFields = $conditionFieldIds->isEmpty()
        ? collect()
        : BaseField::query()
            ->whereIn('id', $conditionFieldIds)
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

    /*
    |--------------------------------------------------------------------------
    | Attach Resolved Fields
    |--------------------------------------------------------------------------
    */

    $tariffRule->setAttribute(
        'resolvedConditionFields',
        $resolvedConditionFields
    );

    return ApiResponse::success(
        new TariffRuleResource($tariffRule),
        'Tariff rule retrieved successfully'
    );
}

    public function update(
        UpdateTariffRuleRequest $request,
        string $tariff_version,
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



   
        /**
         * Copy formula variables from one tariff rule to another.
         */
        public function copyFormula(
            Request $request,
            TariffVersion $tariffVersion,
            TariffRule $destinationRule
        ): JsonResponse {
            $validated = $request->validate([
                'source_rule_id' => [
                    'required',
                    'uuid',
                    'exists:tariff_rules,id',
                ],

                'options' => [
                    'required',
                    'array',
                ],

                'options.variables' => [
                    'required',
                    'accepted',
                ],
            ]);

            $rule = $this->service->copyFormula(
                $tariffVersion,
                $destinationRule,
                $validated
            );

            return ApiResponse::updated(
                new TariffRuleResource($rule),
                'Formula variables copied successfully.'
            );
        }


}