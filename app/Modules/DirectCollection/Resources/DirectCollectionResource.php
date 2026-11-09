<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectCollectionResource extends JsonResource
{
    /**
     * Transform the invoice into a direct collection response.
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        $invoice->loadMissing([
            'citizen',
            'citizen.administrativeUnit',
            'items',
            'items.service',
        ]);

        $citizen = $invoice->citizen;

        $items = $invoice->items->map(
            function ($item): array {
                return [
                    'id' => $item->id,
                    'line_number' => $item->line_number,

                    'service_id' => $item->service_id,
                    'service_name' => $item->service?->name,

                    'description' => $item->description,

                    'quantity' => $this->decimalOrNull(
                        $item->quantity
                    ),

                    'unit' => $item->unit,

                    'unit_price' => $this->decimalOrNull(
                        $item->unit_price
                    ),

                    'amount' => $this->decimalOrNull(
                        $item->amount
                    ),

                    'tariff_version_id' => $item->tariff_version_id,
                    'tariff_rule_id' => $item->tariff_rule_id,
                    'penalty_rule_id' => $item->penalty_rule_id,
                    'interest_rule_id' => $item->interest_rule_id,

                    'input_snapshot' => $item->input_snapshot,
                    'calculation_snapshot' => $item->calculation_snapshot,

                    'created_at' => $item->created_at?->toISOString(),
                    'updated_at' => $item->updated_at?->toISOString(),
                ];
            }
        )->values();

        $totalAmount = $invoice->total_amount ?? '0.0000';
        $paidAmount = $invoice->paid_amount ?? '0.0000';
        $balanceDue = $invoice->balance_due ?? '0.0000';

        return [
            /*
            |--------------------------------------------------------------------------
            | Direct Collection
            |--------------------------------------------------------------------------
            */

            'id' => $invoice->id,
            'source_type' => $invoice->source_type,

            /*
            |--------------------------------------------------------------------------
            | Invoice
            |--------------------------------------------------------------------------
            */

            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,

                'subtotal' => $this->decimalOrNull(
                    $invoice->subtotal
                ),

                'discount_amount' => $this->decimalOrNull(
                    $invoice->discount_amount
                ),

                'penalty_amount' => $this->decimalOrNull(
                    $invoice->penalty_amount
                ),

                'interest_amount' => $this->decimalOrNull(
                    $invoice->interest_amount
                ),

                'total_amount' => $this->decimalOrNull(
                    $invoice->total_amount
                ),

                'paid_amount' => $this->decimalOrNull(
                    $invoice->paid_amount
                ),

                'balance_due' => $this->decimalOrNull(
                    $invoice->balance_due
                ),

                'due_date' => $this->dateOrNull(
                    $invoice->due_date
                ),

                'issued_at' => $this->dateOrNull(
                    $invoice->issued_at
                ),

                'paid_at' => $this->dateOrNull(
                    $invoice->paid_at
                ),

                'created_at' => $invoice->created_at?->toISOString(),
                'updated_at' => $invoice->updated_at?->toISOString(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Taxpayer
            |--------------------------------------------------------------------------
            */

            'taxpayer' => $citizen
                ? [
                    'id' => $citizen->id,
                    'citizen_uid' => $citizen->citizen_uid,
                    'full_name' => $citizen->full_name,

                    'phone' => $citizen->phone,
                    'email' => $citizen->email,

                    'national_id' => $citizen->national_id,
                    'gender' => $citizen->gender,

                    'date_of_birth' => $citizen->date_of_birth
                        ?->toDateString(),

                    'address' => $citizen->address,

                    'administrative_unit' => $citizen->administrativeUnit
                        ? [
                            'id' => $citizen->administrativeUnit->id,
                            'name' => $citizen->administrativeUnit->name,
                            'code' => $citizen->administrativeUnit->code,
                        ]
                        : null,

                    'registered_at' => $citizen->registered_at
                        ?->toISOString(),
                ]
                : null,

            /*
            |--------------------------------------------------------------------------
            | Direct Collection References
            |--------------------------------------------------------------------------
            */

            'taxpayer_id' => $invoice->citizen_id,

            'revenue_service_id' => $items->first()['service_id'] ?? null,

            /*
            |--------------------------------------------------------------------------
            | Invoice Items
            |--------------------------------------------------------------------------
            */

            'items' => $items,

            /*
            |--------------------------------------------------------------------------
            | Payment Summary
            |--------------------------------------------------------------------------
            |
            | Invoice financial totals are authoritative.
            | Do not sum all payment records here.
            |
            */

            'payment_summary' => [
                'total_amount' => $this->decimalOrNull(
                    $totalAmount
                ),

                'paid_amount' => $this->decimalOrNull(
                    $paidAmount
                ),

                'balance_due' => $this->decimalOrNull(
                    $balanceDue
                ),

                'currency' => $invoice->currency,

                'payment_status' => $this->resolvePaymentStatus(
                    $invoice
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Collection Summary
            |--------------------------------------------------------------------------
            */

            'summary' => [
                'item_count' => $items->count(),

                'amount' => $this->decimalOrNull(
                    $totalAmount
                ),

                'currency' => $invoice->currency,

                'status' => $invoice->status,

                'due_date' => $this->dateOrNull(
                    $invoice->due_date
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' => $invoice->source_metadata ?? null,
        ];
    }

    /**
     * Resolve the payment status from authoritative invoice totals.
     */
    private function resolvePaymentStatus(Invoice $invoice): string
    {
        if ($invoice->status === 'CANCELLED') {
            return 'CANCELLED';
        }

        if ($invoice->status === 'VOID') {
            return 'VOID';
        }

        $paidAmount = (string) ($invoice->paid_amount ?? '0.0000');
        $balanceDue = (string) ($invoice->balance_due ?? '0.0000');
        $totalAmount = (string) ($invoice->total_amount ?? '0.0000');

        if (
            bccomp($totalAmount, '0', 4) > 0
            && bccomp($balanceDue, '0', 4) <= 0
        ) {
            return 'PAID';
        }

        if (bccomp($paidAmount, '0', 4) > 0) {
            return 'PARTIALLY_PAID';
        }

        return 'UNPAID';
    }

    /**
     * Preserve decimal precision by returning decimal strings.
     */
    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Normalize date/datetime values.
     */
    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (method_exists($value, 'toISOString')) {
            return $value->toISOString();
        }

        return (string) $value;
    }
}
