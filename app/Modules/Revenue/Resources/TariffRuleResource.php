<?php

namespace App\Modules\Revenue\Resources;

use App\Modules\Users\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class TariffRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'code' => $this->code,

            'name' => $this->name,

            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            'tariffVersionId' => $this->tariff_version_id,

            'serviceId' => $this->service_id,

            'baseFieldId' => $this->base_field_id,

            'measurementUnitId' => $this->measurement_unit_id,

            'tariffVersion' => new TariffVersionResource(
                $this->whenLoaded('tariffVersion')
            ),

            'service' => new RevenueServiceResource(
                $this->whenLoaded('service')
            ),

            'baseField' => new BaseFieldResource(
                $this->whenLoaded('baseField')
            ),

            'measurementUnit' => new MeasurementUnitResource(
                $this->whenLoaded('measurementUnit')
            ),

            /*
            |--------------------------------------------------------------------------
            | Calculation
            |--------------------------------------------------------------------------
            */

            'calculationType' => $this->calculation_type,

            'priority' => $this->priority,

            'executionOrder' => $this->execution_order,

            'minValue' => $this->min_value,

            'maxValue' => $this->max_value,

            'amount' => $this->amount,

            'percentage' => $this->percentage,

            'minimumAmount' => $this->minimum_amount,

            'maximumAmount' => $this->maximum_amount,

            'formula' => $this->formula,

            'conditions' => $this->formatConditions(),

            'roundingRule' => $this->rounding_rule,

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'isActive' => $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'createdBy' => new UserResource(
                $this->whenLoaded('creator')
            ),

            'updatedBy' => new UserResource(
                $this->whenLoaded('updater')
            ),

            'createdAt' => $this->created_at,

            'updatedAt' => $this->updated_at,

            'deletedAt' => $this->deleted_at,

            /*
            |--------------------------------------------------------------------------
            | Sibling Rules (same tariff version)
            |--------------------------------------------------------------------------
            |
            | Used for frontend validation:
            | - range overlap detection
            | - priority conflicts
            | - execution order conflicts
            |
            */

            'siblingRules' => $this->whenLoaded(
                'relatedRules',
                function () {
                    return $this->relatedRules
                        ->where('id', '!=', $this->id)
                        ->map(function ($rule) {
                            return [
                                'id' => $rule->id,

                                'name' => $rule->name,

                                'priority' => $rule->priority,

                                'executionOrder' => $rule->execution_order,
                            ];
                        })
                        ->values();
                }
            ),
        ];
    }

    /**
     * Format tariff rule conditions with resolved base-field metadata.
     *
     * The controller or service must attach a collection of BaseField
     * records to the model's resolvedConditionFields attribute.
     *
     * This method intentionally performs no database queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatConditions(): array
    {
        $conditions = $this->conditions;

        if (is_string($conditions)) {
            $conditions = json_decode($conditions, true);
        }

        if (! is_array($conditions)) {
            return [];
        }

        $resolvedFields = $this->resource->resolvedConditionFields ?? [];

        if ($resolvedFields instanceof Collection) {
            $fieldsById = $resolvedFields->keyBy('id');
        } elseif (is_array($resolvedFields)) {
            $fieldsById = collect($resolvedFields)->keyBy('id');
        } else {
            $fieldsById = collect();
        }

        return collect($conditions)
            ->filter(fn ($condition) => is_array($condition))
            ->map(function (array $condition) use ($fieldsById): array {
                $fieldId = $condition['fieldId'] ?? null;

                $field = $fieldId !== null
                    ? $fieldsById->get($fieldId)
                    : null;

                return [
                    'fieldId' => $fieldId,

                    'fieldName' => data_get($field, 'name')
                        ?? $condition['fieldName']
                        ?? null,

                    'fieldCode' => data_get($field, 'code')
                        ?? $condition['fieldCode']
                        ?? null,

                    'operator' => $condition['operator'] ?? null,

                    'value' => $condition['value'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
