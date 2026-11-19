<?php

declare(strict_types=1);

use App\Modules\System\Notification\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification API Routes
|--------------------------------------------------------------------------
|
| All routes require authentication through Laravel Sanctum.
|
*/

Route::prefix('notifications')
    ->name('notifications.')
    ->controller(NotificationController::class)
    ->group(function (): void {

        // List notifications (all, read, unread).
        Route::get('/', 'index')
            ->name('index');

        // Get unread notification count.
        Route::get('/unread-count', 'unreadCount')
            ->name('unread-count');

        // Mark all notifications as read.
        Route::patch('/read-all', 'markAllAsRead')
            ->name('read-all');

        // Delete all notifications.
        Route::delete('/', 'destroyAll')
            ->name('destroy-all');

        // Get a specific notification.
        Route::get('/{notificationId}', 'show')
            ->whereUuid('notificationId')
            ->name('show');

        // Mark a specific notification as read.
        Route::patch('/{notificationId}/read', 'markAsRead')
            ->whereUuid('notificationId')
            ->name('mark-as-read');

        // Delete a specific notification.
        Route::delete('/{notificationId}', 'destroy')
            ->whereUuid('notificationId')
            ->name('destroy');
    });