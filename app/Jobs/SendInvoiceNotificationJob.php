<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Modules\Invoice\Notifications\InvoiceNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendInvoiceNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 120;

    /**
     * Delay between retry attempts.
     */
    public int $backoff = 30;

    public function __construct(
        public readonly string $invoiceId,
    ) {
        $this->onQueue('invoice-notifications');
    }

    /**
     * Execute the queued notification.
     */
    public function handle(
        InvoiceNotificationService $notificationService,
    ): void {
        $invoice = Invoice::query()
            ->with('citizen')
            ->find($this->invoiceId);

        if (! $invoice) {
            Log::warning(
                'Invoice SMS notification skipped because the invoice was not found.',
                [
                    'invoice_id' => $this->invoiceId,
                ]
            );

            return;
        }

        Log::info(
            'Starting invoice SMS notification.',
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'attempt' => $this->attempts(),
            ]
        );

        $notificationService->sendInvoiceIssued($invoice);

        Log::info(
            'Invoice SMS notification processing completed.',
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]
        );
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical(
            'Invoice SMS notification permanently failed.',
            [
                'invoice_id' => $this->invoiceId,
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]
        );
    }
}
