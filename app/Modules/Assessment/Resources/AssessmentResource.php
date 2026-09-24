<?php

namespace App\Modules\Assessment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {

        return [

            /*
            |--------------------------------------------------------------------------
            | Core
            |--------------------------------------------------------------------------
            */

            'id' =>
                $this->id,

            'assessmentNumber' =>
                $this->assessment_number,

            'citizenId' =>
                $this->citizen_id,

            /*
            |--------------------------------------------------------------------------
            | Assessment Source
            |--------------------------------------------------------------------------
            |
            | NEW
            | EXISTING_LIZZ
            |
            */

            'sourceType' =>
                $this->source_type,

            'assessmentDate' =>
                optional(
                    $this->assessment_date
                )->format('Y-m-d'),

            'status' =>
                $this->status,

            'notes' =>
                $this->notes,

            /*
            |--------------------------------------------------------------------------
            | Submission
            |--------------------------------------------------------------------------
            */

            'submittedAt' =>
                optional(
                    $this->submitted_at
                )->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Decision
            |--------------------------------------------------------------------------
            */

            'decision' =>
                $this->decision,

            'decisionNotes' =>
                $this->decision_notes,

            'decidedBy' =>
                $this->decided_by,

            'decidedAt' =>
                optional(
                    $this->decided_at
                )->toISOString(),

            'approvedAt' =>
                optional(
                    $this->approved_at
                )->toISOString(),

            'rejectedAt' =>
                optional(
                    $this->rejected_at
                )->toISOString(),

            'decisionMetadata' =>
                $this->decision_metadata,

            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            | Kept as a raw id for back-compat / filtering, PLUS a resolved object
            | when the relation is actually eager-loaded.
            |
            | Controllers that need the name/level/breadcrumb in the response
            | must ->with('administrativeUnit') and its parent chain.
            */

            'administrativeUnitId' =>
                $this->administrative_unit_id,

            'administrativeUnit' =>
                $this->whenLoaded(
                    'administrativeUnit',
                    fn () => $this->transformAdministrativeUnit(
                        $this->administrativeUnit
                    )
                ),

            /*
            |--------------------------------------------------------------------------
            | Taxpayer / Citizen
            |--------------------------------------------------------------------------
            */

            'taxpayer' =>
                $this->whenLoaded(
                    'taxpayer',
                    function () {

                        return [
                            'id' =>
                                $this->taxpayer->id,

                            'citizenUid' =>
                                $this->taxpayer->citizen_uid,

                            'fullName' =>
                                $this->taxpayer->full_name,

                            'nationalId' =>
                                $this->taxpayer->national_id,

                            'phone' =>
                                $this->taxpayer->phone,

                            'email' =>
                                $this->taxpayer->email,

                            'gender' =>
                                $this->taxpayer->gender,

                            'dateOfBirth' =>
                                optional(
                                    $this->taxpayer->date_of_birth
                                )->format('Y-m-d'),

                            'address' =>
                                $this->taxpayer->address,

                            'isActive' =>
                                $this->taxpayer->is_active,
                        ];
                    }
                ),

            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            */

            'services' =>
                $this->whenLoaded(
                    'services',
                    function () {

                        return $this->services
                            ->map(
                                function ($service) {

                                    return [

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Assessment Service
                                        |--------------------------------------------------------------------------
                                        */

                                        'id' =>
                                            $service->id,

                                        'serviceId' =>
                                            $service->service_id,

                                        'serviceCode' =>
                                            $service->service_code,

                                        'serviceOrder' =>
                                            $service->service_order,

                                        'status' =>
                                            $service->status,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Calculation
                                        |--------------------------------------------------------------------------
                                        */

                                        'computedAmount' =>
                                            $service->computed_amount,

                                        'currencyCode' =>
                                            $service->currency_code,

                                        'calculationMetadata' =>
                                            $service->calculation_metadata,

                                        'calculationError' =>
                                            $service->calculation_error,

                                        'calculatedAt' =>
                                            optional(
                                                $service->calculated_at
                                            )->toISOString(),

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Historical Financial Position
                                        |--------------------------------------------------------------------------
                                        |
                                        | Used by Existing LIZZ.
                                        |
                                        | computedAmount
                                        |     = Original Obligation
                                        |
                                        | paidAmount
                                        |     = Amount Already Paid
                                        |
                                        | remainingAmount
                                        |     = Outstanding Historical Balance
                                        |
                                        */

                                        'paidAmount' =>
                                            $service->paid_amount,

                                        'remainingAmount' =>
                                            $service->remaining_amount,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Payment Tracking
                                        |--------------------------------------------------------------------------
                                        |
                                        | This is kept separate from paidAmount.
                                        |
                                        */

                                        'paymentStatus' =>
                                            $service->payment_status,

                                        'paidPrincipalAmount' =>
                                            $service->paid_principal_amount,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Payment Obligation
                                        |--------------------------------------------------------------------------
                                        */

                                        'dueDate' =>
                                            optional(
                                                $service->due_date
                                            )->format('Y-m-d'),

                                        'agreementDate' =>
                                            optional(
                                                $service->agreement_date
                                            )->format('Y-m-d'),

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Applied Financial Rules
                                        |--------------------------------------------------------------------------
                                        */

                                        'penaltyRuleId' =>
                                            $service->penalty_rule_id,

                                        'interestRuleId' =>
                                            $service->interest_rule_id,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Revenue Service
                                        |--------------------------------------------------------------------------
                                        */

                                        'service' =>
                                            $service->relationLoaded(
                                                'service'
                                            )
                                                ? $this->transformRevenueService(
                                                    $service->service
                                                )
                                                : null,

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Captured Values
                                        |--------------------------------------------------------------------------
                                        */

                                        'values' =>
                                            $service->relationLoaded(
                                                'values'
                                            )
                                                ? $service->values
                                                    ->map(
                                                        fn ($value) =>
                                                            $this->transformServiceValue(
                                                                $value
                                                            )
                                                    )
                                                    ->values()
                                                : [],

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Payment Schedules
                                        |--------------------------------------------------------------------------
                                        */

                                        'paymentSchedules' =>
                                            $service->relationLoaded(
                                                'paymentSchedules'
                                            )
                                                ? $service->paymentSchedules
                                                    ->map(
                                                        function ($schedule) {

                                                            return [
                                                                'id' =>
                                                                    $schedule->id,

                                                                'installmentNumber' =>
                                                                    $schedule->installment_number,

                                                                'dueDate' =>
                                                                    optional(
                                                                        $schedule->due_date
                                                                    )->format('Y-m-d'),

                                                                'amount' =>
                                                                    $schedule->amount,

                                                                'status' =>
                                                                    $schedule->status,
                                                            ];
                                                        }
                                                    )
                                                    ->values()
                                                : [],
                                    ];
                                }
                            )
                            ->values();
                    }
                ),

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'createdBy' =>
                $this->whenLoaded(
                    'creator',
                    function () {

                        return [
                            'id' =>
                                $this->creator->id,

                            'name' =>
                                $this->creator->name,

                            'label' =>
                                $this->creator->label,

                            'email' =>
                                $this->creator->email,

                            'phone' =>
                                $this->creator->phone,

                            'userType' =>
                                $this->creator->user_type,

                            'isActive' =>
                                $this->creator->is_active,

                            'administrativeUnitId' =>
                                $this->creator->administrative_unit_id,

                            'administrativeUnit' =>
                                $this->creator->relationLoaded(
                                    'administrativeUnit'
                                )
                                    ? $this->transformAdministrativeUnit(
                                        $this->creator->administrativeUnit
                                    )
                                    : null,

                            'sectorId' =>
                                $this->creator->sector_id,

                            'sector' =>
                                $this->creator->relationLoaded(
                                    'sector'
                                )
                                    ? $this->transformSector(
                                        $this->creator->sector
                                    )
                                    : null,
                        ];
                    }
                ),

            'updatedBy' =>
                $this->whenLoaded(
                    'updater',
                    function () {

                        return [
                            'id' =>
                                $this->updater->id,

                            'name' =>
                                $this->updater->name,

                            'label' =>
                                $this->updater->label,

                            'email' =>
                                $this->updater->email,

                            'phone' =>
                                $this->updater->phone,

                            'userType' =>
                                $this->updater->user_type,

                            'isActive' =>
                                $this->updater->is_active,

                            'administrativeUnitId' =>
                                $this->updater->administrative_unit_id,

                            'administrativeUnit' =>
                                $this->updater->relationLoaded(
                                    'administrativeUnit'
                                )
                                    ? $this->transformAdministrativeUnit(
                                        $this->updater->administrativeUnit
                                    )
                                    : null,

                            'sectorId' =>
                                $this->updater->sector_id,

                            'sector' =>
                                $this->updater->relationLoaded(
                                    'sector'
                                )
                                    ? $this->transformSector(
                                        $this->updater->sector
                                    )
                                    : null,
                        ];
                    }
                ),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'createdAt' =>
                optional(
                    $this->created_at
                )->toISOString(),

            'updatedAt' =>
                optional(
                    $this->updated_at
                )->toISOString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | REVENUE SERVICE
    |--------------------------------------------------------------------------
    */

    protected function transformRevenueService(
        mixed $service
    ): ?array {

        if (!$service) {
            return null;
        }

        return [
            'id' =>
                $service->id,

            'name' =>
                $service->name
                ?? null,

            'code' =>
                $service->code
                ?? null,

            'description' =>
                $service->description
                ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ADMINISTRATIVE UNIT
    |--------------------------------------------------------------------------
    | Resolves a CITY / SUBCITY / WEREDA node plus a breadcrumb of its
    | ancestors.
    |
    | Assumes a self-referencing adjacency list (`parent()` relation
    | on the AdministrativeUnit model).
    */

    protected function transformAdministrativeUnit(
        mixed $unit
    ): ?array {

        if (!$unit) {
            return null;
        }

        $context = [
            'city' => null,
            'subcity' => null,
            'wereda' => null,
        ];

        $node = $unit;

        while ($node) {

            $level = strtolower(
                $node->level ?? ''
            );

            if (
                array_key_exists(
                    $level,
                    $context
                ) &&
                $context[$level] === null
            ) {
                $context[$level] = [
                    'id' =>
                        $node->id,

                    'name' =>
                        $node->name,
                ];
            }

            $node =
                $node->relationLoaded('parent')
                    ? $node->parent
                    : null;
        }

        return [
            'id' =>
                $unit->id,

            'name' =>
                $unit->name,

            'code' =>
                $unit->code ?? null,

            'level' =>
                $unit->level,

            'context' =>
                $context,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SECTOR
    |--------------------------------------------------------------------------
    */

    protected function transformSector(
        mixed $sector
    ): ?array {

        if (!$sector) {
            return null;
        }

        return [
            'id' =>
                $sector->id,

            'name' =>
                $sector->name,

            'code' =>
                $sector->code ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SERVICE VALUE
    |--------------------------------------------------------------------------
    */

    protected function transformServiceValue(
        mixed $value
    ): array {

        return [

            'id' =>
                $value->id,

            'revenueServiceFieldId' =>
                $value->revenue_service_field_id,

            'fieldCode' =>
                $value->field_code,

            'fieldLabel' =>
                $value->field_label,

            'dataType' =>
                $value->data_type,

            'inputType' =>
                $value->input_type,

            'value' =>
                $value->value,

            'displayValue' =>
                $value->display_value,

            'measurementUnitId' =>
                $value->measurement_unit_id,

            'sortOrder' =>
                $value->sort_order,

            /*
            |--------------------------------------------------------------------------
            | Files
            |--------------------------------------------------------------------------
            */

            'files' =>
                $value->relationLoaded('files')
                    ? $value->files
                        ->map(
                            function ($file) {

                                return [
                                    'id' =>
                                        $file->uuid,

                                    'name' =>
                                        $file->original_name,

                                    'mimeType' =>
                                        $file->mime_type,

                                    'size' =>
                                        $file->size,

                                    'status' =>
                                        $file->status,
                                ];
                            }
                        )
                        ->values()
                    : [],

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'createdAt' =>
                optional(
                    $value->created_at
                )->toISOString(),

            'updatedAt' =>
                optional(
                    $value->updated_at
                )->toISOString(),
        ];
    }
}

