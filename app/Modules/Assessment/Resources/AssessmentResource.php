<?php

namespace App\Modules\Assessment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Core
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,
            'assessmentNumber' => $this->assessment_number,
            'citizenId' => $this->citizen_id,

            /*
            |--------------------------------------------------------------------------
            | Assessment Source
            |--------------------------------------------------------------------------
            */

            'sourceType' => $this->source_type,

            'assessmentDate' => optional(
                $this->assessment_date
            )->format('Y-m-d'),

            'status' => $this->status,
            'notes' => $this->notes,

            /*
            |--------------------------------------------------------------------------
            | Submission
            |--------------------------------------------------------------------------
            */

            'submittedAt' => optional(
                $this->submitted_at
            )->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Decision
            |--------------------------------------------------------------------------
            */

            'decision' => $this->decision,
            'decisionNotes' => $this->decision_notes,

            /*
            |--------------------------------------------------------------------------
            | Decision Maker
            |--------------------------------------------------------------------------
            */

            'decidedBy' => $this->whenLoaded(
                'decider',
                fn () => $this->transformUser($this->decider)
            ),

            'decidedAt' => optional(
                $this->decided_at
            )->toISOString(),

            'approvedAt' => optional(
                $this->approved_at
            )->toISOString(),

            'rejectedAt' => optional(
                $this->rejected_at
            )->toISOString(),

            'decisionMetadata' => $this->decision_metadata,

            /*
            |--------------------------------------------------------------------------
            | Assessment Administrative Unit
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | This is the administrative unit assigned to the assessment.
            | It is independent of the creator's current administrative unit.
            |
            */

            'administrativeUnitId' => $this->administrative_unit_id,

            'administrativeUnit' => $this->whenLoaded(
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

            'taxpayer' => $this->whenLoaded(
                'taxpayer',
                function () {
                    if (!$this->taxpayer) {
                        return null;
                    }

                    return [
                        'id' => $this->taxpayer->id,
                        'citizenUid' => $this->taxpayer->citizen_uid,
                        'fullName' => $this->taxpayer->full_name,
                        'nationalId' => $this->taxpayer->national_id,
                        'phone' => $this->taxpayer->phone,
                        'email' => $this->taxpayer->email,
                        'gender' => $this->taxpayer->gender,

                        'dateOfBirth' => optional(
                            $this->taxpayer->date_of_birth
                        )->format('Y-m-d'),

                        'address' => $this->taxpayer->address,
                        'isActive' => $this->taxpayer->is_active,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            */

            'services' => $this->whenLoaded(
                'services',
                function () {
                    return $this->services
                        ->map(function ($service) {
                            return [

                                /*
                                |--------------------------------------------------------------------------
                                | Assessment Service Identity
                                |--------------------------------------------------------------------------
                                */

                                'id' => $service->id,
                                'assessmentId' => $service->assessment_id,
                                'serviceId' => $service->service_id,
                                'revenueServiceId' => $service->service_id,
                                'serviceCode' => $service->service_code,
                                'serviceOrder' => $service->service_order,
                                'status' => $service->status,

                                'paymentPlanType' =>
                                    $service->service?->revenueCode?->paymentScheduleRule?->payment_plan_type
                                    ?? 'ONE_TIME',

                                /*
                                |--------------------------------------------------------------------------
                                | Calculation
                                |--------------------------------------------------------------------------
                                */

                                'computedAmount' => $service->computed_amount,
                                'currencyCode' => $service->currency_code,
                                'calculationMetadata' => $service->calculation_metadata,
                                'calculationError' => $service->calculation_error,

                                'calculatedAt' => optional(
                                    $service->calculated_at
                                )->toISOString(),

                                /*
                                |--------------------------------------------------------------------------
                                | Historical Financial Position
                                |--------------------------------------------------------------------------
                                */

                                'originalObligation' => $service->computed_amount,
                                'paidAmount' => $service->paid_amount,
                                'remainingAmount' => $service->remaining_amount,

                                'balanceAsOfDate' => optional(
                                    $service->balance_as_of_date
                                )->format('Y-m-d'),

                                /*
                                |--------------------------------------------------------------------------
                                | Payment Tracking
                                |--------------------------------------------------------------------------
                                */

                                'paymentStatus' => $service->payment_status,
                                'paidPrincipalAmount' => $service->paid_principal_amount,

                                /*
                                |--------------------------------------------------------------------------
                                | Payment Obligation
                                |--------------------------------------------------------------------------
                                */

                                'dueDate' => optional(
                                    $service->due_date
                                )->format('Y-m-d'),

                                'agreementDate' => optional(
                                    $service->agreement_date
                                )->format('Y-m-d'),

                                /*
                                |--------------------------------------------------------------------------
                                | Applied Financial Rules
                                |--------------------------------------------------------------------------
                                */

                                'penaltyRuleId' => $service->penalty_rule_id,
                                'interestRuleId' => $service->interest_rule_id,

                                /*
                                |--------------------------------------------------------------------------
                                | Revenue Service
                                |--------------------------------------------------------------------------
                                */

                                'service' => $service->relationLoaded('service')
                                    ? $this->transformRevenueService(
                                        $service->service
                                    )
                                    : null,

                                /*
                                |--------------------------------------------------------------------------
                                | Captured Service Values
                                |--------------------------------------------------------------------------
                                */

                                'values' => $service->relationLoaded('values')
                                    ? $service->values
                                        ->map(
                                            fn ($value) => $this->transformServiceValue(
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

                                'paymentSchedules' => $service->relationLoaded(
                                    'paymentSchedules'
                                )
                                    ? $service->paymentSchedules
                                        ->map(function ($schedule) {
                                            return [
                                                'id' => $schedule->id,
                                                'assessmentServiceId' => $schedule->assessment_service_id,
                                                'installmentNumber' => $schedule->installment_number,

                                                'dueDate' => optional(
                                                    $schedule->due_date
                                                )->format('Y-m-d'),

                                                'amount' => $schedule->amount,
                                                'paidAmount' => $schedule->paid_amount ?? null,
                                                'remainingAmount' => $schedule->remaining_amount ?? null,
                                                'status' => $schedule->status,

                                                'createdAt' => optional(
                                                    $schedule->created_at
                                                )->toISOString(),

                                                'updatedAt' => optional(
                                                    $schedule->updated_at
                                                )->toISOString(),
                                            ];
                                        })
                                        ->values()
                                    : [],

                                /*
                                |--------------------------------------------------------------------------
                                | Assessment Service Audit
                                |--------------------------------------------------------------------------
                                */

                                'createdAt' => optional(
                                    $service->created_at
                                )->toISOString(),

                                'updatedAt' => optional(
                                    $service->updated_at
                                )->toISOString(),
                            ];
                        })
                        ->values();
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Audit - Created By
            |--------------------------------------------------------------------------
            |
            | This administrative unit belongs to the creator, not the assessment.
            |
            */

            'createdBy' => $this->whenLoaded(
                'creator',
                fn () => $this->transformUser($this->creator)
            ),

            /*
            |--------------------------------------------------------------------------
            | Audit - Updated By
            |--------------------------------------------------------------------------
            */

            'updatedBy' => $this->whenLoaded(
                'updater',
                fn () => $this->transformUser($this->updater)
            ),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'createdAt' => optional(
                $this->created_at
            )->toISOString(),

            'updatedAt' => optional(
                $this->updated_at
            )->toISOString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | USER
    |--------------------------------------------------------------------------
    */

    protected function transformUser(mixed $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'label' => $user->label,
            'email' => $user->email,
            'phone' => $user->phone,
            'userType' => $user->user_type,
            'isActive' => $user->is_active,

            'administrativeUnitId' => $user->administrative_unit_id,

            'administrativeUnit' => $user->relationLoaded(
                'administrativeUnit'
            )
                ? $this->transformAdministrativeUnit(
                    $user->administrativeUnit
                )
                : null,

            'sectorId' => $user->sector_id,

            'sector' => $user->relationLoaded('sector')
                ? $this->transformSector($user->sector)
                : null,

            'createdAt' => optional(
                $user->created_at
            )->toISOString(),

            'updatedAt' => optional(
                $user->updated_at
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
            'id' => $service->id,
            'name' => $service->name ?? null,
            'code' => $service->code ?? null,
            'description' => $service->description ?? null,
            'category' => $service->category ?? null,
            'collectionMode' => $service->collection_mode ?? null,
            'isActive' => $service->is_active ?? null,

            'createdAt' => optional(
                $service->created_at
            )->toISOString(),

            'updatedAt' => optional(
                $service->updated_at
            )->toISOString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ADMINISTRATIVE UNIT
    |--------------------------------------------------------------------------
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
                array_key_exists($level, $context) &&
                $context[$level] === null
            ) {
                $context[$level] = [
                    'id' => $node->id,
                    'name' => $node->name,
                    'code' => $node->code ?? null,
                ];
            }

            $node = $node->relationLoaded('parent')
                ? $node->parent
                : null;
        }

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'code' => $unit->code ?? null,
            'level' => $unit->level,
            'context' => $context,
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
            'id' => $sector->id,
            'name' => $sector->name,
            'code' => $sector->code ?? null,
            'isActive' => $sector->is_active ?? null,
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
            'id' => $value->id,
            'assessmentServiceId' => $value->assessment_service_id,
            'revenueServiceFieldId' => $value->revenue_service_field_id,
            'fieldCode' => $value->field_code,
            'fieldLabel' => $value->field_label,
            'dataType' => $value->data_type,
            'inputType' => $value->input_type,
            'value' => $value->value,
            'displayValue' => $value->display_value,
            'measurementUnitId' => $value->measurement_unit_id,
            'sortOrder' => $value->sort_order,

            /*
            |--------------------------------------------------------------------------
            | Files
            |--------------------------------------------------------------------------
            */

            'files' => $value->relationLoaded('files')
                ? $value->files
                    ->map(function ($file) {
                        return [
                            'id' => $file->uuid,
                            'name' => $file->original_name,
                            'mimeType' => $file->mime_type,
                            'size' => $file->size,
                            'status' => $file->status,
                        ];
                    })
                    ->values()
                : [],

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'createdAt' => optional(
                $value->created_at
            )->toISOString(),

            'updatedAt' => optional(
                $value->updated_at
            )->toISOString(),
        ];
    }
}
