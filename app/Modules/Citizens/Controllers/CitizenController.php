<?php

namespace App\Modules\Citizens\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Citizen;
use App\Modules\Citizens\Requests\StoreCitizenRequest;
use App\Modules\Citizens\Requests\UpdateCitizenRequest;
use App\Modules\Citizens\Resources\CitizenResource;
use App\Modules\Citizens\Services\CitizenService;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use Illuminate\Http\Request;

class CitizenController extends Controller
{
    public function __construct(
        protected CitizenService $service,
        protected SystemLogService $systemLogService,
    ) {}

    /**
     * ============================================================
     * LIST CITIZENS
     * ============================================================
     */
    public function index(Request $request)
    {
        $this->authorize(
            'viewAny',
            Citizen::class
        );

        $citizens = $this->service->paginate(
            $request->all()
        );

        return ApiResponse::success(
            CitizenResource::collection($citizens),
            'Citizens retrieved successfully'
        );
    }

    /**
     * ============================================================
     * STORE CITIZEN
     * ============================================================
     */
    public function store(
        StoreCitizenRequest $request
    ) {
        $this->authorize(
            'create',
            Citizen::class
        );

        $validated = $request->validated();

        $citizen = $this->service->store(
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->created(
            resource: $citizen,
            module: 'Citizens',
            description: 'Citizen created successfully',
            newValues: $citizen->getAttributes(),
        );

        return ApiResponse::created(
            new CitizenResource(
                $citizen->load([
                    'administrativeUnit',
                    'createdBy',
                ])
            ),
            'Citizen created successfully'
        );
    }

    /**
     * ============================================================
     * SHOW CITIZEN
     * ============================================================
     */
    public function show(
        Citizen $citizen
    ) {
        $this->authorize(
            'view',
            $citizen
        );

        $citizen->load([
            'administrativeUnit',
            'createdBy',
            'updatedBy',
        ]);

        /*
        |--------------------------------------------------------------------------
        | AUDIT VIEW
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->viewed(
            resource: $citizen,
            module: 'Citizens',
            description: 'Citizen profile viewed',
        );

        return ApiResponse::success(
            new CitizenResource($citizen),
            'Citizen retrieved successfully'
        );
    }

    /**
     * ============================================================
     * UPDATE CITIZEN
     * ============================================================
     */
    public function update(
        UpdateCitizenRequest $request,
        Citizen $citizen
    ) {
        $this->authorize(
            'update',
            $citizen
        );

        /*
        |--------------------------------------------------------------------------
        | Capture original values BEFORE update
        |--------------------------------------------------------------------------
        */
        $oldValues = $citizen->getAttributes();

        $validated = $request->validated();

        $updated = $this->service->update(
            $citizen,
            $validated
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->updated(
            resource: $updated,
            module: 'Citizens',
            oldValues: $oldValues,
            newValues: $updated->getAttributes(),
            description: 'Citizen updated successfully',
        );

        return ApiResponse::success(
            new CitizenResource(
                $updated->load([
                    'administrativeUnit',
                    'updatedBy',
                ])
            ),
            'Citizen updated successfully'
        );
    }

    /**
     * ============================================================
     * DELETE CITIZEN
     * ============================================================
     */
    public function destroy(
        Citizen $citizen
    ) {
        $this->authorize(
            'delete',
            $citizen
        );

        /*
        |--------------------------------------------------------------------------
        | Capture values BEFORE deletion
        |--------------------------------------------------------------------------
        */
        $oldValues = $citizen->getAttributes();

        $this->service->delete(
            $citizen
        );

        /*
        |--------------------------------------------------------------------------
        | AUDIT LOG
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->deleted(
            resource: $citizen,
            module: 'Citizens',
            oldValues: $oldValues,
            description: 'Citizen deleted successfully',
        );

        return ApiResponse::success(
            null,
            'Citizen deleted successfully'
        );
    }

    /**
     * ============================================================
     * UPDATE CITIZEN STATUS
     * ============================================================
     *
     * Activates or deactivates a citizen.
     *
     * Policy:
     *
     *     updateStatus
     *
     * The policy should check:
     *
     *     1. citizens.update permission
     *     2. Citizen organizational scope
     */
    public function updateStatus(
        Citizen $citizen
    ) {
        $this->authorize(
            'updateStatus',
            $citizen
        );

        /*
        |--------------------------------------------------------------------------
        | Capture old status
        |--------------------------------------------------------------------------
        */
        $oldStatus = (bool) $citizen->is_active;

        /*
        |--------------------------------------------------------------------------
        | Toggle status
        |--------------------------------------------------------------------------
        */
        $updated = $this->service->toggleStatus(
            $citizen
        );

        /*
        |--------------------------------------------------------------------------
        | Capture new status
        |--------------------------------------------------------------------------
        */
        $newStatus = (bool) $updated->is_active;

        /*
        |--------------------------------------------------------------------------
        | AUDIT STATUS CHANGE
        |--------------------------------------------------------------------------
        */
        $this->systemLogService->statusChanged(
            resource: $updated,
            module: 'Citizens',
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            description: $newStatus
                ? 'Citizen activated successfully'
                : 'Citizen deactivated successfully',
        );

        return ApiResponse::success(
            new CitizenResource(
                $updated->load([
                    'administrativeUnit',
                    'updatedBy',
                ])
            ),
            'Citizen status updated successfully'
        );
    }
}

