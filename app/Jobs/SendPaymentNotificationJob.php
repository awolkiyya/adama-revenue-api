<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Payment;
use App\Modules\Payment\Notifications\PaymentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPaymentNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Maximum number of seconds the job may run.
     */
    public int $timeout = 120;

    /**
     * Number of seconds Laravel waits before retrying a failed attempt.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $notificationType,
    ) {
        $this->onQueue('payment-notifications');
    }

    /**
     * Execute the notification job.
     *
     * The payment is reloaded from the database rather than serialized
     * as a full Eloquent model so the worker always works with current
     * payment information.
     */
    public function handle(
        PaymentNotificationService $notificationService,
    ): void {
        /*
         * Load all relationships required by the notification service.
         *
         * citizen is important because the notification service uses:
         *
         * 1. payment->payer_phone
         * 2. payment->citizen->phone
         *
         * when resolving the taxpayer's phone number.
         */
        $payment = Payment::query()
            ->with([
                'invoice',
                'citizen',
                'receipt',
                'onlineDetails',
                'bankTransferDetails',
            ])
            ->find($this->paymentId);

        /*
         * The payment may have been deleted after the notification
         * was queued. In that case there is nothing to notify.
         */
        if (! $payment) {
            Log::warning(
                'Payment notification job skipped because payment was not found.',
                [
                    'payment_id' => $this->paymentId,
                    'notification_type' => $this->notificationType,
                ]
            );

            return;
        }

        Log::info(
            'Payment notification job started.',
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'notification_type' => $this->notificationType,
                'payment_status' =>
                    $payment->status?->value
                    ?? $payment->status,
                'citizen_id' => $payment->citizen_id,
            ]
        );

        /*
         * The notification service is responsible for:
         *
         * - selecting the correct notification message
         * - resolving the taxpayer phone number
         * - sending the SMS through SmsService
         * - throwing an exception when SMS delivery fails
         *
         * If an exception is thrown, Laravel will retry this job
         * according to $tries and $backoff.
         */
        $notificationService->send(
            payment: $payment,
            notificationType: $this->notificationType,
        );

        Log::info(
            'Payment notification job completed.',
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'notification_type' => $this->notificationType,
            ]
        );
    }

    /**
     * Handle a permanently failed job.
     *
     * This method is called after Laravel exhausts all configured
     * retry attempts.
     */
    public function failed(Throwable $exception): void
    {
        Log::error(
            'Payment notification job permanently failed.',
            [
                'payment_id' => $this->paymentId,
                'notification_type' => $this->notificationType,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]
        );
    }
}
