<?php

namespace App\Modules\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceDetailResource extends JsonResource
{
    /**
     * Transform the invoice resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | BASIC INFORMATION
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'source_type' => $this->source_type,
            'status' => $this->status,
            'currency' => $this->currency,

            /*
            |--------------------------------------------------------------------------
            | CITIZEN / TAXPAYER
            |--------------------------------------------------------------------------
            */

            'citizen' => $this->whenLoaded(
                'citizen',
                function () {
                    if (!$this->citizen) {
                        return null;
                    }

                    return [
                        'id' => $this->citizen->id,
                        'name' => $this->citizen->name,
                        'citizen_number' => $this->citizen->citizen_number,
                        'phone' => $this->citizen->phone,
                        'email' => $this->citizen->email,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | ASSESSMENT
            |--------------------------------------------------------------------------
            */

            'assessment' => $this->whenLoaded(
                'assessment',
                function () {
                    if (!$this->assessment) {
                        return null;
                    }

                    return [
                        'id' => $this->assessment->id,
                        'assessment_number' => $this->assessment->assessment_number,
                        'status' => $this->assessment->status,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | ADMINISTRATIVE UNIT
            |--------------------------------------------------------------------------
            */

            'administrative_unit' => $this->whenLoaded(
                'administrativeUnit',
                function () {
                    if (!$this->administrativeUnit) {
                        return null;
                    }

                    return [
                        'id' => $this->administrativeUnit->id,
                        'name' => $this->administrativeUnit->name,
                        'code' => $this->administrativeUnit->code,
                    ];
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | FINANCIAL INFORMATION
            |--------------------------------------------------------------------------
            |
            | These are persisted invoice values. Do not recalculate them here.
            |
            */

            'financial' => [
                'subtotal' => $this->subtotal,
                'discount_amount' => $this->discount_amount,
                'penalty_amount' => $this->penalty_amount,
                'interest_amount' => $this->interest_amount,
                'total_amount' => $this->total_amount,
                'paid_amount' => $this->paid_amount,
                'balance_due' => $this->balance_due,
            ],

            /*
            |--------------------------------------------------------------------------
            | INVOICE DATES
            |--------------------------------------------------------------------------
            */

            'dates' => [
                'issued_at' => $this->issued_at,
                'due_date' => $this->due_date,
                'paid_at' => $this->paid_at,
                'cancelled_at' => $this->cancelled_at,
                'voided_at' => $this->voided_at,
            ],

            /*
            |--------------------------------------------------------------------------
            | INVOICE ITEMS
            |--------------------------------------------------------------------------
            */

            'items' => $this->whenLoaded(
                'items',
                function () {
                    return $this->items
                        ->map(function ($item): array {
                            return [
                                'id' => $item->id,
                                'line_number' => $item->line_number,

                                'assessment_service_id' =>
                                    $item->assessment_service_id,

                                'service_id' => $item->service_id,

                                'description' => $item->description,
                                'quantity' => $item->quantity,
                                'unit' => $item->unit,
                                'unit_price' => $item->unit_price,

                                'amount' => $item->amount,
                                'discount_amount' => $item->discount_amount,
                                'penalty_amount' => $item->penalty_amount,
                                'interest_amount' => $item->interest_amount,
                                'total_amount' => $item->total_amount,
                                'currency' => $item->currency,

                                'tariff_version_id' =>
                                    $item->tariff_version_id,

                                'tariff_rule_id' =>
                                    $item->tariff_rule_id,

                                'input_snapshot' => $item->input_snapshot,
                                'calculation_snapshot' =>
                                    $item->calculation_snapshot,

                                /*
                                | Revenue service
                                */

                                'service' => $item->relationLoaded('service')
                                    ? (
                                        $item->service
                                            ? [
                                                'id' => $item->service->id,
                                                'name' => $item->service->name,
                                                'code' => $item->service->code,
                                            ]
                                            : null
                                    )
                                    : null,

                                /*
                                | Assessment service
                                */

                                'assessment_service' =>
                                    $item->relationLoaded('assessmentService')
                                        ? (
                                            $item->assessmentService
                                                ? [
                                                    'id' => $item->assessmentService->id,
                                                    'computed_amount' =>
                                                        $item->assessmentService->computed_amount,
                                                ]
                                                : null
                                        )
                                        : null,

                                /*
                                | Payment schedule
                                */

                                'payment_schedule' =>
                                    $item->relationLoaded('paymentSchedule')
                                        ? (
                                            $item->paymentSchedule
                                                ? [
                                                    'id' => $item->paymentSchedule->id,
                                                ]
                                                : null
                                        )
                                        : null,
                            ];
                        })
                        ->values()
                        ->all();
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | PAYMENT HISTORY
            |--------------------------------------------------------------------------
            |
            | Only real payment records associated with this invoice are returned.
            | The InvoiceService must eager-load the payments relationship.
            |
            */

            'payments' => $this->whenLoaded(
                'payments',
                function () {
                    return $this->payments
                        ->map(function ($payment): array {
                            /*
                            | Convert backed enums to their stored values.
                            */

                            $paymentMethod = $payment->payment_method;
                            $paymentProvider = $payment->payment_provider;
                            $paymentStatus = $payment->status;

                            return [
                                /*
                                | Payment identity
                                */

                                'id' => $payment->id,
                                'payment_number' => $payment->payment_number,

                                /*
                                | Financial information
                                */

                                'amount' => $payment->amount,
                                'currency' => $payment->currency,

                                /*
                                | Classification
                                */

                                'payment_method' => $paymentMethod instanceof \BackedEnum
                                    ? $paymentMethod->value
                                    : $paymentMethod,

                                'payment_provider' => $paymentProvider instanceof \BackedEnum
                                    ? $paymentProvider->value
                                    : $paymentProvider,

                                'status' => $paymentStatus instanceof \BackedEnum
                                    ? $paymentStatus->value
                                    : $paymentStatus,

                                /*
                                | Transaction references
                                */

                                'transaction_reference' =>
                                    $payment->transaction_reference,

                                'provider_reference' =>
                                    $payment->provider_reference,

                                /*
                                | Dates
                                |
                                | The provided Payment model does not establish
                                | a payment_date column. Return null when absent.
                                */

                                'payment_date' =>
                                    $payment->getAttribute('payment_date'),

                                'created_at' => $payment->created_at,
                                'verified_at' => $payment->verified_at,

                                /*
                                | Receiving / processing officer
                                */

                                'received_by' =>
                                    $payment->relationLoaded('processedBy')
                                        ? (
                                            $payment->processedBy
                                                ? [
                                                    'id' => $payment->processedBy->id,
                                                    'name' => $payment->processedBy->name,
                                                ]
                                                : null
                                        )
                                        : null,

                                /*
                                | Verifying officer
                                */

                                'verified_by' =>
                                    $payment->relationLoaded('verifiedBy')
                                        ? (
                                            $payment->verifiedBy
                                                ? [
                                                    'id' => $payment->verifiedBy->id,
                                                    'name' => $payment->verifiedBy->name,
                                                ]
                                                : null
                                        )
                                        : null,

                                /*
                                | Failure information
                                */

                                'failure_reason' => $payment->failure_reason,

                                /*
                                | Payment files
                                |
                                | These are metadata only. Do not expose private
                                | storage paths or direct download URLs.
                                */

                                'evidence' =>
                                    $payment->relationLoaded('files')
                                        ? $payment->files
                                            ->map(function ($file): array {
                                                return [
                                                    'id' => $file->id,
                                                    'uuid' => $file->uuid,
                                                    'original_name' => $file->original_name,
                                                    'mime_type' => $file->mime_type,
                                                    'size_bytes' => $file->size_bytes,
                                                    'status' => $file->status,
                                                    'visibility' => $file->visibility,
                                                    'uploaded_at' => $file->uploaded_at,
                                                ];
                                            })
                                            ->values()
                                            ->all()
                                        : [],

                                /*
                                | Official receipt
                                |
                                | Payment::receipt() is HasOne, so this is
                                | a single object or null, not a collection.
                                */

                                'receipt' =>
                                    $payment->relationLoaded('receipt')
                                        ? (
                                            $payment->receipt
                                                ? [
                                                    'id' => $payment->receipt->id,
                                                ]
                                                : null
                                        )
                                        : null,

                                /*
                                | Method-specific details
                                */

                                'cash_details' =>
                                    $payment->relationLoaded('cashDetails')
                                        ? (
                                            $payment->cashDetails
                                                ? [
                                                    'id' => $payment->cashDetails->id,
                                                ]
                                                : null
                                        )
                                        : null,

                                'bank_transfer_details' =>
                                    $payment->relationLoaded('bankTransferDetails')
                                        ? (
                                            $payment->bankTransferDetails
                                                ? [
                                                    'id' => $payment->bankTransferDetails->id,
                                                ]
                                                : null
                                        )
                                        : null,

                                'online_details' =>
                                    $payment->relationLoaded('onlineDetails')
                                        ? (
                                            $payment->onlineDetails
                                                ? [
                                                    'id' => $payment->onlineDetails->id,
                                                ]
                                                : null
                                        )
                                        : null,
                            ];
                        })
                        ->values()
                        ->all();
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | SOURCE INFORMATION
            |--------------------------------------------------------------------------
            */

            'source' => [
                'type' => $this->source_type,
                'metadata' => $this->source_metadata,
            ],

            /*
            |--------------------------------------------------------------------------
            | AUDIT INFORMATION
            |--------------------------------------------------------------------------
            */

            'audit' => [
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,

                'created_by' => $this->whenLoaded(
                    'creator',
                    function () {
                        if (!$this->creator) {
                            return null;
                        }

                        return [
                            'id' => $this->creator->id,
                            'name' => $this->creator->name,
                        ];
                    }
                ),

                'issued_by' => $this->whenLoaded(
                    'issuer',
                    function () {
                        if (!$this->issuer) {
                            return null;
                        }

                        return [
                            'id' => $this->issuer->id,
                            'name' => $this->issuer->name,
                        ];
                    }
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | CANCELLATION
            |--------------------------------------------------------------------------
            */

            'cancellation' => [
                'cancelled_at' => $this->cancelled_at,
                'reason' => $this->cancellation_reason,

                'cancelled_by' => $this->whenLoaded(
                    'cancelledBy',
                    function () {
                        if (!$this->cancelledBy) {
                            return null;
                        }

                        return [
                            'id' => $this->cancelledBy->id,
                            'name' => $this->cancelledBy->name,
                        ];
                    }
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | VOID
            |--------------------------------------------------------------------------
            */

            'void' => [
                'voided_at' => $this->voided_at,
                'reason' => $this->void_reason,

                'voided_by' => $this->whenLoaded(
                    'voidedBy',
                    function () {
                        if (!$this->voidedBy) {
                            return null;
                        }

                        return [
                            'id' => $this->voidedBy->id,
                            'name' => $this->voidedBy->name,
                        ];
                    }
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | NOTES
            |--------------------------------------------------------------------------
            */

            'notes' => $this->notes,
        ];
    }
}

