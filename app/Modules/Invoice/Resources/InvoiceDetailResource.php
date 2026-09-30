<?php

namespace App\Modules\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice detail API resource.
 *
 * Endpoint:
 * GET /api/v1/invoices/{id}
 *
 * This resource intentionally provides a complete read model for
 * an individual invoice.
 *
 * The resource does not perform business calculations.
 * All financial values are read from the persisted invoice/payment
 * records maintained by the backend.
 *
 * Important:
 * - whenLoaded() is used only on this JsonResource.
 * - Nested Eloquent models use relationLoaded() to prevent
 *   accidental lazy loading and N+1 queries.
 */
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
            |
            | citizen is expected to be eager-loaded by the invoice detail
            | service. The relationship may still be null only if the
            | underlying record is unavailable.
            |
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

                        'citizen_number' =>
                            $this->citizen->citizen_number,

                        'phone' =>
                            $this->citizen->phone,

                        'email' =>
                            $this->citizen->email,
                    ];
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | ASSESSMENT
            |--------------------------------------------------------------------------
            |
            | ASSESSMENT invoices normally have an assessment.
            |
            | DIRECT_COLLECTION invoices normally have no assessment.
            |
            */

            'assessment' => $this->whenLoaded(
                'assessment',
                function () {
                    if (!$this->assessment) {
                        return null;
                    }

                    return [
                        'id' => $this->assessment->id,

                        'assessment_number' =>
                            $this->assessment->assessment_number,

                        'status' =>
                            $this->assessment->status,
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
                        'id' =>
                            $this->administrativeUnit->id,

                        'name' =>
                            $this->administrativeUnit->name,

                        'code' =>
                            $this->administrativeUnit->code,
                    ];
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | FINANCIAL INFORMATION
            |--------------------------------------------------------------------------
            |
            | These values are authoritative persisted invoice values.
            |
            | The frontend MUST NOT independently recalculate:
            |
            | total_amount
            | paid_amount
            | balance_due
            |
            */

            'financial' => [

                'subtotal' =>
                    $this->subtotal,

                'discount_amount' =>
                    $this->discount_amount,

                'penalty_amount' =>
                    $this->penalty_amount,

                'interest_amount' =>
                    $this->interest_amount,

                'total_amount' =>
                    $this->total_amount,

                'paid_amount' =>
                    $this->paid_amount,

                'balance_due' =>
                    $this->balance_due,
            ],


            /*
            |--------------------------------------------------------------------------
            | INVOICE DATES
            |--------------------------------------------------------------------------
            */

            'dates' => [

                'issued_at' =>
                    $this->issued_at,

                'due_date' =>
                    $this->due_date,

                'paid_at' =>
                    $this->paid_at,

                'cancelled_at' =>
                    $this->cancelled_at,

                'voided_at' =>
                    $this->voided_at,
            ],


            /*
            |--------------------------------------------------------------------------
            | INVOICE ITEMS
            |--------------------------------------------------------------------------
            |
            | Each item represents an immutable financial line captured
            | when the invoice was generated.
            |
            | IMPORTANT:
            | $item is an InvoiceItem Eloquent model.
            | Therefore we DO NOT call:
            |
            | $item->whenLoaded(...)
            |
            | Instead we use relationLoaded() before accessing nested
            | relationships.
            |
            */

            'items' => $this->whenLoaded(
                'items',
                function () {
                    return $this->items->map(
                        function ($item): array {
                            return [

                                /*
                                |--------------------------------------------------------------------------
                                | ITEM IDENTITY
                                |--------------------------------------------------------------------------
                                */

                                'id' =>
                                    $item->id,

                                'line_number' =>
                                    $item->line_number,

                                'assessment_service_id' =>
                                    $item->assessment_service_id,

                                'service_id' =>
                                    $item->service_id,


                                /*
                                |--------------------------------------------------------------------------
                                | ITEM DESCRIPTION
                                |--------------------------------------------------------------------------
                                */

                                'description' =>
                                    $item->description,

                                'quantity' =>
                                    $item->quantity,

                                'unit' =>
                                    $item->unit,

                                'unit_price' =>
                                    $item->unit_price,


                                /*
                                |--------------------------------------------------------------------------
                                | ITEM FINANCIAL VALUES
                                |--------------------------------------------------------------------------
                                */

                                'amount' =>
                                    $item->amount,

                                'discount_amount' =>
                                    $item->discount_amount,

                                'penalty_amount' =>
                                    $item->penalty_amount,

                                'interest_amount' =>
                                    $item->interest_amount,

                                'total_amount' =>
                                    $item->total_amount,

                                'currency' =>
                                    $item->currency,


                                /*
                                |--------------------------------------------------------------------------
                                | TARIFF / CALCULATION REFERENCES
                                |--------------------------------------------------------------------------
                                */

                                'tariff_version_id' =>
                                    $item->tariff_version_id,

                                'tariff_rule_id' =>
                                    $item->tariff_rule_id,


                                /*
                                |--------------------------------------------------------------------------
                                | CALCULATION SNAPSHOTS
                                |--------------------------------------------------------------------------
                                |
                                | These snapshots are preserved for:
                                |
                                | - auditability
                                | - dispute investigation
                                | - historical calculation review
                                | - reproducing how the amount was determined
                                |
                                */

                                'input_snapshot' =>
                                    $item->input_snapshot,

                                'calculation_snapshot' =>
                                    $item->calculation_snapshot,


                                /*
                                |--------------------------------------------------------------------------
                                | REVENUE SERVICE
                                |--------------------------------------------------------------------------
                                |
                                | Only access the relationship when it was explicitly
                                | eager-loaded. This prevents accidental lazy loading.
                                |
                                */

                                'service' =>
                                    $item->relationLoaded('service')
                                        ? (
                                            $item->service
                                                ? [
                                                    'id' =>
                                                        $item->service->id,

                                                    'name' =>
                                                        $item->service->name,

                                                    'code' =>
                                                        $item->service->code
                                                            ?? null,
                                                ]
                                                : null
                                        )
                                        : null,


                                /*
                                |--------------------------------------------------------------------------
                                | ASSESSMENT SERVICE
                                |--------------------------------------------------------------------------
                                */

                                'assessment_service' =>
                                    $item->relationLoaded(
                                        'assessmentService'
                                    )
                                        ? (
                                            $item->assessmentService
                                                ? [
                                                    'id' =>
                                                        $item
                                                            ->assessmentService
                                                            ->id,

                                                    'computed_amount' =>
                                                        $item
                                                            ->assessmentService
                                                            ->computed_amount,
                                                ]
                                                : null
                                        )
                                        : null,
                            ];
                        }
                    )->values()->all();
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | PAYMENT HISTORY
            |--------------------------------------------------------------------------
            |
            | A single invoice can have multiple payments.
            |
            | This supports:
            |
            | - full payment
            | - partial payment
            | - failed payment
            | - cancelled payment
            | - refunded payment
            | - payment verification
            | - payment evidence
            | - official receipts
            |
            */

            'payments' => $this->whenLoaded(
                'payments',
                function () {
                    return $this->payments->map(
                        function ($payment): array {
                            return [

                                /*
                                |--------------------------------------------------------------------------
                                | PAYMENT IDENTITY
                                |--------------------------------------------------------------------------
                                */

                                'id' =>
                                    $payment->id,

                                'payment_number' =>
                                    $payment->payment_number,


                                /*
                                |--------------------------------------------------------------------------
                                | FINANCIAL INFORMATION
                                |--------------------------------------------------------------------------
                                */

                                'amount' =>
                                    $payment->amount,

                                'currency' =>
                                    $payment->currency,


                                /*
                                |--------------------------------------------------------------------------
                                | PAYMENT CLASSIFICATION
                                |--------------------------------------------------------------------------
                                */

                                'payment_method' =>
                                    $payment->payment_method,

                                'payment_provider' =>
                                    $payment->payment_provider,

                                'status' =>
                                    $payment->status,


                                /*
                                |--------------------------------------------------------------------------
                                | TRANSACTION REFERENCES
                                |--------------------------------------------------------------------------
                                |
                                | transaction_reference:
                                | Internal system/application reference.
                                |
                                | provider_reference:
                                | Reference returned by the external payment
                                | provider when applicable.
                                |
                                */

                                'transaction_reference' =>
                                    $payment->transaction_reference,

                                'provider_reference' =>
                                    $payment->provider_reference,


                                /*
                                |--------------------------------------------------------------------------
                                | PAYMENT DATES
                                |--------------------------------------------------------------------------
                                */

                                'payment_date' =>
                                    $payment->payment_date,

                                'verified_at' =>
                                    $payment->verified_at,


                                /*
                                |--------------------------------------------------------------------------
                                | RECEIVING OFFICER
                                |--------------------------------------------------------------------------
                                */

                                'received_by' =>
                                    $payment->relationLoaded('receivedBy')
                                        ? (
                                            $payment->receivedBy
                                                ? [
                                                    'id' =>
                                                        $payment
                                                            ->receivedBy
                                                            ->id,

                                                    'name' =>
                                                        $payment
                                                            ->receivedBy
                                                            ->name,
                                                ]
                                                : null
                                        )
                                        : null,


                                /*
                                |--------------------------------------------------------------------------
                                | VERIFYING OFFICER
                                |--------------------------------------------------------------------------
                                */

                                'verified_by' =>
                                    $payment->relationLoaded('verifiedBy')
                                        ? (
                                            $payment->verifiedBy
                                                ? [
                                                    'id' =>
                                                        $payment
                                                            ->verifiedBy
                                                            ->id,

                                                    'name' =>
                                                        $payment
                                                            ->verifiedBy
                                                            ->name,
                                                ]
                                                : null
                                        )
                                        : null,


                                /*
                                |--------------------------------------------------------------------------
                                | FAILURE INFORMATION
                                |--------------------------------------------------------------------------
                                */

                                'failure_reason' =>
                                    $payment->failure_reason,


                                /*
                                |--------------------------------------------------------------------------
                                | PAYMENT EVIDENCE
                                |--------------------------------------------------------------------------
                                |
                                | Generic files attached to this payment
                                | through the payment_evidence collection.
                                |
                                */

                                'evidence' =>
                                    $payment->relationLoaded(
                                        'paymentEvidence'
                                    )
                                        ? $payment
                                            ->paymentEvidence
                                            ->map(
                                                function ($file): array {
                                                    return [
                                                        'id' =>
                                                            $file->id,

                                                        'uuid' =>
                                                            $file->uuid,

                                                        'original_name' =>
                                                            $file->original_name,

                                                        'mime_type' =>
                                                            $file->mime_type,

                                                        'size_bytes' =>
                                                            $file->size_bytes,

                                                        'status' =>
                                                            $file->status,

                                                        'visibility' =>
                                                            $file->visibility,

                                                        'uploaded_at' =>
                                                            $file->uploaded_at,
                                                    ];
                                                }
                                            )
                                            ->values()
                                            ->all()
                                        : [],


                                /*
                                |--------------------------------------------------------------------------
                                | PAYMENT RECEIPTS
                                |--------------------------------------------------------------------------
                                |
                                | Official receipts generated for the payment.
                                |
                                */

                                'receipts' =>
                                    $payment->relationLoaded(
                                        'receipts'
                                    )
                                        ? $payment
                                            ->receipts
                                            ->map(
                                                function ($file): array {
                                                    return [
                                                        'id' =>
                                                            $file->id,

                                                        'uuid' =>
                                                            $file->uuid,

                                                        'original_name' =>
                                                            $file->original_name,

                                                        'mime_type' =>
                                                            $file->mime_type,

                                                        'size_bytes' =>
                                                            $file->size_bytes,

                                                        'status' =>
                                                            $file->status,

                                                        'visibility' =>
                                                            $file->visibility,

                                                        'uploaded_at' =>
                                                            $file->uploaded_at,
                                                    ];
                                                }
                                            )
                                            ->values()
                                            ->all()
                                        : [],
                            ];
                        }
                    )->values()->all();
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | SOURCE INFORMATION
            |--------------------------------------------------------------------------
            |
            | source_metadata contains contextual information captured
            | when the invoice was generated.
            |
            | This is particularly useful for:
            |
            | - DIRECT_COLLECTION
            | - ASSESSMENT
            | - calculation snapshots
            | - source-specific metadata
            |
            */

            'source' => [

                'type' =>
                    $this->source_type,

                'metadata' =>
                    $this->source_metadata,
            ],


            /*
            |--------------------------------------------------------------------------
            | AUDIT INFORMATION
            |--------------------------------------------------------------------------
            */

            'audit' => [

                'created_at' =>
                    $this->created_at,

                'updated_at' =>
                    $this->updated_at,


                /*
                |--------------------------------------------------------------------------
                | CREATED BY
                |--------------------------------------------------------------------------
                */

                'created_by' => $this->whenLoaded(
                    'createdBy',
                    function () {
                        if (!$this->createdBy) {
                            return null;
                        }

                        return [
                            'id' =>
                                $this->createdBy->id,

                            'name' =>
                                $this->createdBy->name,
                        ];
                    }
                ),


                /*
                |--------------------------------------------------------------------------
                | ISSUED BY
                |--------------------------------------------------------------------------
                */

                'issued_by' => $this->whenLoaded(
                    'issuedBy',
                    function () {
                        if (!$this->issuedBy) {
                            return null;
                        }

                        return [
                            'id' =>
                                $this->issuedBy->id,

                            'name' =>
                                $this->issuedBy->name,
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

                'cancelled_at' =>
                    $this->cancelled_at,

                'reason' =>
                    $this->cancellation_reason,

                'cancelled_by' => $this->whenLoaded(
                    'cancelledBy',
                    function () {
                        if (!$this->cancelledBy) {
                            return null;
                        }

                        return [
                            'id' =>
                                $this->cancelledBy->id,

                            'name' =>
                                $this->cancelledBy->name,
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

                'voided_at' =>
                    $this->voided_at,

                'reason' =>
                    $this->void_reason,

                'voided_by' => $this->whenLoaded(
                    'voidedBy',
                    function () {
                        if (!$this->voidedBy) {
                            return null;
                        }

                        return [
                            'id' =>
                                $this->voidedBy->id,

                            'name' =>
                                $this->voidedBy->name,
                        ];
                    }
                ),
            ],


            /*
            |--------------------------------------------------------------------------
            | NOTES
            |--------------------------------------------------------------------------
            */

            'notes' =>
                $this->notes,
        ];
    }
}