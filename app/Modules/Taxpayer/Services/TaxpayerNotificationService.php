<?php

namespace App\Modules\Taxpayer\Services;

use App\Models\Citizen;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TaxpayerNotificationService
{
    public function paginate(
        Citizen $citizen,
        int $perPage = 20
    ): LengthAwarePaginator {
        return $citizen
            ->notifications()
            ->latest()
            ->paginate($perPage);
    }

    public function markAsRead(
        Citizen $citizen,
        string $notificationId
    ): void {
        $notification = $citizen
            ->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->markAsRead();
    }

    public function markAllAsRead(
        Citizen $citizen
    ): void {
        $citizen
            ->unreadNotifications()
            ->update([
                'read_at' => now(),
            ]);
    }
}