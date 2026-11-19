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
     * Maximum number of attempts.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 120;

    /**
     * Delay between failed attempts in seconds.
     */
    public int $backoff = 30;

    public function __construct(
        public readonly string $paymentId,
        public readonly string $notificationType,
    ) {
        $this->onQueue('payment-notifications');
    }

    /**
     * Execute the payment notification.
     */
    public function handle(
        PaymentNotificationService $notificationService,
    ): void {
        $payment = Payment::query()
            ->with([
                'invoice',
                'citizen',
                'receipt',
                'onlineDetails',
                'bankTransferDetails',
            ])
            ->find($this->paymentId);

        if ($payment === null) {
            Log::warning(
                'Payment notification skipped: payment not found.',
                [
                    'payment_id' => $this->paymentId,
                    'notification_type' => $this->notificationType,
                ]
            );

            return;
        }

        Log::info('Payment notification job started.', [
            'payment_id' => $payment->getKey(),
            'payment_number' => $payment->payment_number,
            'notification_type' => $this->notificationType,
            'payment_status' =>
                $payment->status?->value ?? $payment->status,
        ]);

        $notificationService->send(
            payment: $payment,
            notificationType: $this->notificationType,
        );

        Log::info('Payment notification job completed.', [
            'payment_id' => $payment->getKey(),
            'payment_number' => $payment->payment_number,
            'notification_type' => $this->notificationType,
        ]);
    }

    /**
     * Handle permanent job failure.
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
