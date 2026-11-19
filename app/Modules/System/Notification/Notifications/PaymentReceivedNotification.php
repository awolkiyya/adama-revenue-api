<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Notifications;

use App\Modules\System\Notification\Enums\NotificationPriority;
use App\Modules\System\Notification\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int|string $paymentId,
        public readonly string $reference,
        public readonly string $amount,
        public readonly string $currency = 'ETB',
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => NotificationType::PAYMENT_RECEIVED->value,
            'module' => 'payment',
            'title' => 'Payment Received',
            'message' => "Payment {$this->reference} has been successfully received.",
            'priority' => NotificationPriority::NORMAL->value,
            'data' => [
                'payment_id' => $this->paymentId,
                'reference' => $this->reference,
                'amount' => $this->amount,
                'currency' => $this->currency,
            ],
            'action_url' => "/en/office/payments/{$this->paymentId}",
        ];
    }
}
