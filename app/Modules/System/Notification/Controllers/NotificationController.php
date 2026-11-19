<?php

declare(strict_types=1);

namespace App\Modules\System\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\System\Notification\Requests\IndexNotificationRequest;
use App\Modules\System\Notification\Resources\NotificationResource;
use App\Modules\System\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * List the authenticated user's notifications.
     */
    public function index(
        IndexNotificationRequest $request,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        $notifications = $this->notificationService->index(
            $user,
            $request->filters(),
        );

        return NotificationResource::collection($notifications);
    }

    /**
     * Get the authenticated user's unread notification count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Unread notification count retrieved successfully.',
            'data' => [
                'unread_count' => $this->notificationService->unreadCount($user),
            ],
        ]);
    }

    /**
     * Retrieve a specific notification.
     */
    public function show(
        Request $request,
        string $notificationId,
    ): NotificationResource {
        /** @var User $user */
        $user = $request->user();

        $notification = $this->notificationService->find(
            $user,
            $notificationId,
        );

        return new NotificationResource($notification);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(
        Request $request,
        string $notificationId,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $notification = $this->notificationService->markAsRead(
            $user,
            $notificationId,
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read successfully.',
            'data' => new NotificationResource($notification),
        ]);
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updatedCount = $this->notificationService->markAllAsRead($user);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read successfully.',
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    /**
     * Delete a specific notification.
     */
    public function destroy(
        Request $request,
        string $notificationId,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $this->notificationService->delete(
            $user,
            $notificationId,
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully.',
        ]);
    }

    /**
     * Delete all notifications belonging to the authenticated user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deletedCount = $this->notificationService->deleteAll($user);

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted successfully.',
            'data' => [
                'deleted_count' => $deletedCount,
            ],
        ]);
    }
}
