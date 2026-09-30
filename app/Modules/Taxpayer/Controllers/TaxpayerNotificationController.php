<?php

namespace App\Modules\Taxpayer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Taxpayer\Resources\TaxpayerNotificationResource;
use App\Modules\Taxpayer\Services\TaxpayerNotificationService;
use App\Services\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Throwable;

class TaxpayerNotificationController extends Controller
{
    public function __construct(
        private readonly TaxpayerNotificationService $notificationService
    ) {
    }

    public function index(Request $request)
    {
        $citizen = $request->user()?->citizen;

        if (! $citizen) {
            return ApiResponse::forbidden(
                'Authenticated taxpayer is not linked to a citizen.'
            );
        }

        try {
            $perPage = min(
                max((int) $request->input('per_page', 20), 1),
                100
            );

            $notifications = $this->notificationService->paginate(
                citizen: $citizen,
                perPage: $perPage
            );

            return ApiResponse::success(
                data: TaxpayerNotificationResource::collection(
                    $notifications
                ),
                message: 'Notifications retrieved successfully.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    public function markAsRead(
        Request $request,
        string $notification
    ) {
        $citizen = $request->user()?->citizen;

        if (! $citizen) {
            return ApiResponse::forbidden(
                'Authenticated taxpayer is not linked to a citizen.'
            );
        }

        try {
            $this->notificationService->markAsRead(
                citizen: $citizen,
                notificationId: $notification
            );

            return ApiResponse::success(
                message: 'Notification marked as read.'
            );
        } catch (ModelNotFoundException) {
            return ApiResponse::notFound(
                'Notification not found.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }

    public function markAllAsRead(Request $request)
    {
        $citizen = $request->user()?->citizen;

        if (! $citizen) {
            return ApiResponse::forbidden(
                'Authenticated taxpayer is not linked to a citizen.'
            );
        }

        try {
            $this->notificationService->markAllAsRead(
                citizen: $citizen
            );

            return ApiResponse::success(
                message: 'All notifications marked as read.'
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::serverError(
                exception: $e
            );
        }
    }
}