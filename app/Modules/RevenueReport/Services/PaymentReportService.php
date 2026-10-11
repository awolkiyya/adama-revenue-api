<?php

namespace App\Modules\RevenueReport\Services;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PaymentReportService
{
    /**
     * Allowed payment report sorting columns.
     */
    private const SORTABLE_COLUMNS = [
        'created_at' => 'payments.created_at',
        'amount' => 'payments.amount',
        'status' => 'payments.status',
    ];

    /**
     * Relationships required by the payment report.
     */
    private const REPORT_RELATIONSHIPS = [
        'invoice',
        'invoice.citizen',
        'processedBy',
        'verifiedBy',
        'bankTransferDetails.bankAccount',
    ];

    /**
     * Return a filtered, paginated payment report.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with(self::REPORT_RELATIONSHIPS);

        $this->applyFilters($query, $filters);

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $requestedSort = (string) (
            $filters['sort_by'] ?? 'created_at'
        );

        $sortColumn = self::SORTABLE_COLUMNS[$requestedSort]
            ?? self::SORTABLE_COLUMNS['created_at'];

        $sortDirection = strtolower(
            (string) ($filters['sort_direction'] ?? 'desc')
        );

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortColumn, $sortDirection);

        // Stable secondary sort for consistent pagination.
        $query->orderByDesc('payments.id');

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = max(
            1,
            min((int) ($filters['per_page'] ?? 15), 100)
        );

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Return one payment with its report relationships.
     */
    public function findById(int|string $id): Payment
    {
        return Payment::query()
            ->with(self::REPORT_RELATIONSHIPS)
            ->findOrFail($id);
    }

    /**
     * Return summary statistics for the selected report filters.
     *
     * Status, search, pagination, and sorting are intentionally
     * excluded from the summary.
     *
     * Date, payment method, provider, source, administrative unit,
     * revenue category, and service filters remain active.
     */
    public function summary(array $filters): array
    {
        $summaryFilters = $filters;

        unset(
            $summaryFilters['status'],
            $summaryFilters['search'],
            $summaryFilters['per_page'],
            $summaryFilters['page'],
            $summaryFilters['sort_by'],
            $summaryFilters['sort_direction']
        );

        $query = Payment::query();

        $this->applyFilters($query, $summaryFilters);

        $result = $query
            ->selectRaw("
                COUNT(*) AS total_transactions,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'COMPLETED'
                        THEN payments.amount
                        ELSE 0
                    END
                ), 0) AS completed_amount,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'PENDING'
                        THEN payments.amount
                        ELSE 0
                    END
                ), 0) AS pending_amount,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'PROCESSING'
                        THEN payments.amount
                        ELSE 0
                    END
                ), 0) AS processing_amount,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'COMPLETED'
                        THEN 1 ELSE 0
                    END
                ), 0) AS completed_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'PENDING'
                        THEN 1 ELSE 0
                    END
                ), 0) AS pending_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'PROCESSING'
                        THEN 1 ELSE 0
                    END
                ), 0) AS processing_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'FAILED'
                        THEN 1 ELSE 0
                    END
                ), 0) AS failed_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'CANCELLED'
                        THEN 1 ELSE 0
                    END
                ), 0) AS cancelled_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'EXPIRED'
                        THEN 1 ELSE 0
                    END
                ), 0) AS expired_count,

                COALESCE(SUM(
                    CASE
                        WHEN payments.status = 'REVERSED'
                        THEN 1 ELSE 0
                    END
                ), 0) AS reversed_count
            ")
            ->firstOrFail();

        return [
            'total_transactions' => (int) $result->total_transactions,

            'completed_count' => (int) $result->completed_count,
            'completed_amount' => (string) $result->completed_amount,

            'pending_count' => (int) $result->pending_count,
            'pending_amount' => (string) $result->pending_amount,

            'processing_count' => (int) $result->processing_count,
            'processing_amount' => (string) $result->processing_amount,

            'failed_count' => (int) $result->failed_count,
            'cancelled_count' => (int) $result->cancelled_count,
            'expired_count' => (int) $result->expired_count,
            'reversed_count' => (int) $result->reversed_count,

            'currency' => 'ETB',
        ];
    }

    /**
     * Apply payment and classification filters.
     */
    private function applyFilters(
        Builder $query,
        array $filters
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Reporting Period
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['start_date'])) {
            $query->whereDate(
                'payments.created_at',
                '>=',
                $filters['start_date']
            );
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate(
                'payments.created_at',
                '<=',
                $filters['end_date']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Status
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['status'])) {
            $query->where(
                'payments.status',
                $filters['status']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Method
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['payment_method'])) {
            $query->where(
                'payments.payment_method',
                $filters['payment_method']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Provider
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['payment_provider'])) {
            $query->where(
                'payments.payment_provider',
                $filters['payment_provider']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Source
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['payment_source'])) {
            $query->where(
                'payments.payment_source',
                $filters['payment_source']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['administrative_unit_id'])) {
            $administrativeUnitId =
                $filters['administrative_unit_id'];

            $query->whereHas(
                'invoice',
                function (Builder $invoiceQuery) use (
                    $administrativeUnitId
                ): void {
                    $invoiceQuery->where(
                        'invoices.administrative_unit_id',
                        $administrativeUnitId
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Category
        |--------------------------------------------------------------------------
        |
        | invoice_items.service_id
        |   -> revenue_services.revenue_code_id
        |   -> revenue_codes.category_id
        |
        | Requires Invoice::items(), InvoiceItem::service(),
        | and RevenueService::revenueCode().
        */

        if (! empty($filters['revenue_category_id'])) {
            $revenueCategoryId =
                $filters['revenue_category_id'];

            $query->whereHas(
                'invoice',
                function (Builder $invoiceQuery) use (
                    $revenueCategoryId
                ): void {
                    $invoiceQuery->whereHas(
                        'items',
                        function (Builder $itemQuery) use (
                            $revenueCategoryId
                        ): void {
                            $itemQuery->whereHas(
                                'service',
                                function (Builder $serviceQuery) use (
                                    $revenueCategoryId
                                ): void {
                                    $serviceQuery->whereHas(
                                        'revenueCode',
                                        function (
                                            Builder $revenueCodeQuery
                                        ) use (
                                            $revenueCategoryId
                                        ): void {
                                            $revenueCodeQuery->where(
                                                'category_id',
                                                $revenueCategoryId
                                            );
                                        }
                                    );
                                }
                            );
                        }
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Service
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['service_id'])) {
            $serviceId = $filters['service_id'];

            $query->whereHas(
                'invoice',
                function (Builder $invoiceQuery) use (
                    $serviceId
                ): void {
                    $invoiceQuery->whereHas(
                        'items',
                        function (Builder $itemQuery) use (
                            $serviceId
                        ): void {
                            $itemQuery->where(
                                'invoice_items.service_id',
                                $serviceId
                            );
                        }
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (isset($filters['search'])) {
            $search = trim((string) $filters['search']);

            if ($search !== '') {
                $searchTerm = '%' . $search . '%';

                $query->where(
                    function (Builder $searchQuery) use (
                        $search,
                        $searchTerm
                    ): void {
                        $searchQuery
                            ->where(
                                'payments.payment_number',
                                'like',
                                $searchTerm
                            )
                            ->orWhere(
                                'payments.transaction_reference',
                                'like',
                                $searchTerm
                            );

                        if (
                            preg_match(
                                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                                $search
                            ) === 1
                        ) {
                            $searchQuery->orWhere(
                                'payments.id',
                                '=',
                                $search
                            );
                        }
                    }
                );
            }
        }
    }
}
