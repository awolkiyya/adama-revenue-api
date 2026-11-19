<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Notifications;

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AssessmentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int|string $assessmentId,
        public readonly string $assessmentNumber,
        public readonly ?string $approvedBy = null,
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
            'type' => NotificationType::ASSESSMENT_APPROVED->value,
            'module' => 'assessment',
            'title' => 'Assessment Approved',
            'message' => "Assessment {$this->assessmentNumber} has been approved.",
            'priority' => NotificationPriority::HIGH->value,
            'data' => [
                'assessment_id' => $this->assessmentId,
                'assessment_number' => $this->assessmentNumber,
                'approved_by' => $this->approvedBy,
            ],
            'action_url' => "/en/office/assessments/{$this->assessmentId}",
        ];
    }
}
