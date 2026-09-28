<?php

namespace App\Modules\PaymentSchedule\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentScheduleResource extends JsonResource
{
    /**
     * Transform the payment schedule into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | IDENTIFICATION
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'assessmentServiceId' =>
                $this->assessment_service_id,

            'installmentNumber' =>
                $this->installment_number,


            /*
            |--------------------------------------------------------------------------
            | APPLIED RULE SNAPSHOT
            |--------------------------------------------------------------------------
            |
            | Stores the percentage that was actually applied when this
            | payment schedule was generated.
            |
            | This is a historical snapshot and does not read the current
            | revenue-code payment schedule rule.
            |
            | Example:
            |
            |     installmentNumber = 1
            |     rulePercentage = 10.00
            |
            | means that the 10% first-installment rule was applied when
            | this schedule was generated.
            |
            | NULL means that no percentage rule was applied to this
            | installment.
            |
            */

            'rulePercentage' =>
                $this->rule_percentage,


            /*
            |--------------------------------------------------------------------------
            | PAYMENT OBLIGATION
            |--------------------------------------------------------------------------
            |
            | amountDue is the authoritative amount payable for this
            | installment.
            |
            | The resource does not calculate tariffs, percentages,
            | penalties, interest, discounts, or installment allocations.
            |
            */

            'dueDate' =>
                $this->due_date?->toDateString(),

            'amountDue' =>
                $this->amount_due,

            'amountPaid' =>
                $this->amount_paid,

            'remainingAmount' =>
                $this->remainingAmount(),


            /*
            |--------------------------------------------------------------------------
            | STATUS
            |--------------------------------------------------------------------------
            */

            'status' =>
                $this->status?->value ?? $this->status,

            'paidAt' =>
                $this->paid_at?->toISOString(),


            /*
            |--------------------------------------------------------------------------
            | INVOICE
            |--------------------------------------------------------------------------
            |
            | payment_schedules does not contain invoice_id.
            |
            | Relationship:
            |
            | PaymentSchedule
            |      ↓
            | InvoiceItem.payment_schedule_id
            |      ↓
            | Invoice
            |
            | The invoice summary is returned only when invoiceItems
            | (and their invoice relation) have been eager loaded.
            |
            */

            'invoice' => $this->when(
                $this->relationLoaded('invoiceItems'),
                function () {
                    $invoiceItem = $this->invoiceItems
                        ->filter(
                            fn ($item) => $item->invoice !== null
                        )
                        ->first();

                    if (! $invoiceItem?->invoice) {
                        return null;
                    }

                    return [
                        'id' =>
                            $invoiceItem->invoice->id,

                        'invoiceNumber' =>
                            $invoiceItem->invoice->invoice_number,

                        'status' =>
                            $invoiceItem->invoice->status?->value
                            ?? $invoiceItem->invoice->status,
                    ];
                }
            ),


            /*
            |--------------------------------------------------------------------------
            | SYSTEM
            |--------------------------------------------------------------------------
            */

            'createdAt' =>
                $this->created_at?->toISOString(),

            'updatedAt' =>
                $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Calculate the unpaid principal remaining on this schedule.
     *
     * This does NOT include:
     *
     * - penalty
     * - interest
     * - discount
     *
     * Those belong to the financial/billing layer.
     */
    protected function remainingAmount(): string
    {
        $amountDue = (float) ($this->amount_due ?? 0);

        $amountPaid = (float) ($this->amount_paid ?? 0);

        return number_format(
            max($amountDue - $amountPaid, 0),
            4,
            '.',
            ''
        );
    }
}