<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashReconciliation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RevenueService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DirectCollectionDashboardService
{
    private const TIMEZONE = 'Africa/Addis_Ababa';

    private const MAX_REVENUE_SERVICES = 8;

    private const DIRECT_COLLECTION_SOURCE = 'DIRECT_COLLECTION';

    /**
     * Get the complete direct collection dashboard.
     */
    public function getDashboard(User $user): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        $startOfDay = $now->startOfDay();
        $endOfDay = $now->endOfDay();

        $invoiceSummary = $this->todayInvoiceSummary(
            $user,
            $startOfDay,
            $endOfDay
        );

        $paymentSummary = $this->todayPaymentSummary(
            $user,
            $startOfDay,
            $endOfDay
        );

        $todayCashCollected = $this->todayCashCollected(
            $user,
            $startOfDay,
            $endOfDay
        );

        $expectedCash = $this->expectedCash($user);

        $reconciledCash = $this->reconciledCash($user);

        $outstandingCash = bcsub(
            $expectedCash,
            $reconciledCash,
            2
        );

        return [
            'summary' => [
                'today_invoice_count' => $invoiceSummary['count'],
                'today_invoice_total' => $invoiceSummary['total'],

                'today_payment_count' => $paymentSummary['count'],
                'today_payment_total' => $paymentSummary['total'],

                'today_cash_collected' => $todayCashCollected,

                'expected_cash' => $expectedCash,
                'reconciled_cash' => $reconciledCash,
                'outstanding_cash' => $outstandingCash,
            ],

            'revenue_services' => $this->accessibleRevenueServices(),

            'period' => [
                'date' => $now->toDateString(),
                'timezone' => self::TIMEZONE,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Today's Invoice Summary
    |--------------------------------------------------------------------------
    */

    /**
     * Get today's direct collection invoice count and monetary total.
     *
     * An invoice is attributed to the collector when either created_by
     * or issued_by matches the authenticated user's ID.
     *
     * The OR condition ensures each invoice is counted only once.
     */
    private function todayInvoiceSummary(
        User $user,
        CarbonImmutable $startOfDay,
        CarbonImmutable $endOfDay
    ): array {
        $summary = Invoice::query()
            ->where('source_type', self::DIRECT_COLLECTION_SOURCE)
            ->whereBetween('issued_at', [
                $startOfDay,
                $endOfDay,
            ])
            ->whereNotIn('status', [
                'CANCELLED',
                'VOID',
            ])
            ->where(function (Builder $query) use ($user) {
                $query
                    ->where('created_by', $user->id)
                    ->orWhere('issued_by', $user->id);
            })
            ->selectRaw('
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS invoice_total
            ')
            ->first();

        return [
            'count' => (int) ($summary->invoice_count ?? 0),
            'total' => $this->money($summary->invoice_total ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Today's Payment Summary
    |--------------------------------------------------------------------------
    */

    /**
     * Get today's successfully completed direct collection payments.
     *
     * Includes CASH, BANK_TRANSFER and ONLINE payments.
     *
     * A payment is attributed to the collector when:
     * - payments.processed_by matches the collector; OR
     * - its invoice was created_by or issued_by the collector.
     *
     * verified_at is used as the payment completion timestamp.
     */
    private function todayPaymentSummary(
        User $user,
        CarbonImmutable $startOfDay,
        CarbonImmutable $endOfDay
    ): array {
        $summary = Payment::query()
            ->where('status', PaymentStatus::COMPLETED->value)
            ->whereHas('invoice', function (Builder $query) {
                $query->where(
                    'source_type',
                    self::DIRECT_COLLECTION_SOURCE
                );
            })
            ->whereBetween('verified_at', [
                $startOfDay,
                $endOfDay,
            ])
            ->where(function (Builder $query) use ($user) {
                $query
                    ->where('processed_by', $user->id)
                    ->orWhereHas('invoice', function (
                        Builder $invoiceQuery
                    ) use ($user) {
                        $invoiceQuery->where(function (
                            Builder $attributionQuery
                        ) use ($user) {
                            $attributionQuery
                                ->where('created_by', $user->id)
                                ->orWhere('issued_by', $user->id);
                        });
                    });
            })
            ->selectRaw('
                COUNT(*) AS payment_count,
                COALESCE(SUM(amount), 0) AS payment_total
            ')
            ->first();

        return [
            'count' => (int) ($summary->payment_count ?? 0),
            'total' => $this->money($summary->payment_total ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Today's Physical Cash Collection
    |--------------------------------------------------------------------------
    */

    /**
     * Get successful physical cash payments received by the collector today.
     *
     * CashPaymentDetail.received_by identifies the physical receiver.
     * CashPaymentDetail.cash_received_at identifies the collection date.
     */
    private function todayCashCollected(
        User $user,
        CarbonImmutable $startOfDay,
        CarbonImmutable $endOfDay
    ): string {
        $total = Payment::query()
            ->where('status', PaymentStatus::COMPLETED->value)
            ->where('payment_method', PaymentMethod::CASH->value)
            ->whereHas('invoice', function (Builder $query) {
                $query->where(
                    'source_type',
                    self::DIRECT_COLLECTION_SOURCE
                );
            })
            ->whereHas('cashDetails', function (
                Builder $query
            ) use ($user, $startOfDay, $endOfDay) {
                $query
                    ->where('received_by', $user->id)
                    ->whereBetween('cash_received_at', [
                        $startOfDay,
                        $endOfDay,
                    ]);
            })
            ->sum('amount');

        return $this->money($total);
    }

    /*
    |--------------------------------------------------------------------------
    | Expected Cash
    |--------------------------------------------------------------------------
    */

    /**
     * Get the cumulative expected physical cash received by this collector.
     *
     * This is an all-time cumulative amount.
     *
     * Only successfully completed direct collection CASH payments
     * attributed through cash_payment_details.received_by are included.
     */
    private function expectedCash(User $user): string
    {
        $total = Payment::query()
            ->where('status', PaymentStatus::COMPLETED->value)
            ->where('payment_method', PaymentMethod::CASH->value)
            ->whereHas('invoice', function (Builder $query) {
                $query->where(
                    'source_type',
                    self::DIRECT_COLLECTION_SOURCE
                );
            })
            ->whereHas('cashDetails', function (
                Builder $query
            ) use ($user) {
                $query->where('received_by', $user->id);
            })
            ->sum('amount');

        return $this->money($total);
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciled Cash
    |--------------------------------------------------------------------------
    */

    /**
     * Get actual cash recorded in reconciled records for this collector.
     *
     * Only RECONCILED records contribute to this total.
     */
    private function reconciledCash(User $user): string
    {
        $total = CashReconciliation::query()
            ->where('collector_id', $user->id)
            ->where(
                'status',
                CashReconciliation::STATUS_RECONCILED
            )
            ->sum('actual_amount');

        return $this->money($total);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessible Revenue Services
    |--------------------------------------------------------------------------
    */

    /**
     * Get up to eight active revenue services that support field collection.
     *
     * NOTE:
     * This currently selects eligible services, not the most popular ones.
     * Popularity ranking requires the actual invoice-to-service relationship.
     */
    private function accessibleRevenueServices(): Collection
    {
        return RevenueService::query()
            ->where('is_active', true)
            ->whereIn('collection_mode', [
                'FIELD_COLLECTION',
                'BOTH',
            ])
            ->orderBy('name')
            ->limit(self::MAX_REVENUE_SERVICES)
            ->get([
                'id',
                'revenue_code_id',
                'name',
                'service_type',
                'collection_mode',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Money Formatting
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize monetary aggregate values to two decimal places.
     */
    private function money(
        int|float|string|null $amount
    ): string {
        return bcadd(
            (string) ($amount ?? '0'),
            '0',
            2
        );
    }
}
