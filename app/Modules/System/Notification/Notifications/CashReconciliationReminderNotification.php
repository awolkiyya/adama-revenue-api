<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Notifications;

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CashReconciliationReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected array $details
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $dateFrom = $this->details['date_from'] ?? null;
        $dateTo = $this->details['date_to'] ?? null;

        $period = $dateFrom && $dateTo
            ? "{$dateFrom} to {$dateTo}"
            : 'the selected reporting period';

        $outstanding = number_format(
            (float) $this->details['outstanding_total'],
            2
        );

        return [
            'type' => NotificationType::CASH_RECONCILIATION_REMINDER->value,
            'module' => 'cash_reconciliation',

            'title' => 'Outstanding Cash Reconciliation',

            'message' => sprintf(
                'You have outstanding collected cash of ETB %s for %s. Please review your cash reconciliation and complete any outstanding actions.',
                $outstanding,
                $period
            ),

            'priority' => NotificationPriority::HIGH->value,

            'data' => [
                'collector_id' => $this->details['collector_id'],
                'collector_name' => $this->details['collector_name'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,

                'payments_count' => $this->details['payments_count'],

                'expected_total' => $this->details['expected_total'],
                'reconciliations_count' => $this->details['reconciliations_count'],
                'reconciled_total' => $this->details['reconciled_total'],
                'outstanding_total' => $this->details['outstanding_total'],
                'currency' => 'ETB',
            ],

            // Set this after confirming the actual collector web route.
            'action_url' => null,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
