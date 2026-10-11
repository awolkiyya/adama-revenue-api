<?php

namespace App\Modules\RevenueReport\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueCollectionReportService
{
    /**
     * Generate an aggregated Revenue Collection Report.
     *
     * This report does not return individual payment transactions.
     *
     * Collected revenue is calculated from COMPLETED payments only.
     * Invoice-item totals are not treated as collected revenue.
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
     * No invoice_items join is performed here, preventing a payment
     * from being duplicated when an invoice contains multiple services.
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
         * at least one matching service. They do not allocate a payment
         * between multiple services or revenue domains.
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
     * Summarize payment amounts and counts by status.
     *
     * total_collected includes COMPLETED payments only.
     */
    private function summary(array $filters): array
    {
        $row = $this->baseQuery($filters)
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'COMPLETED'),
                    0
                ) AS total_collected
            ")
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

        $totalCollected = (float) $row->total_collected;
        $completedCount = (int) $row->completed_count;

        return [
            'total_collected' => $this->money($totalCollected),
            'total_transactions' => (int) $row->total_transactions,

            'completed_count' => $completedCount,

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

            'average_payment' => $this->money(
                $completedCount > 0
                    ? $totalCollected / $completedCount
                    : 0
            ),
        ];
    }

    /**
     * Return completed collections grouped by day or month.
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

        return $this->baseQuery($filters)
            ->selectRaw("{$periodExpression} AS period")
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'COMPLETED'),
                    0
                ) AS completed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'COMPLETED'
                ) AS completed_count
            ")
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
     * Group payments by an explicitly allowed payment column.
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

        return $this->baseQuery($filters)
            ->selectRaw(
                "COALESCE(payments.{$column}::text, 'UNKNOWN') AS label"
            )
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'COMPLETED'),
                    0
                ) AS completed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'COMPLETED'
                ) AS completed_count
            ")
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
     * Group completed payments by revenue domain.
     *
     * To avoid counting one payment in multiple domains, only invoices
     * whose non-deleted services resolve to exactly one distinct domain
     * are included in this breakdown.
     *
     * This is not a payment allocation. Multi-domain invoices are
     * deliberately excluded from this breakdown until an authoritative
     * allocation rule exists.
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

        $query = $this->baseQuery($filters)
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
            );

        return $query
            ->select('report_single_domain.revenue_domain')
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'COMPLETED'),
                    0
                ) AS completed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'COMPLETED'
                ) AS completed_count
            ")
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
     * Summarize completed payments by invoice administrative unit.
     */
    private function administrativeUnitBreakdown(array $filters): array
    {
        return $this->baseQuery($filters)
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
            ->selectRaw("
                COALESCE(
                    SUM(payments.amount)
                        FILTER (WHERE payments.status = 'COMPLETED'),
                    0
                ) AS completed_amount
            ")
            ->selectRaw("
                COUNT(*) FILTER (
                    WHERE payments.status = 'COMPLETED'
                ) AS completed_count
            ")
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