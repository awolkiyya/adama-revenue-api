<?php

namespace App\Modules\System\AccessManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Modules\System\AccessManagement\Services\PermissionService;
use App\Modules\System\AccessManagement\Resources\PermissionGroupResource;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(
        private PermissionService $service
    ) {}

    /**
     * ============================================================
     * LIST PERMISSIONS GROUPED BY MODULE
     * ============================================================
     *
     * Permission:
     *
     *     permissions.view
     *
     * Returns the application-defined permission catalog grouped
     * by module.
     */
    public function grouped(): JsonResponse
    {
        $this->authorize(
            'viewAny',
            \Spatie\Permission\Models\Permission::class
        );

        return ApiResponse::success(
            PermissionGroupResource::collection(
                $this->service->grouped()
            ),
            'Grouped permissions retrieved successfully.'
        );
    }
}