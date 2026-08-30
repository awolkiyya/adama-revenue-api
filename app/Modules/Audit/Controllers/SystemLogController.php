<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Modules\Audit\Requests\SystemLogIndexRequest;
use App\Modules\Audit\Resources\SystemLogResource;
use App\Modules\Audit\Services\SystemLogService;
use App\Services\ApiResponse;

class SystemLogController extends Controller
{
    public function __construct(
        protected SystemLogService $service
    ) {}

    /**
     * ============================================================
     * LIST AUDIT LOGS
     * ============================================================
     *
     * Permission:
     *
     *     audit.view
     *
     * Authorization is handled by SystemLogPolicy.
     *
     * Validation and normalization are handled by
     * SystemLogIndexRequest.
     *
     * Querying, filtering, sorting and pagination are handled
     * by SystemLogService.
     */
    public function index(SystemLogIndexRequest $request)
    {
        $this->authorize(
            'viewAny',
            SystemLog::class
        );

        $logs = $this->service->paginate(
            $request->validated()
        );

        return ApiResponse::success(
            SystemLogResource::collection($logs),
            'Audit logs retrieved successfully'
        );
    }

    /**
     * ============================================================
     * SHOW AUDIT LOG
     * ============================================================
     *
     * Permission:
     *
     *     audit.view
     *
     * Authorization is handled by SystemLogPolicy.
     */
    public function show(SystemLog $systemLog)
    {
        $this->authorize(
            'view',
            $systemLog
        );

        $systemLog->load('user');

        return ApiResponse::success(
            new SystemLogResource($systemLog),
            'Audit log retrieved successfully'
        );
    }
}