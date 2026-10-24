<?php

declare(strict_types=1);

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
        $this->authorize('viewAny', Citizen::class);

        $citizens = $this->service->paginate(
            $request->all()
        );

        $summary = [
            'total_citizens' => Citizen::query()->count(),

            'manual_registration' => Citizen::query()
                ->where('source', 'MANUAL')
                ->count(),

            'imported_records' => Citizen::query()
                ->where('source', 'IMPORT')
                ->count(),

            'external_system' => Citizen::query()
                ->where('source', 'EXTERNAL')
                ->count(),
        ];

        return ApiResponse::success(
            CitizenResource::collection($citizens),
            'Citizens retrieved successfully',
            summary: $summary,
        );
    }

    /**
     * ============================================================
     * STORE CITIZEN
     * ============================================================
     */
    public function store(StoreCitizenRequest $request)
    {
        $this->authorize('create', Citizen::class);

        $validated = $request->validated();

        $citizen = $this->service->store($validated);

        $this->systemLogService->created(
            resource: $citizen,
            module: 'Citizens',
            description: 'Citizen created successfully',
            newValues: $this->auditAttributes($citizen),
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
    public function show(Citizen $citizen)
    {
        $this->authorize('view', $citizen);

        $citizen->load([
            'administrativeUnit',
            'createdBy',
            'updatedBy',
        ]);

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
        $this->authorize('update', $citizen);

        // Capture the persisted values before the update.
        $oldValues = $this->auditAttributes($citizen);

        $validated = $request->validated();

        $updated = $this->service->update(
            $citizen,
            $validated
        );

        // Capture the persisted values after the update.
        $newValues = $this->auditAttributes($updated);

        // Include only fields whose values actually changed.
        $changedOldValues = [];
        $changedNewValues = [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changedOldValues[$key] = $oldValue;
                $changedNewValues[$key] = $newValue;
            }
        }

        if ($changedNewValues !== []) {
            $this->systemLogService->updated(
                resource: $updated,
                module: 'Citizens',
                oldValues: $changedOldValues,
                newValues: $changedNewValues,
                description: 'Citizen updated successfully',
            );
        }

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
     * UPDATE CITIZEN STATUS
     * ============================================================
     */
    public function updateStatus(Citizen $citizen)
    {
        // Do not leave status changes unprotected.
        $this->authorize('updateStatus', $citizen);

        $oldStatus = (bool) $citizen->is_active;

        $updated = $this->service->toggleStatus($citizen);

        $newStatus = (bool) $updated->is_active;

        if ($oldStatus !== $newStatus) {
            $this->systemLogService->statusChanged(
                resource: $updated,
                module: 'Citizens',
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                description: $newStatus
                    ? 'Citizen activated successfully'
                    : 'Citizen deactivated successfully',
            );
        }

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

    /**
     * ============================================================
     * AUDIT ATTRIBUTE WHITELIST
     * ============================================================
     *
     * Record only fields suitable for the citizen audit trail.
     * Do not log every model attribute by default.
     */
    private function auditAttributes(Citizen $citizen): array
    {
        $attributes = $citizen->only([
            'id',
            'citizen_uid',
            'national_id',
            'phone',
            'gender',
            'source',
            'is_active',
            'administrative_unit_id',
        ]);

        // Normalize values for reliable comparisons and JSON storage.
        return array_map(
            static fn ($value) => $value,
            $attributes
        );
    }
}
