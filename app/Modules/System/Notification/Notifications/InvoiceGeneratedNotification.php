<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Notifications;

use App\Modules\System\Notification\Enums\NotificationPriority;
use App\Modules\System\Notification\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class InvoiceGeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int|string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $amount,
        public readonly string $currency = 'ETB',
        public readonly ?string $dueDate = null,
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
            'type' => NotificationType::INVOICE_GENERATED->value,
            'module' => 'invoice',
            'title' => 'New Invoice Generated',
            'message' => "Invoice {$this->invoiceNumber} has been generated successfully.",
            'priority' => NotificationPriority::NORMAL->value,
            'data' => [
                'invoice_id' => $this->invoiceId,
                'invoice_number' => $this->invoiceNumber,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'due_date' => $this->dueDate,
            ],
            'action_url' => "/en/taxpayer/invoices/{$this->invoiceId}",
        ];
    }
}
