<?php

declare(strict_types=1);

namespace App\Modules\RevenueReport\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueCollectionTransactionResource extends JsonResource
{
    /**
     * Transform a payment into a report transaction.
     */
    public function toArray(Request $request): array
    {
        $paymentMethod = $this->enumValue(
            $this->payment_method
        );

        $paymentStatus = $this->enumValue(
            $this->status
        );

        $paymentProvider = $this->enumValue(
            $this->payment_provider
        );

        $isCompleted = $paymentStatus === 'COMPLETED';

        $isCash = $paymentMethod === 'CASH';

        $reconciliation = $this->whenLoaded(
            'cashReconciliation'
        );

        $reconciliationStatus = $reconciliation
            ? $this->enumValue($reconciliation->status)
            : null;

        $isReconciled = $isCash
            && $isCompleted
            && $reconciliationStatus === 'RECONCILED';

        $isNonCashCollected = !$isCash && $isCompleted;

        $isOfficiallyCollected = $isReconciled
            || $isNonCashCollected;

        return [
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Payment Identification
            |--------------------------------------------------------------------------
            */

            'payment_number' => $this->payment_number,

            'transaction_reference' => $this->transaction_reference,

            /*
            |--------------------------------------------------------------------------
            | Invoice Information
            |--------------------------------------------------------------------------
            */

            'invoice' => [
                'id' => $this->invoice_id,

                'number' => $this->whenLoaded(
                    'invoice',
                    fn () => $this->invoice?->invoice_number
                ),

                'source_type' => $this->whenLoaded(
                    'invoice',
                    fn () => $this->invoice?->source_type
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Payer Snapshot
            |--------------------------------------------------------------------------
            */

            'payer' => [
                'name' => $this->payer_name,

                'email' => $this->payer_email,

                'phone' => $this->payer_phone,
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Classification
            |--------------------------------------------------------------------------
            */

            'payment_method' => $paymentMethod,

            'payment_provider' => $paymentProvider,

            'payment_source' => $this->payment_source,

            'status' => $paymentStatus,

            /*
            |--------------------------------------------------------------------------
            | Cash Reconciliation
            |--------------------------------------------------------------------------
            |
            | This information is available when the controller/service
            | explicitly eager-loads the cashReconciliation relationship.
            |
            */

            'reconciliation' => $this->whenLoaded(
                'cashReconciliation',
                fn () => $reconciliation
                    ? [
                        'id' => $reconciliation->id,

                        'status' => $reconciliationStatus,

                        'period_start' => $reconciliation->period_start
                            ? $reconciliation->period_start->toDateString()
                            : null,

                        'period_end' => $reconciliation->period_end
                            ? $reconciliation->period_end->toDateString()
                            : null,
                    ]
                    : null
            ),

            /*
            |--------------------------------------------------------------------------
            | Collection Recognition
            |--------------------------------------------------------------------------
            */

            'collection_status' => [
                'is_cash' => $isCash,

                'is_completed' => $isCompleted,

                'is_reconciled' => $isReconciled,

                'is_officially_collected' => $isOfficiallyCollected,

                'recognition_status' => match (true) {
                    !$isCompleted => 'NOT_COMPLETED',

                    !$isCash => 'COLLECTED',

                    $reconciliationStatus === 'RECONCILED'
                        => 'RECONCILED',

                    $reconciliationStatus === 'DISCREPANCY'
                        => 'DISCREPANCY',

                    $reconciliationStatus === 'CANCELLED'
                        => 'CANCELLED',

                    $reconciliationStatus === 'RETURNED'
                        => 'RETURNED',

                    $reconciliationStatus === 'PENDING_REVIEW'
                        => 'PENDING_REVIEW',

                    $reconciliationStatus === 'DRAFT'
                        => 'DRAFT',

                    default => 'UNRECONCILED',
                },
            ],

            /*
            |--------------------------------------------------------------------------
            | Financial Information
            |--------------------------------------------------------------------------
            */

            'amount' => $this->amount !== null
                ? number_format(
                    (float) $this->amount,
                    2,
                    '.',
                    ''
                )
                : '0.00',

            'currency' => $this->currency,

            /*
            |--------------------------------------------------------------------------
            | Processing Information
            |--------------------------------------------------------------------------
            */

            'processed_by' => $this->processed_by,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),

            'report_date' => $this->created_at?->toDateString(),
        ];
    }

    /**
     * Support backed enums and plain database strings.
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}