<?php

namespace App\Modules\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the invoice into an API response.
     */
    public function toArray(Request $request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | IDENTIFICATION
            |--------------------------------------------------------------------------
            */

            'id' =>
                $this->id,

            'invoice_number' =>
                $this->invoice_number,

            'source_type' =>
                $this->source_type,

            'status' =>
                $this->status,


            /*
            |--------------------------------------------------------------------------
            | BILLING PERIOD
            |--------------------------------------------------------------------------
            */

            'fiscal_year' =>
                $this->fiscal_year,


            /*
            |--------------------------------------------------------------------------
            | CURRENCY
            |--------------------------------------------------------------------------
            */

            'currency' =>
                $this->currency,


            /*
            |--------------------------------------------------------------------------
            | CITIZEN
            |--------------------------------------------------------------------------
            */

            'citizen' => $this->whenLoaded(
                'citizen',
                fn () => $this->citizen
                    ? [
                        'id' =>
                            $this->citizen->id,

                        'citizen_number' =>
                            $this->citizen->citizen_uid,

                        'name' =>
                            $this->citizen->full_name,

                        'phone' =>
                            $this->citizen->phone,

                        'email' =>
                            $this->citizen->email,
                    ]
                    : null
            ),


            /*
            |--------------------------------------------------------------------------
            | ASSESSMENT
            |--------------------------------------------------------------------------
            */

            'assessment' => $this->whenLoaded(
                'assessment',
                fn () => $this->assessment
                    ? [
                        'id' =>
                            $this->assessment->id,

                        'assessment_number' =>
                            $this->assessment->assessment_number,

                        'status' =>
                            $this->assessment->status,

                        'created_at' =>
                            $this->assessment->created_at
                                ?->toIso8601String(),
                    ]
                    : null
            ),


            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
            */

            'administrative_unit' => $this->whenLoaded(
                'administrativeUnit',
                fn () => $this->administrativeUnit
                    ? [
                        'id' =>
                            $this->administrativeUnit->id,

                        'name' =>
                            $this->administrativeUnit->name,
                    ]
                    : null
            ),


            /*
            |--------------------------------------------------------------------------
            | FINANCIAL
            |--------------------------------------------------------------------------
            */

            'financial' => [

                'subtotal' =>
                    $this->subtotal,

                'discount_amount' =>
                    $this->discount_amount,

                'penalty_amount' =>
                    $this->penalty_amount,

                'total_amount' =>
                    $this->total_amount,

                'paid_amount' =>
                    $this->paid_amount,

                'balance_due' =>
                    $this->balance_due,

                'currency' =>
                    $this->currency,
            ],


            /*
            |--------------------------------------------------------------------------
            | DATES
            |--------------------------------------------------------------------------
            */

            'dates' => [

                'issued_at' =>
                    $this->issued_at?->toIso8601String(),

                'due_date' =>
                    $this->due_date?->toDateString(),

                'paid_at' =>
                    $this->paid_at?->toIso8601String(),

                'cancelled_at' =>
                    $this->cancelled_at?->toIso8601String(),

                'voided_at' =>
                    $this->voided_at?->toIso8601String(),

                'created_at' =>
                    $this->created_at?->toIso8601String(),

                'updated_at' =>
                    $this->updated_at?->toIso8601String(),
            ],


            /*
            |--------------------------------------------------------------------------
            | AUDIT / ACTORS
            |--------------------------------------------------------------------------
            */

            'audit' => [

                'created_by' => $this->whenLoaded(
                    'createdBy',
                    fn () => $this->createdBy
                        ? [
                            'id' =>
                                $this->createdBy->id,

                            'name' =>
                                $this->createdBy->name,
                        ]
                        : null
                ),

                'issued_by' => $this->whenLoaded(
                    'issuedBy',
                    fn () => $this->issuedBy
                        ? [
                            'id' =>
                                $this->issuedBy->id,

                            'name' =>
                                $this->issuedBy->name,
                        ]
                        : null
                ),
            ],


            /*
            |--------------------------------------------------------------------------
            | CANCELLATION
            |--------------------------------------------------------------------------
            */

            'cancellation' => $this->when(
                $this->cancelled_at !== null ||
                $this->cancellation_reason !== null,

                fn () => [

                    'cancelled_at' =>
                        $this->cancelled_at
                            ?->toIso8601String(),

                    'reason' =>
                        $this->cancellation_reason,
                ]
            ),


            /*
            |--------------------------------------------------------------------------
            | VOID
            |--------------------------------------------------------------------------
            */

            'void' => $this->when(
                $this->voided_at !== null ||
                $this->void_reason !== null,

                fn () => [

                    'voided_at' =>
                        $this->voided_at
                            ?->toIso8601String(),

                    'reason' =>
                        $this->void_reason,
                ]
            ),


            /*
            |--------------------------------------------------------------------------
            | NOTES
            |--------------------------------------------------------------------------
            */

            'notes' =>
                $this->notes,


            /*
            |--------------------------------------------------------------------------
            | SOURCE METADATA
            |--------------------------------------------------------------------------
            */

            'source_metadata' =>
                $this->source_metadata,


            /*
            |--------------------------------------------------------------------------
            | ITEMS
            |--------------------------------------------------------------------------
            */

            'items' =>
                InvoiceItemResource::collection(
                    $this->whenLoaded('items')
                ),
        ];
    }
}