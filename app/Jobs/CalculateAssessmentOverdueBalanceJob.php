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

    /**
     * Maximum number of attempts.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 120;

    /**
     * Retry delays.
     *
     * 1st retry: 60 seconds
     * 2nd retry: 300 seconds
     */
    public array $backoff = [
        60,
        300,
    ];


    /**
     * ============================================================
     * CONSTRUCTOR
     * ============================================================
     *
     * Store only the invoice UUID and calculation date.
     *
     * Do not serialize the complete Invoice model because the
     * financial state must be loaded fresh when the worker runs.
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

        /*
         * --------------------------------------------------------
         * NORMALIZE DATE
         * --------------------------------------------------------
         */

        $asOfDate = Carbon::createFromFormat(
            'Y-m-d',
            $this->asOfDate,
        )->startOfDay();


        /*
         * --------------------------------------------------------
         * LOAD FRESH INVOICE
         * --------------------------------------------------------
         *
         * We intentionally load the invoice at job execution time
         * rather than relying on stale data from when the command
         * dispatched the job.
         */

        $invoice = Invoice::query()
            ->find($this->invoiceId);


        /*
         * --------------------------------------------------------
         * INVOICE MAY HAVE BEEN DELETED
         * --------------------------------------------------------
         */

        if (! $invoice) {

            Log::warning(
                'Revenue invoice overdue balance job skipped because invoice was not found.',
                [
                    'invoice_id' => $this->invoiceId,
                    'as_of_date' => $this->asOfDate,
                ],
            );

            return;
        }


        /*
         * --------------------------------------------------------
         * RE-CHECK INVOICE STATUS
         * --------------------------------------------------------
         *
         * Accrual applies only to active receivables.
         *
         * DRAFT:
         *     Not issued yet.
         *
         * CANCELLED:
         *     Financial obligation cancelled.
         *
         * VOID:
         *     Financial document voided.
         *
         * ISSUED:
         *     Active unpaid invoice.
         *
         * PARTIALLY_PAID:
         *     Active invoice with remaining balance.
         *
         * OVERDUE:
         *     Active invoice whose due date has passed.
         *
         * PAID:
         *     No remaining balance.
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
                'Revenue invoice overdue balance job skipped because invoice is not financially active.',
                [
                    'invoice_id' => $invoice->id,
                    'status' => $invoice->status,
                    'as_of_date' => $this->asOfDate,
                ],
            );

            return;
        }


        /*
         * --------------------------------------------------------
         * CHECK DUE DATE
         * --------------------------------------------------------
         *
         * There is no reason to perform an overdue accrual before
         * the invoice due date.
         *
         * The invoice due date is immutable and represents the
         * legally applicable payment deadline.
         */

        if (! $invoice->due_date) {

            Log::warning(
                'Revenue invoice overdue balance job skipped because invoice has no due date.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'as_of_date' => $this->asOfDate,
                ],
            );

            return;
        }


        $dueDate = Carbon::parse(
            $invoice->due_date
        )->startOfDay();


        /*
         * --------------------------------------------------------
         * NOT YET OVERDUE
         * --------------------------------------------------------
         */

        if (! $asOfDate->gt($dueDate)) {

            Log::info(
                'Revenue invoice overdue balance job skipped because invoice is not yet overdue.',
                [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'due_date' => $dueDate->toDateString(),
                    'as_of_date' => $this->asOfDate,
                ],
            );

            return;
        }


        /*
         * --------------------------------------------------------
         * ACCRUE CURRENT PENALTY AND INTEREST
         * --------------------------------------------------------
         *
         * InvoiceAccrualService is responsible for:
         *
         * - locking the invoice
         * - calculating penalty
         * - calculating interest
         * - updating invoice items
         * - aggregating invoice totals
         * - recalculating balance_due
         * - updating invoice status
         *
         * The operation is idempotent.
         */

        $updatedInvoice =
            $invoiceAccrualService->accrue(
                invoice: $invoice->id,
                asOfDate: $asOfDate,
            );


        /*
         * --------------------------------------------------------
         * TECHNICAL LOG
         * --------------------------------------------------------
         *
         * This is a system calculation, not a user business action.
         */

        Log::info(
            'Revenue invoice overdue balance calculated and persisted.',
            [
                'invoice_id' =>
                    $updatedInvoice->id,

                'invoice_number' =>
                    $updatedInvoice->invoice_number,

                'as_of_date' =>
                    $asOfDate->toDateString(),

                'due_date' =>
                    $updatedInvoice->due_date
                        ? Carbon::parse(
                            $updatedInvoice->due_date
                        )->toDateString()
                        : null,

                'subtotal' =>
                    $updatedInvoice->subtotal,

                'penalty' =>
                    $updatedInvoice->penalty_amount,

                'interest' =>
                    $updatedInvoice->interest_amount,

                'total_amount' =>
                    $updatedInvoice->total_amount,

                'paid_amount' =>
                    $updatedInvoice->paid_amount,

                'balance_due' =>
                    $updatedInvoice->balance_due,

                'status' =>
                    $updatedInvoice->status,
            ],
        );
    }


    /**
     * ============================================================
     * FAILED JOB
     * ============================================================
     *
     * Laravel calls this after the job permanently fails.
     */
    public function failed(
        Throwable $exception
    ): void {

        Log::error(
            'Revenue invoice overdue balance job permanently failed.',
            [
                'invoice_id' =>
                    $this->invoiceId,

                'as_of_date' =>
                    $this->asOfDate,

                'exception' =>
                    $exception::class,

                'message' =>
                    $exception->getMessage(),
            ],
        );
    }
}