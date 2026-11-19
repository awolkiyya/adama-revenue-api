<?php

declare(strict_types=1);

namespace App\Modules\RevenueReport\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueCollectionReportService
{
    private const PAYMENT_CASH = 'CASH';

    private const PAYMENT_COMPLETED = 'COMPLETED';

    private const RECONCILIATION_RECONCILED = 'RECONCILED';

    private const RECONCILIATION_DISCREPANCY = 'DISCREPANCY';

    /**
     * Generate an aggregated Revenue Collection Report.
     *
     * Officially collected revenue consists of:
     *
     * 1. Completed non-cash payments.
     * 2. Completed cash payments covered by a RECONCILED
     *    cash reconciliation.
     *
     * Cash under DISCREPANCY, DRAFT, PENDING_REVIEW, RETURNED,
     * or without a reconciliation is not officially collected.
     *
     * No individual payment transactions are returned.
     */
    public function generate(array $filters): array
    {
        $startDate = $filters['start_date']
            ?? now()->startOfYear()->toDateString();

        $endDate = $filters['end_date']
            ?? now()->toDateString();

        $start = Carbon::createFromFormat('Y-m-d', $startDate);
        $end = Carbon::createFromFormat('Y-m-d', $endDate);

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'The start date must be on or before the end date.',
                ],
            ]);
        }

        $filters['start_date'] = $start->toDateString();
        $filters['end_date'] = $end->toDateString();

        return [
            'summary' => $this->summary($filters),

            'daily_collections' => $this->dailyCollections($filters),

            'payment_methods' => $this->groupedPayments(
                $filters,
                'payment_method'
            ),

            'payment_sources' => $this->groupedPayments(
                $filters,
                'payment_source'
            ),

            'revenue_domains' => $this->revenueDomains($filters),

            'administrative_units' => $this->administrativeUnitBreakdown(
                $filters
            ),
        ];
    }

    /**
     * Build the common payment query.
     *
     * No invoice_items or cash_reconciliations joins are performed here.
     *
     * This prevents duplicate payment records when invoices contain
     * multiple services or when reconciliation records are involved.
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Payment::query()
            ->where(
                'payments.created_at',
                '>=',
                $filters['start_date'] . ' 00:00:00'
            )
            ->where(
                'payments.created_at',
                '<',
                Carbon::parse($filters['end_date'])
                    ->addDay()
                    ->toDateString() . ' 00:00:00'
            );

        if (!empty($filters['payment_method'])) {
            $query->where(
                'payments.payment_method',
                $filters['payment_method']
            );
        }

        if (!empty($filters['payment_provider'])) {
            $query->where(
                'payments.payment_provider',
                $filters['payment_provider']
            );
        }

        if (!empty($filters['payment_source'])) {
            $query->where(
                'payments.payment_source',
                $filters['payment_source']
            );
        }

        if (!empty($filters['status'])) {
            $query->where(
                'payments.status',
                $filters['status']
            );
        }

        if (!empty($filters['administrative_unit_id'])) {
            $query->whereHas(
                'invoice',
                function (Builder $invoiceQuery) use ($filters) {
                    $invoiceQuery->where(
                        'invoices.administrative_unit_id',
                        $filters['administrative_unit_id']
                    );
                }
            );
        }

        /*
         * Classification filters select payments whose invoice contains
         * at least one matching service.
         *
         * They do not allocate a payment between multiple services
         * or revenue domains.
         */
        if (
            !empty($filters['revenue_domain'])
            || !empty($filters['revenue_category_id'])
            || !empty($filters['service_id'])
        ) {
            $query->whereHas(
                'invoice.items.service.revenueCode.category',
                function (Builder $categoryQuery) use ($filters) {
                    if (!empty($filters['revenue_domain'])) {
                        $categoryQuery->where(
                            'revenue_categories.revenue_domain',
                            $filters['revenue_domain']
                        );
                    }

                    if (!empty($filters['revenue_category_id'])) {
                        $categoryQuery->where(
                            'revenue_categories.id',
                            $filters['revenue_category_id']
                        );
                    }
                }
            );

            if (!empty($filters['service_id'])) {
                $query->whereHas(
                    'invoice.items',
                    function (Builder $itemQuery) use ($filters) {
                        $itemQuery->where(
                            'invoice_items.service_id',
                            $filters['service_id']
                        );
                    }
                );
            }
        }

        return $query;
    }

    /**
     * Determine whether a completed cash payment belongs to
     * a successfully reconciled cash reconciliation.
     *
     * The reconciliation must:
     *
     * - Belong to the same collector as payments.processed_by.
     * - Have status RECONCILED.
     * - Cover the payment's creation date.
     *
     * Uses EXISTS to prevent duplicate payment records.
     */
    private function hasReconciledCash(): \Closure
    {
        return function ($query) {
            $query->whereExists(
                function ($reconciliationQuery) {
                    $reconciliationQuery
                        ->selectRaw('1')
                        ->from(
                            'cash_reconciliations as report_reconciliations'
                        )
                        ->whereColumn(
                            'report_reconciliations.collector_id',
                            'payments.processed_by'
                        )
                        ->where(
                            'report_reconciliations.status',
                            self::RECONCILIATION_RECONCILED
                        )
                        ->whereRaw(
                            'payments.created_at >= report_reconciliations.period_start'
                        )
                        ->whereRaw(
                            "payments.created_at < (
                                report_reconciliations.period_end
                                + INTERVAL '1 day'
                            )"
                        );
                }
            );
        };
    }

    /**
     * Determine whether a completed cash payment belongs to
     * a reconciliation reviewed with DISCREPANCY status.
     */
    private function hasDiscrepancyCash(): \Closure
    {
        return function ($query) {
            $query->whereExists(
                function ($reconciliationQuery) {
                    $reconciliationQuery
                        ->selectRaw('1')
                        ->from(
                            'cash_reconciliations as report_reconciliations'
                        )
                        ->whereColumn(
                            'report_reconciliations.collector_id',
                            'payments.processed_by'
                        )
                        ->where(
                            'report_reconciliations.status',
                            self::RECONCILIATION_DISCREPANCY
                        )
                        ->whereRaw(
                            'payments.created_at >= report_reconciliations.period_start'
                        )
                        ->whereRaw(
                            "payments.created_at < (
                                report_reconciliations.period_end
                                + INTERVAL '1 day'
                            )"
                        );
                }
            );
        };
    }

    /**
     * Build the officially collected payment query.
     *
     * Includes:
     *
     * - Completed non-cash payments.
     * - Completed reconciled cash payments.
     *
     * Excludes:
     *
     * - Pending and failed payments.
     * - Unreconciled cash.
     * - Discrepancy cash.
     *
     * The original filters are preserved.
     */
    private function collectedQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->where(
                'payments.status',
                self::PAYMENT_COMPLETED
            )
            ->where(function (Builder $query) {
                $query
                    ->where(function (Builder $nonCashQuery) {
                        $nonCashQuery
                            ->where(
                                'payments.payment_method',
                                '<>',
                                self::PAYMENT_CASH
                            )
                            ->whereNotNull('payments.payment_method');
                    })
                    ->orWhere(function (Builder $cashQuery) {
                        $cashQuery
                            ->where(
                                'payments.payment_method',
                                self::PAYMENT_CASH
                            )
                            ->where(
                                $this->hasReconciledCash()
                            );
                    });
            });
    }

    /**
     * Build the completed cash payment query.
     */
    private function completedCashQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->where(
                'payments.status',
                self::PAYMENT_COMPLETED
            )
            ->where(
                'payments.payment_method',
                self::PAYMENT_CASH
            );
    }

    /**
     * Build the completed non-cash payment query.
     */
    private function completedNonCashQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->where(
                'payments.status',
                self::PAYMENT_COMPLETED
            )
            ->where(
                'payments.payment_method',
                '<>',
                self::PAYMENT_CASH
            )
            ->whereNotNull('payments.payment_method');
    }

    /**
     * Summarize payments and cash reconciliation figures.
     *
     * total_collected:
     * Completed non-cash + reconciled cash.
     *
     * unreconciled_cash:
     * Completed cash without RECONCILED or DISCREPANCY reconciliation.
     *
     * discrepancy_cash:
     * Completed cash belonging to DISCREPANCY reconciliations.
     */
    private function summary(array $filters): array
    {
        /*
         * General payment transaction statistics.
         *
         * These statistics include all payment statuses.
         */
        $row = $this->baseQuery($filters)
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'COMPLETED'
                ) AS completed_count
            ")
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'PENDING'),
                    0
                ) AS pending_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'PENDING'
                ) AS pending_count
            ")
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'PROCESSING'),
                    0
                ) AS processing_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'PROCESSING'
                ) AS processing_count
            ")
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'FAILED'),
                    0
                ) AS failed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'FAILED'
                ) AS failed_count
            ")
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'REVERSED'),
                    0
                ) AS reversed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'REVERSED'
                ) AS reversed_count
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'CANCELLED'
                ) AS cancelled_count
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'EXPIRED'
                ) AS expired_count
            ")
            ->selectRaw('COUNT(*) AS total_transactions')
            ->first();

        /*
         * Officially collected revenue.
         */
        $collected = $this->collectedQuery($filters)
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS total_collected'
            )
            ->selectRaw(
                'COUNT(*) AS collected_count'
            )
            ->first();

        /*
         * Reconciled cash.
         */
        $reconciledCash = $this->completedCashQuery($filters)
            ->where($this->hasReconciledCash())
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS amount'
            )
            ->selectRaw(
                'COUNT(*) AS payment_count'
            )
            ->first();

        /*
         * Unreconciled cash.
         *
         * Excludes both RECONCILED and DISCREPANCY cash.
         *
         * Includes:
         * - No reconciliation.
         * - DRAFT.
         * - PENDING_REVIEW.
         * - RETURNED.
         */
        $unreconciledCash = $this->completedCashQuery($filters)
            ->where(function (Builder $query) {
                $query
                    ->whereNot(
                        $this->hasReconciledCash()
                    )
                    ->whereNot(
                        $this->hasDiscrepancyCash()
                    );
            })
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS amount'
            )
            ->selectRaw(
                'COUNT(*) AS payment_count'
            )
            ->first();

        /*
         * Discrepancy cash.
         *
         * Reported separately and excluded from official collections.
         */
        $discrepancyCash = $this->completedCashQuery($filters)
            ->where($this->hasDiscrepancyCash())
            ->whereNot($this->hasReconciledCash())
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS amount'
            )
            ->selectRaw(
                'COUNT(*) AS payment_count'
            )
            ->first();

        /*
         * Completed non-cash collections.
         */
        $nonCash = $this->completedNonCashQuery($filters)
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS amount'
            )
            ->selectRaw(
                'COUNT(*) AS payment_count'
            )
            ->first();

        $totalCollected = (float) $collected->total_collected;
        $collectedCount = (int) $collected->collected_count;

        return [
            /*
             * Officially recognized collections.
             */
            'total_collected' => $this->money(
                $totalCollected
            ),

            'collected_count' => $collectedCount,

            /*
             * Cash reconciliation breakdown.
             */
            'reconciled_cash' => $this->money(
                (float) $reconciledCash->amount
            ),

            'reconciled_cash_count' => (int) $reconciledCash->payment_count,

            'unreconciled_cash' => $this->money(
                (float) $unreconciledCash->amount
            ),

            'unreconciled_cash_count' => (int) $unreconciledCash->payment_count,

            'discrepancy_cash' => $this->money(
                (float) $discrepancyCash->amount
            ),

            'discrepancy_cash_count' => (int) $discrepancyCash->payment_count,

            /*
             * Non-cash collections.
             */
            'non_cash_collected' => $this->money(
                (float) $nonCash->amount
            ),

            'non_cash_collected_count' => (int) $nonCash->payment_count,

            /*
             * General payment transaction statistics.
             */
            'total_transactions' => (int) $row->total_transactions,

            'completed_count' => (int) $row->completed_count,

            'pending_amount' => $this->money(
                (float) $row->pending_amount
            ),

            'pending_count' => (int) $row->pending_count,

            'processing_amount' => $this->money(
                (float) $row->processing_amount
            ),

            'processing_count' => (int) $row->processing_count,

            'failed_amount' => $this->money(
                (float) $row->failed_amount
            ),

            'failed_count' => (int) $row->failed_count,

            'reversed_amount' => $this->money(
                (float) $row->reversed_amount
            ),

            'reversed_count' => (int) $row->reversed_count,

            'cancelled_count' => (int) $row->cancelled_count,

            'expired_count' => (int) $row->expired_count,

            /*
             * Average payment is based on officially collected payments,
             * not all completed transactions.
             */
            'average_payment' => $this->money(
                $collectedCount > 0
                    ? $totalCollected / $collectedCount
                    : 0
            ),
        ];
    }

    /**
     * Return officially collected revenue grouped by day or month.
     *
     * Periods longer than 62 days are grouped by month.
     */
    private function dailyCollections(array $filters): array
    {
        $start = Carbon::parse($filters['start_date']);
        $end = Carbon::parse($filters['end_date']);

        $groupByMonth = $start->diffInDays($end) > 62;

        $periodExpression = $groupByMonth
            ? "TO_CHAR(payments.created_at, 'YYYY-MM')"
            : "TO_CHAR(payments.created_at, 'YYYY-MM-DD')";

        return $this->collectedQuery($filters)
            ->selectRaw("{$periodExpression} AS period")
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS completed_amount'
            )
            ->selectRaw('COUNT(*) AS completed_count')
            ->groupByRaw($periodExpression)
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,

                'completed_amount' => $this->money(
                    (float) $row->completed_amount
                ),

                'completed_count' => (int) $row->completed_count,
            ])
            ->all();
    }

    /**
     * Group officially collected payments by an allowed payment column.
     */
    private function groupedPayments(
        array $filters,
        string $column
    ): array {
        $allowedColumns = [
            'payment_method',
            'payment_source',
        ];

        if (!in_array($column, $allowedColumns, true)) {
            return [];
        }

        return $this->collectedQuery($filters)
            ->selectRaw(
                "COALESCE(payments.{$column}::text, 'UNKNOWN') AS label"
            )
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS completed_amount'
            )
            ->selectRaw('COUNT(*) AS completed_count')
            ->groupBy("payments.{$column}")
            ->orderBy('label')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,

                'completed_amount' => $this->money(
                    (float) $row->completed_amount
                ),

                'completed_count' => (int) $row->completed_count,
            ])
            ->all();
    }

    /**
     * Group officially collected payments by revenue domain.
     *
     * Only invoices whose non-deleted services resolve to exactly
     * one distinct revenue domain are included.
     *
     * Multi-domain invoices are deliberately excluded until
     * an authoritative payment allocation rule exists.
     */
    private function revenueDomains(array $filters): array
    {
        $singleDomainInvoices = DB::table('invoice_items')
            ->join(
                'revenue_services',
                'revenue_services.id',
                '=',
                'invoice_items.service_id'
            )
            ->join(
                'revenue_codes',
                'revenue_codes.id',
                '=',
                'revenue_services.revenue_code_id'
            )
            ->join(
                'revenue_categories',
                'revenue_categories.id',
                '=',
                'revenue_codes.category_id'
            )
            ->whereNull('invoice_items.deleted_at')
            ->whereNull('revenue_services.deleted_at')
            ->whereNull('revenue_codes.deleted_at')
            ->whereNull('revenue_categories.deleted_at')
            ->select('invoice_items.invoice_id')
            ->selectRaw(
                'MIN(revenue_categories.revenue_domain::text) AS revenue_domain'
            )
            ->groupBy('invoice_items.invoice_id')
            ->havingRaw(
                'COUNT(DISTINCT revenue_categories.revenue_domain) = 1'
            );

        return $this->collectedQuery($filters)
            ->joinSub(
                $singleDomainInvoices,
                'report_single_domain',
                function ($join) {
                    $join->on(
                        'payments.invoice_id',
                        '=',
                        'report_single_domain.invoice_id'
                    );
                }
            )
            ->select('report_single_domain.revenue_domain')
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS completed_amount'
            )
            ->selectRaw('COUNT(*) AS completed_count')
            ->groupBy('report_single_domain.revenue_domain')
            ->orderBy('report_single_domain.revenue_domain')
            ->get()
            ->map(fn ($row) => [
                'revenue_domain' => $row->revenue_domain,

                'completed_amount' => $this->money(
                    (float) $row->completed_amount
                ),

                'completed_count' => (int) $row->completed_count,
            ])
            ->all();
    }

    /**
     * Summarize officially collected payments by invoice
     * administrative unit.
     *
     * Administrative unit is obtained through the invoice.
     * cash_reconciliations has no administrative_unit_id column.
     */
    private function administrativeUnitBreakdown(array $filters): array
    {
        return $this->collectedQuery($filters)
            ->leftJoin(
                'invoices as report_unit_invoices',
                'report_unit_invoices.id',
                '=',
                'payments.invoice_id'
            )
            ->leftJoin(
                'administrative_units as report_units',
                'report_units.id',
                '=',
                'report_unit_invoices.administrative_unit_id'
            )
            ->selectRaw(
                'report_units.id AS administrative_unit_id'
            )
            ->selectRaw("
                COALESCE(
                    report_units.name,
                    'Unassigned'
                ) AS administrative_unit_name
            ")
            ->selectRaw(
                'COALESCE(SUM(payments.amount), 0) AS completed_amount'
            )
            ->selectRaw('COUNT(*) AS completed_count')
            ->groupBy(
                'report_units.id',
                'report_units.name'
            )
            ->orderByDesc('completed_amount')
            ->get()
            ->map(fn ($row) => [
                'administrative_unit_id' => $row->administrative_unit_id,

                'administrative_unit_name' => $row->administrative_unit_name,

                'completed_amount' => $this->money(
                    (float) $row->completed_amount
                ),

                'completed_count' => (int) $row->completed_count,
            ])
            ->all();
    }

    /**
     * Format monetary values consistently.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
