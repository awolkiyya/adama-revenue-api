<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Models\BankTransferDetail;
use App\Models\CashReconciliation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Database\Eloquent\Builder;

final class DashboardWorkQueueService
{
    public function __construct(
        private readonly AdministrativeScopeService $administrativeScope,
    ) {}

    /**
     * Return dashboard work items backed by actual records,
     * restricted to the user's accessible administrative units.
     *
     * Bank transfers are scoped through their associated invoices.
     * Cash reconciliations are scoped through their responsible collectors.
     *
     * @return array{
     *     total: int,
     *     items: list<array{
     *         key: string,
     *         title: string,
     *         description: string,
     *         count: int,
     *         href: string
     *     }>
     * }
     */
    public function summarize(User $user): array
    {
        $items = [];

        /*
        |--------------------------------------------------------------------------
        | Bank Transfers Awaiting Verification
        |--------------------------------------------------------------------------
        */

        $pendingBankTransfers = BankTransferDetail::query()
            ->where('verification_status', 'PENDING')
            ->whereHas(
                'payment',
                function (Builder $paymentQuery) use ($user): void {
                    $paymentQuery->whereHas(
                        'invoice',
                        function (Builder $invoiceQuery) use ($user): void {
                            $this->applyInvoiceAdministrativeScope(
                                $invoiceQuery,
                                $user,
                            );
                        },
                    );
                },
            )
            ->count();

        $this->appendItem(
            items: $items,
            key: 'pending_bank_transfers',
            title: 'Bank transfers awaiting verification',
            description: 'Bank transfers requiring verification.',
            count: $pendingBankTransfers,
            href: '/payments/bank-transfers/pending-verification',
        );

        /*
        |--------------------------------------------------------------------------
        | Cash Reconciliations Awaiting Review
        |--------------------------------------------------------------------------
        |
        | CashReconciliation has a collector() relationship, not a payment()
        | relationship. Scope the reconciliation using the collector's
        | administrative-unit assignment.
        |
        */

        $pendingCashReconciliations = CashReconciliation::query()
            ->where(
                'status',
                CashReconciliation::STATUS_PENDING_REVIEW,
            )
            ->whereHas(
                'collector',
                function (Builder $collectorQuery) use ($user): void {
                    $this->administrativeScope->applyAdministrativeScope(
                        $collectorQuery,
                        $user,
                        'users.administrative_unit_id',
                    );
                },
            )
            ->count();

        $this->appendItem(
            items: $items,
            key: 'pending_cash_reconciliations',
            title: 'Cash reconciliations awaiting review',
            description: 'Submitted cash reconciliations requiring review.',
            count: $pendingCashReconciliations,
            href: '/cash-reconciliations/pending-review',
        );

        return [
            'total' => array_sum(
                array_column($items, 'count'),
            ),
            'items' => $items,
        ];
    }

    /**
     * Apply administrative scope to an invoice query.
     *
     * @param Builder<Invoice> $query
     */
    private function applyInvoiceAdministrativeScope(
        Builder $query,
        User $user,
    ): void {
        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'invoices.administrative_unit_id',
        );
    }

    /**
     * Add a work item only when it has pending records.
     *
     * @param list<array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     count: int,
     *     href: string
     * }> $items
     */
    private function appendItem(
        array &$items,
        string $key,
        string $title,
        string $description,
        int $count,
        string $href,
    ): void {
        if ($count <= 0) {
            return;
        }

        $items[] = [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'href' => $href,
        ];
    }
}