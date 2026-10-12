<?php

namespace App\Modules\Taxpayer\Services;

use App\Models\Invoice;
use App\Models\Payment;

class TaxpayerDashboardService
{
    /**
     * Get dashboard data for the authenticated taxpayer.
     *
     * Financial totals are read from the authoritative
     * invoice and payment records.
     *
     * This service does not calculate tariff amounts.
     */
    public function getDashboard(string $citizenId): array
    {
        $invoiceQuery = Invoice::query()
            ->where('citizen_id', $citizenId);

        $outstandingStatuses = [
            'ISSUED',
            'PARTIALLY_PAID',
            'OVERDUE',
        ];

        $totalOutstanding = (clone $invoiceQuery)
            ->whereIn('status', $outstandingStatuses)
            ->sum('balance_due');

        $totalInvoices = (clone $invoiceQuery)
            ->count();

        $outstandingInvoices = (clone $invoiceQuery)
            ->whereIn('status', $outstandingStatuses)
            ->count();

        $paidInvoices = (clone $invoiceQuery)
            ->where('status', 'PAID')
            ->count();

        /*
         * Count only successfully completed payments.
         *
         * Do not include pending, processing, failed,
         * cancelled, or expired payments.
         */
        $completedPaymentsQuery = Payment::query()
            ->where('citizen_id', $citizenId)
            ->where('status', 'COMPLETED');

        $totalPaid = (clone $completedPaymentsQuery)
            ->sum('amount');

        $recentInvoices = Invoice::query()
            ->where('citizen_id', $citizenId)
            ->with([
                'items.service',
            ])
            ->latest('created_at')
            ->limit(5)
            ->get();

        /*
         * Use created_at instead of payment_date because
         * payment_date does not exist in the reported schema.
         */
        $recentPayments = (clone $completedPaymentsQuery)
            ->latest('created_at')
            ->limit(5)
            ->get();

        $overdueInvoices = Invoice::query()
            ->where('citizen_id', $citizenId)
            ->where('status', 'OVERDUE')
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $dueSoonInvoices = Invoice::query()
            ->where('citizen_id', $citizenId)
            ->whereIn('status', [
                'ISSUED',
                'PARTIALLY_PAID',
            ])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [
                now()->toDateString(),
                now()->addDays(7)->toDateString(),
            ])
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        return [
            'summary' => [
                'total_invoices' => $totalInvoices,
                'outstanding_invoices' => $outstandingInvoices,
                'paid_invoices' => $paidInvoices,
                'total_outstanding' => $totalOutstanding,
                'total_paid' => $totalPaid,
                'currency' => 'ETB',
            ],

            'recent_invoices' => $recentInvoices,
            'recent_payments' => $recentPayments,
            'overdue_invoices' => $overdueInvoices,
            'due_soon_invoices' => $dueSoonInvoices,
        ];
    }
}
