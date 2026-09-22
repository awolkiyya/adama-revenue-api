<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectCollectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The underlying resource is an Invoice created and issued
     * through the Direct Collection workflow.
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        $invoice->loadMissing([
            'citizen',
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

                'amount' => $this->decimalOrNull(
                    $invoice->amount
                ),

                'due_date' => $this->dateOrNull(
                    $invoice->due_date
                ),

                'issued_at' => $this->dateOrNull(
                    $invoice->issued_at
                ),

                'created_at' => $invoice->created_at?->toISOString(),
                'updated_at' => $invoice->updated_at?->toISOString(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Taxpayer / Citizen
            |--------------------------------------------------------------------------
            */

            'taxpayer' => $citizen
                ? [
                    'id' => $citizen->id,
                    'name' => $this->resolveCitizenName($citizen),
                    'phone' => $citizen->phone ?? null,
                    'email' => $citizen->email ?? null,
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
            | Items
            |--------------------------------------------------------------------------
            */

            'items' => $items,

            /*
            |--------------------------------------------------------------------------
            | Summary
            |--------------------------------------------------------------------------
            */

            'summary' => [
                'item_count' => $items->count(),

                'amount' => $this->decimalOrNull(
                    $invoice->amount
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
     * Return a decimal value as a string.
     *
     * Keeping monetary values as strings avoids floating-point
     * precision problems in the API response.
     */
    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return number_format(
                (float) $value,
                4,
                '.',
                ''
            );
        }

        return (string) $value;
    }

    /**
     * Normalize a date/datetime value.
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

    /**
     * Resolve the citizen's display name without assuming
     * one specific naming convention.
     */
    private function resolveCitizenName(object $citizen): ?string
    {
        $fullName = collect([
            $citizen->first_name ?? null,
            $citizen->middle_name ?? null,
            $citizen->last_name ?? null,
        ])
            ->filter(
                fn ($value): bool =>
                    $value !== null
                    && trim((string) $value) !== ''
            )
            ->map(
                fn ($value): string =>
                    trim((string) $value)
            )
            ->implode(' ');

        if ($fullName !== '') {
            return $fullName;
        }

        if (
            isset($citizen->name)
            && trim((string) $citizen->name) !== ''
        ) {
            return trim((string) $citizen->name);
        }

        if (
            isset($citizen->full_name)
            && trim((string) $citizen->full_name) !== ''
        ) {
            return trim((string) $citizen->full_name);
        }

        return null;
    }
}