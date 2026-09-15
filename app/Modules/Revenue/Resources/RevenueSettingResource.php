<?php

namespace App\Modules\Revenue\Resources;

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
            | Annual Payment Due Date
            |--------------------------------------------------------------------------
            |
            | Stored as an Ethiopian recurring MM-DD value.
            |
            | Examples:
            |
            |     01-01
            |     10-30
            |     13-06
            |
            | The year is intentionally not stored because the payment
            | deadline recurs every Ethiopian calendar year.
            |
            */

            'annual_payment_due_date' => $this->annualPaymentDueDate(),


            /*
            |--------------------------------------------------------------------------
            | Penalty / Interest
            |--------------------------------------------------------------------------
            */

            'penalty_enabled' => $this->penalty_enabled,

            'interest_enabled' => $this->interest_enabled,


            /*
            |--------------------------------------------------------------------------
            | Lizz Policy
            |--------------------------------------------------------------------------
            |
            | Global policy used when an assessment has:
            |
            |     first_installment_required = true
            |
            | Example:
            |
            |     total Lizz amount = 44,400 ETB
            |     percentage = 30%
            |     first installment = 13,320 ETB
            |
            | This is a global reusable setting.
            |
            */

            'lizz_first_installment_percentage' =>
                $this->lizz_first_installment_percentage !== null
                    ? (float) $this->lizz_first_installment_percentage
                    : null,


            /*
            |--------------------------------------------------------------------------
            | Assessment
            |--------------------------------------------------------------------------
            */

            'assessment_auto_calculation' =>
                $this->assessment_auto_calculation,

            'assessment_allow_manual_adjustment' =>
                $this->assessment_allow_manual_adjustment,

            'assessment_requires_approval' =>
                $this->assessment_requires_approval,

            'assessment_reassessment_allowed' =>
                $this->assessment_reassessment_allowed,


            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice_auto_numbering' => $this->invoice_auto_numbering,

            'invoice_prefix' => $this->invoice_prefix,

            'invoice_allow_overpayment' =>
                $this->invoice_allow_overpayment,

            'invoice_allow_overdue_payment' =>
                $this->invoice_allow_overdue_payment,


            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_confirmation_required' =>
                $this->payment_confirmation_required,

            'payment_auto_receipt' =>
                $this->payment_auto_receipt,

            'enabled_payment_methods' =>
                $this->enabledPaymentMethods(),


            /*
            |--------------------------------------------------------------------------
            | Receipt
            |--------------------------------------------------------------------------
            */

            'receipt_auto_numbering' =>
                $this->receipt_auto_numbering,

            'receipt_prefix' =>
                $this->receipt_prefix,

            'receipt_allow_reprint' =>
                $this->receipt_allow_reprint,


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

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}

