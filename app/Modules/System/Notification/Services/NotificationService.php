<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    /**
     * Retrieve paginated notifications for the authenticated user.
     */
    public function index(
        User $user,
        array $filters = [],
    ): LengthAwarePaginator {
        $query = $user->notifications();

        $filter = $filters['filter'] ?? 'all';

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );
    }

    /**
     * Retrieve the number of unread notifications.
     */
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Retrieve a notification belonging to the user.
     *
     * @throws ModelNotFoundException
     */
    public function find(
        User $user,
        string $notificationId,
    ): DatabaseNotification {
        return $user->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();
    }

    /**
     * Mark a specific notification as read.
     *
     * @throws ModelNotFoundException
     */
    public function markAsRead(
        User $user,
        string $notificationId,
    ): DatabaseNotification {
        $notification = $this->find($user, $notificationId);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification;
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()->update([
            'read_at' => now(),
        ]);
    }

    /**
     * Delete a specific notification.
     *
     * @throws ModelNotFoundException
     */
    public function delete(
        User $user,
        string $notificationId,
    ): bool {
        $notification = $this->find($user, $notificationId);

        return (bool) $notification->delete();
    }

    /**
     * Delete all notifications belonging to the user.
     */
    public function deleteAll(User $user): int
    {
        return $user->notifications()->delete();
    }
}
