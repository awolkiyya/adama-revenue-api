<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueSettingResource extends JsonResource
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


            /*
            |--------------------------------------------------------------------------
            | Payment Period
            |--------------------------------------------------------------------------
            */

            'payment_period' => [
                'start' => $this->paymentStartPeriod(),
                'end' => $this->paymentEndPeriod(),
                'configured' => $this->hasPaymentPeriod(),
            ],

            'payment_start_month' => $this->payment_start_month,
            'payment_start_day' => $this->payment_start_day,

            'payment_end_month' => $this->payment_end_month,
            'payment_end_day' => $this->payment_end_day,


            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => $this->penalty_enabled,
            'interest_enabled' => $this->interest_enabled,


            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' => $this->assessment_auto_calculation,
            'assessment_allow_manual_adjustment' => $this->assessment_allow_manual_adjustment,
            'assessment_requires_approval' => $this->assessment_requires_approval,
            'assessment_reassessment_allowed' => $this->assessment_reassessment_allowed,


            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' => $this->invoice_auto_numbering,
            'invoice_prefix' => $this->invoice_prefix,
            'invoice_allow_overpayment' => $this->invoice_allow_overpayment,
            'invoice_allow_overdue_payment' => $this->invoice_allow_overdue_payment,


            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' => $this->payment_confirmation_required,
            'payment_auto_receipt' => $this->payment_auto_receipt,

            'enabled_payment_methods' => $this->enabledPaymentMethods(),


            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' => $this->receipt_auto_numbering,
            'receipt_prefix' => $this->receipt_prefix,
            'receipt_allow_reprint' => $this->receipt_allow_reprint,


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            'is_active' => $this->is_active,


            /*
            |--------------------------------------------------------------------------
            | Legal / Description
            |--------------------------------------------------------------------------
            */

            'legal_reference' => $this->legal_reference,
            'description' => $this->description,


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}