<?php

declare(strict_types=1);

namespace App\Modules\System\AccessManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Modules\System\AccessManagement\Resources\PermissionGroupResource;
use App\Modules\System\AccessManagement\Services\PermissionService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService
    ) {
    }

    /**
     * Display API permissions grouped by resource.
     */
    public function grouped(): JsonResponse
    {
        $requestId = request()->attributes->get('request_id')
            ?? (string) str()->uuid();

        $userId = auth()->id();

        Log::info(
            'PermissionManagementController::grouped() started.',
            [
                'request_id' => $requestId,
                'user_id' => $userId,
                'guard' => 'api',
                'route' => request()->route()?->uri(),
                'method' => request()->method(),
            ]
        );

        try {
            Log::info(
                'Authorizing permission access.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'permission' => 'permissions.view',
                ]
            );

            Gate::authorize('viewAny', Permission::class);

            Log::info(
                'Permission authorization successful.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'permission' => 'permissions.view',
                ]
            );

            Log::info(
                'Calling PermissionService::list().',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                ]
            );

            $groups = $this->permissionService->list();

            Log::info(
                'PermissionService::list() returned successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'group_count' => $groups->count(),
                    'permission_count' => $groups->sum(
                        static fn (array $group): int =>
                            $group['permissions']->count()
                    ),
                ]
            );

            Log::info(
                'Creating PermissionGroupResource collection.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'group_count' => $groups->count(),
                ]
            );

            $resource = PermissionGroupResource::collection($groups);

            Log::info(
                'PermissionGroupResource collection created successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                ]
            );

            $response = ApiResponse::success(
                data: $resource,
                message: 'Permissions retrieved successfully.'
            );

            Log::info(
                'PermissionManagementController::grouped() completed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'status' => $response->getStatusCode(),
                ]
            );

            return $response;
        } catch (Throwable $exception) {
            Log::error(
                'PermissionManagementController::grouped() failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }
    }
}
