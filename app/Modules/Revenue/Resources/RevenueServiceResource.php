<?php

namespace App\Modules\Revenue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueServiceResource extends JsonResource
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

            'id' => (string) $this->id,


            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            */

            'revenueCodeId' => (string) $this->revenue_code_id,


            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */

            'name' => $this->name,

            'description' => $this->description,


            /*
            |--------------------------------------------------------------------------
            | Service Configuration
            |--------------------------------------------------------------------------
            */

            'serviceType' => $this->service_type,

            'collectionMode' => $this->collection_mode,


            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            */

            'isActive' => (bool) $this->is_active,

            'status' => $this->is_active
                ? 'ACTIVE'
                : 'INACTIVE',


            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            |
            | Includes the parent RevenueCode.
            |
            | RevenueService
            |      ↓
            | RevenueCode
            |      ↓
            | RevenueCategory
            |
            | IMPORTANT:
            | We intentionally do NOT return the full
            | RevenueCodeResource here to prevent recursion.
            |
            */

            'revenueCode' => $this->whenLoaded(
                'revenueCode',
                function () {

                    $code = $this->revenueCode;

                    return [

                        /*
                        |--------------------------------------------------------------------------
                        | Code Identity
                        |--------------------------------------------------------------------------
                        */

                        'id' => (string) $code->id,

                        'code' => $code->code,

                        'name' => $code->name,

                        'description' => $code->description,


                        /*
                        |--------------------------------------------------------------------------
                        | Code Status
                        |--------------------------------------------------------------------------
                        */

                        'isActive' => (bool) $code->is_active,

                        'status' => $code->is_active
                            ? 'ACTIVE'
                            : 'INACTIVE',


                        /*
                        |--------------------------------------------------------------------------
                        | Revenue Category
                        |--------------------------------------------------------------------------
                        |
                        | RevenueCode
                        |      ↓
                        | RevenueCategory
                        |
                        */

                        'category' => $code->relationLoaded('category')
                            && $code->category
                            ? [

                                /*
                                |--------------------------------------------------------------------------
                                | Category Identity
                                |--------------------------------------------------------------------------
                                */

                                'id' => (string) $code->category->id,


                                /*
                                |--------------------------------------------------------------------------
                                | Revenue Domain
                                |--------------------------------------------------------------------------
                                */

                                'revenueDomain' =>
                                    $code->category->revenue_domain,


                                /*
                                |--------------------------------------------------------------------------
                                | Category Information
                                |--------------------------------------------------------------------------
                                */

                                'name' =>
                                    $code->category->name,

                                'description' =>
                                    $code->category->description,


                                /*
                                |--------------------------------------------------------------------------
                                | Revenue Code Range
                                |--------------------------------------------------------------------------
                                */

                                'startCode' =>
                                    $code->category->start_code,

                                'endCode' =>
                                    $code->category->end_code,


                                /*
                                |--------------------------------------------------------------------------
                                | Ordering
                                |--------------------------------------------------------------------------
                                */

                                'sortOrder' =>
                                    $code->category->sort_order,


                                /*
                                |--------------------------------------------------------------------------
                                | Status
                                |--------------------------------------------------------------------------
                                */

                                'isActive' =>
                                    (bool) $code->category->is_active,

                                'status' =>
                                    $code->category->is_active
                                        ? 'ACTIVE'
                                        : 'INACTIVE',

                            ]
                            : null,
                    ];
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | Configured Revenue Service Fields
            |--------------------------------------------------------------------------
            |
            | These are NOT BaseFields themselves.
            |
            | Each record represents:
            |
            | RevenueService
            |      ↓
            | RevenueServiceField
            |      ↓
            | BaseField
            |
            */

            'fields' => $this->whenLoaded(
                'fields',
                function () {

                    return $this->fields
                        ->sortBy('sort_order')
                        ->values()
                        ->map(
                            function ($field) {

                                return [

                                    /*
                                    |--------------------------------------------------------------------------
                                    | Revenue Service Field
                                    |--------------------------------------------------------------------------
                                    */

                                    'id' =>
                                        (string) $field->id,

                                    'revenueServiceId' =>
                                        (string) $field->service_id,

                                    'baseFieldId' =>
                                        (string) $field->base_field_id,


                                    /*
                                    |--------------------------------------------------------------------------
                                    | Service-Specific Configuration
                                    |--------------------------------------------------------------------------
                                    */

                                    'label' =>
                                        $field->label,

                                    'helpText' =>
                                        $field->help_text,

                                    'validationRules' =>
                                        $field->validation_rules,

                                    'isRequired' =>
                                        (bool) $field->is_required,

                                    'sortOrder' =>
                                        $field->sort_order,

                                    'isActive' =>
                                        (bool) $field->is_active,

                                    'status' =>
                                        $field->is_active
                                            ? 'ACTIVE'
                                            : 'INACTIVE',


                                    /*
                                    |--------------------------------------------------------------------------
                                    | Canonical Base Field
                                    |--------------------------------------------------------------------------
                                    */

                                    'baseField' =>
                                        $field->relationLoaded('baseField')
                                            && $field->baseField
                                            ? [

                                                /*
                                                |--------------------------------------------------------------------------
                                                | Base Field Identity
                                                |--------------------------------------------------------------------------
                                                */

                                                'id' =>
                                                    (string) $field->baseField->id,

                                                'code' =>
                                                    $field->baseField->code,

                                                'name' =>
                                                    $field->baseField->name,

                                                'description' =>
                                                    $field->baseField->description,


                                                /*
                                                |--------------------------------------------------------------------------
                                                | Base Field Configuration
                                                |--------------------------------------------------------------------------
                                                */

                                                'measurementUnitId' =>
                                                    $field->baseField->measurement_unit_id,

                                                'dataType' =>
                                                    $field->baseField->data_type,

                                                'isActive' =>
                                                    (bool) $field->baseField->is_active,

                                                'status' =>
                                                    $field->baseField->is_active
                                                        ? 'ACTIVE'
                                                        : 'INACTIVE',


                                                /*
                                                |--------------------------------------------------------------------------
                                                | Measurement Unit
                                                |--------------------------------------------------------------------------
                                                */

                                                'measurementUnit' =>
                                                    $field->baseField
                                                        ->relationLoaded(
                                                            'measurementUnit'
                                                        )
                                                        && $field->baseField->measurementUnit
                                                            ? [

                                                                'id' =>
                                                                    (string) $field->baseField
                                                                        ->measurementUnit->id,

                                                                'code' =>
                                                                    $field->baseField
                                                                        ->measurementUnit->code,

                                                                'name' =>
                                                                    $field->baseField
                                                                        ->measurementUnit->name,

                                                                'symbol' =>
                                                                    $field->baseField
                                                                        ->measurementUnit->symbol,

                                                            ]
                                                            : null,


                                                /*
                                                |--------------------------------------------------------------------------
                                                | Select / Radio / Checkbox Options
                                                |--------------------------------------------------------------------------
                                                */

                                                'options' =>
                                                    $field->baseField
                                                        ->relationLoaded('options')
                                                        ? $field->baseField
                                                            ->options
                                                            ->sortBy('sort_order')
                                                            ->values()
                                                            ->map(
                                                                function ($option) {

                                                                    return [

                                                                        'id' =>
                                                                            (string) $option->id,

                                                                        'baseFieldId' =>
                                                                            (string) $option->base_field_id,

                                                                        'value' =>
                                                                            $option->value,

                                                                        'label' =>
                                                                            $option->label,

                                                                        'sortOrder' =>
                                                                            $option->sort_order,

                                                                        'isActive' =>
                                                                            (bool) $option->is_active,

                                                                        'status' =>
                                                                            $option->is_active
                                                                                ? 'ACTIVE'
                                                                                : 'INACTIVE',

                                                                    ];
                                                                }
                                                            )
                                                            ->all()
                                                        : [],

                                            ]
                                            : null,

                                ];
                            }
                        )
                        ->all();
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | Fields Count
            |--------------------------------------------------------------------------
            */

            'fieldsCount' => $this->when(
                isset($this->fields_count),
                fn () => (int) $this->fields_count
            ),


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'createdBy' => $this->created_by
                ? (string) $this->created_by
                : null,

            'updatedBy' => $this->updated_by
                ? (string) $this->updated_by
                : null,

            'createdAt' =>
                $this->created_at?->toIso8601String(),

            'updatedAt' =>
                $this->updated_at?->toIso8601String(),

        ];
    }
}