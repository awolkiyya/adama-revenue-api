<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Notifications;

use App\Modules\System\Notification\Enums\NotificationPriority;
use App\Modules\System\Notification\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeaseExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int|string $leaseId,
        public readonly string $leaseNumber,
        public readonly string $expirationDate,
        public readonly int $daysRemaining,
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
        $priority = $this->daysRemaining <= 7
            ? NotificationPriority::URGENT
            : NotificationPriority::HIGH;

        return [
            'type' => NotificationType::LEASE_EXPIRING->value,
            'module' => 'lease',
            'title' => 'Lease Expiration Reminder',
            'message' => "Lease {$this->leaseNumber} will expire in {$this->daysRemaining} days.",
            'priority' => $priority->value,
            'data' => [
                'lease_id' => $this->leaseId,
                'lease_number' => $this->leaseNumber,
                'expiration_date' => $this->expirationDate,
                'days_remaining' => $this->daysRemaining,
            ],
            'action_url' => "/en/office/leases/{$this->leaseId}",
        ];
    }
}
