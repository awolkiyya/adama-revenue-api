<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceAccrualService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateInvoiceOverdueBalanceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * ============================================================
     * QUEUE CONFIGURATION
     * ============================================================
     */
    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [
        60,
        300,
    ];

    /**
     * ============================================================
     * CONSTRUCTOR
     * ============================================================
     */
    public function __construct(
        public string $invoiceId,
        public string $asOfDate,
    ) {
    }

    /**
     * ============================================================
     * HANDLE
     * ============================================================
     */
    public function handle(
        InvoiceAccrualService $invoiceAccrualService,
    ): void {
        $asOfDate = Carbon::createFromFormat(
            'Y-m-d',
            $this->asOfDate,
        )->startOfDay();

        /*
         * --------------------------------------------------------
         * LOAD FRESH INVOICE
         * --------------------------------------------------------
         */
        $invoice = Invoice::query()
            ->find($this->invoiceId);

        if (! $invoice) {
            Log::warning(
                'Invoice overdue balance job skipped because invoice was not found.',
                [
                    'invoice_id' => $this->invoiceId,
                    'as_of_date' => $asOfDate->toDateString(),
                ],
            );

            return;
        }

        /*
         * --------------------------------------------------------
         * VALIDATE INVOICE STATUS
         * --------------------------------------------------------
         */
        if (! in_array(
            $invoice->status,
            [
                'ISSUED',
                'PARTIALLY_PAID',
                'OVERDUE',
            ],
            true
        )) {
            Log::info(
                'Invoice overdue balance job skipped because invoice status is not eligible.',
                [
                    'invoice_id' => $invoice->id,
                    'status' => $invoice->status,
                    'as_of_date' => $asOfDate->toDateString(),
                ],
            );

            return;
        }

        /*
         * --------------------------------------------------------
         * VALIDATE DUE DATE
         * --------------------------------------------------------
         */
        if (! $invoice->due_date) {
            Log::warning(
                'Invoice overdue balance job skipped because invoice has no due date.',
                [
                    'invoice_id' => $invoice->id,
                    'as_of_date' => $asOfDate->toDateString(),
                ],
            );

            return;
        }

        $dueDate = Carbon::parse(
            $invoice->due_date
        )->startOfDay();

        /*
         * --------------------------------------------------------
         * CHECK WHETHER INVOICE IS ACTUALLY OVERDUE
         * --------------------------------------------------------
         *
         * Same rule used by the command:
         *
         *     asOfDate > dueDate
         *
         * The due date itself is not overdue.
         */
        if (! $asOfDate->gt($dueDate)) {
            Log::info(
                'Invoice overdue balance job skipped because invoice is not yet overdue.',
                [
                    'invoice_id' => $invoice->id,
                    'due_date' => $dueDate->toDateString(),
                    'as_of_date' => $asOfDate->toDateString(),
                ],
            );

            return;
        }

        /*
         * --------------------------------------------------------
         * ACCRUE PENALTY + INTEREST
         * --------------------------------------------------------
         *
         * InvoiceAccrualService is responsible for:
         *
         * - invoice item calculation
         * - penalty calculation
         * - interest calculation
         * - invoice totals
         * - balance due
         * - invoice status
         */
        $updatedInvoice = $invoiceAccrualService->accrue(
            invoice: $invoice->id,
            asOfDate: $asOfDate,
        );

        /*
         * --------------------------------------------------------
         * SUCCESS LOG
         * --------------------------------------------------------
         */
        Log::info(
            'Invoice overdue balance calculated successfully.',
            [
                'invoice_id' => $updatedInvoice->id,
                'due_date' => $dueDate->toDateString(),
                'as_of_date' => $asOfDate->toDateString(),
                'penalty_amount' => $updatedInvoice->penalty_amount,
                'interest_amount' => $updatedInvoice->interest_amount,
                'total_amount' => $updatedInvoice->total_amount,
                'paid_amount' => $updatedInvoice->paid_amount,
                'balance_due' => $updatedInvoice->balance_due,
                'status' => $updatedInvoice->status,
            ],
        );
    }

    /**
     * ============================================================
     * FAILED
     * ============================================================
     */
    public function failed(Throwable $exception): void
    {
        Log::error(
            'Invoice overdue balance job failed permanently.',
            [
                'invoice_id' => $this->invoiceId,
                'as_of_date' => $this->asOfDate,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }
}