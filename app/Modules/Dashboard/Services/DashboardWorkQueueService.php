<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Models\BankTransferDetail;
use App\Models\CashReconciliation;

final class DashboardWorkQueueService
{
    /**
     * Return dashboard work items backed by actual records.
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
    public function summarize(): array
    {
        $items = [];

        // Bank transfers awaiting verification.
        $pendingBankTransfers = BankTransferDetail::query()
            ->where('verification_status', 'PENDING')
            ->count();

        $this->appendItem(
            items: $items,
            key: 'pending_bank_transfers',
            title: 'Bank transfers awaiting verification',
            description: 'Bank transfers requiring verification.',
            count: $pendingBankTransfers,
            href: '/payments/bank-transfers/pending-verification',
        );

        // Cash reconciliations awaiting review.
        $pendingCashReconciliations = CashReconciliation::query()
            ->where(
                'status',
                CashReconciliation::STATUS_PENDING_REVIEW,
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
