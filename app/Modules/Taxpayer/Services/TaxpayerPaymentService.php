<?php

namespace App\Modules\Taxpayer\Services;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TaxpayerPaymentService
{
    /**
     * Get paginated payment history for a taxpayer.
     *
     * Supports:
     * - Search by payment number.
     * - Search by transaction reference.
     * - Search by invoice number.
     * - Search by bank transfer reference.
     * - Search by online checkout reference.
     * - Search by online provider transaction ID.
     * - Filter by payment status.
     * - Explicit pagination.
     *
     * @param string      $citizenId
     * @param int         $perPage
     * @param string|null $search
     * @param string|null $status
     * @param int         $page
     */
    public function paginate(
        string $citizenId,
        int $perPage = 15,
        ?string $search = null,
        ?string $status = null,
        int $page = 1,
    ): LengthAwarePaginator {
        /*
        |--------------------------------------------------------------------------
        | Base Query
        |--------------------------------------------------------------------------
        |
        | Always restrict results to the authenticated taxpayer.
        |
        */

        $query = Payment::query()
            ->where('citizen_id', $citizenId)
            ->with([
                'invoice',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Search Payments
        |--------------------------------------------------------------------------
        |
        | Method-specific references are searched through their detail
        | relationships instead of assuming they exist on payments.
        |
        */

        $search = $search !== null
            ? trim($search)
            : null;

        if ($search !== null && $search !== '') {
            $searchTerm = '%' . $search . '%';

            $query->where(
                function (Builder $builder) use ($searchTerm) {
                    $builder
                        ->where(
                            'payment_number',
                            'like',
                            $searchTerm
                        )
                        ->orWhere(
                            'transaction_reference',
                            'like',
                            $searchTerm
                        )
                        ->orWhereHas(
                            'invoice',
                            function (Builder $invoiceQuery) use ($searchTerm) {
                                $invoiceQuery->where(
                                    'invoice_number',
                                    'like',
                                    $searchTerm
                                );
                            }
                        )
                        ->orWhereHas(
                            'bankTransferDetails',
                            function (Builder $detailQuery) use ($searchTerm) {
                                $detailQuery->where(
                                    'transfer_reference',
                                    'like',
                                    $searchTerm
                                );
                            }
                        )
                        ->orWhereHas(
                            'onlineDetails',
                            function (Builder $detailQuery) use ($searchTerm) {
                                $detailQuery
                                    ->where(
                                        'checkout_reference',
                                        'like',
                                        $searchTerm
                                    )
                                    ->orWhere(
                                        'provider_transaction_id',
                                        'like',
                                        $searchTerm
                                    );
                            }
                        );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter by Payment Status
        |--------------------------------------------------------------------------
        */

        if ($status !== null && trim($status) !== '') {
            $query->where(
                'status',
                strtoupper(trim($status))
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = min(max($perPage, 1), 100);
        $page = max($page, 1);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );
    }

    /**
     * Find a payment belonging to a specific taxpayer.
     *
     * Returns only payments owned by the supplied citizen.
     *
     * @throws ModelNotFoundException
     */
    public function findForTaxpayer(
        string $citizenId,
        string $paymentId
    ): Payment {
        $payment = Payment::query()
            ->where('id', $paymentId)
            ->where('citizen_id', $citizenId)
            ->with([
                'invoice',
            ])
            ->first();

        if (! $payment) {
            throw new ModelNotFoundException(
                'Payment not found for this taxpayer.'
            );
        }

        return $payment;
    }
}