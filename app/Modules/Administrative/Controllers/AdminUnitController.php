<?php

declare(strict_types=1);

namespace App\Modules\Administrative\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Modules\Administrative\Resources\AdminUnitResource;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminUnitController extends Controller
{
    private const MODULE = 'Administrative';

    /**
     * List administrative units with search, filtering,
     * pagination, and system logging.
     */
    public function index(
        Request $request,
        SystemLogService $systemLogService
    ) {
        $this->authorize('viewAny', AdministrativeUnit::class);

        $validated = $request->validate([
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'level' => [
                'sometimes',
                'nullable',
                'in:ALL,CITY,SUBCITY,WEREDA',
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $level = $validated['level'] ?? 'ALL';
        $perPage = (int) ($validated['per_page'] ?? 10);

        $units = AdministrativeUnit::query()
            ->with('parent:id,name,code,level')
            ->when(
                $search !== '',
                function ($query) use ($search): void {
                    $searchTerm = mb_strtolower($search);

                    $query->where(function ($nestedQuery) use ($searchTerm): void {
                        $nestedQuery
                            ->whereRaw(
                                'LOWER(name) LIKE ?',
                                ["%{$searchTerm}%"]
                            )
                            ->orWhereRaw(
                                'LOWER(code) LIKE ?',
                                ["%{$searchTerm}%"]
                            );
                    });
                }
            )
            ->when(
                $level !== 'ALL' && $level !== null,
                fn ($query) => $query->where('level', $level)
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $systemLogService->log(
            action: 'VIEW_LIST',
            module: self::MODULE,
            description: 'Viewed administrative units list.',
            metadata: [
                'search' => $search !== '' ? $search : null,
                'level' => $level,
                'page' => $units->currentPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
            ],
        );

        return ApiResponse::success(
            AdminUnitResource::collection($units),
            'Administrative units retrieved successfully'
        );
    }

    /**
     * Show one administrative unit with its parent and children.
     */
    public function show(
        string $id,
        SystemLogService $systemLogService
    ) {
        $unit = AdministrativeUnit::query()
            ->with([
                'parent:id,name,code,level',
                'children:id,name,code,level,is_active,parent_id',
            ])
            ->findOrFail($id);

        $this->authorize('view', $unit);

        $systemLogService->viewed(
            resource: $unit,
            module: self::MODULE,
            description: sprintf(
                'Viewed administrative unit: %s (%s)',
                $unit->name,
                $unit->code
            ),
        );

        return ApiResponse::success(
            new AdminUnitResource($unit),
            'Administrative unit retrieved successfully'
        );
    }

    /**
     * Retrieve child units and log access to the parent hierarchy.
     */
    public function getChildren(
        string $id,
        SystemLogService $systemLogService
    ) {
        $parent = AdministrativeUnit::findOrFail($id);

        $this->authorize('view', $parent);

        $children = AdministrativeUnit::query()
            ->with('parent:id,name,code,level')
            ->where('parent_id', $parent->id)
            ->latest()
            ->get();

        $systemLogService->viewed(
            resource: $parent,
            module: self::MODULE,
            description: sprintf(
                'Viewed child units of administrative unit: %s (%s)',
                $parent->name,
                $parent->code
            ),
            metadata: [
                'event' => 'VIEW_CHILDREN',
                'children_count' => $children->count(),
            ],
        );

        return ApiResponse::success(
            AdminUnitResource::collection($children),
            'Administrative unit children retrieved successfully'
        );
    }

    /**
     * Create an administrative unit and log its creation.
     */
    public function store(
        Request $request,
        SystemLogService $systemLogService
    ) {
        $this->authorize('create', AdministrativeUnit::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                'unique:administrative_units,code',
            ],
            'level' => [
                'required',
                'in:CITY,SUBCITY,WEREDA',
            ],
            'parent_id' => [
                'nullable',
                'uuid',
                'exists:administrative_units,id',
            ],
        ]);

        $unit = DB::transaction(function () use (
            $validated,
            $systemLogService
        ): AdministrativeUnit {
            $unit = AdministrativeUnit::create($validated);

            $systemLogService->created(
                resource: $unit,
                module: self::MODULE,
                description: sprintf(
                    'Created administrative unit: %s (%s)',
                    $unit->name,
                    $unit->code
                ),
                newValues: $unit->getAttributes(),
            );

            return $unit;
        });

        $unit->load('parent:id,name,code,level');

        return ApiResponse::created(
            new AdminUnitResource($unit),
            'Administrative unit created successfully'
        );
    }

    /**
     * Update an administrative unit and log old/new values.
     */
    public function update(
        Request $request,
        string $id,
        SystemLogService $systemLogService
    ) {
        $unit = AdministrativeUnit::findOrFail($id);

        $this->authorize('update', $unit);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('administrative_units', 'code')
                    ->ignore($unit->id),
            ],
            'level' => [
                'sometimes',
                'required',
                'in:CITY,SUBCITY,WEREDA',
            ],
            'parent_id' => [
                'sometimes',
                'nullable',
                'uuid',
                'exists:administrative_units,id',
            ],
        ]);

        DB::transaction(function () use (
            $unit,
            $validated,
            $systemLogService
        ): void {
            $oldValues = $unit->getAttributes();

            $unit->update($validated);
            $unit->refresh();

            $systemLogService->updated(
                resource: $unit,
                module: self::MODULE,
                oldValues: $oldValues,
                newValues: $unit->getAttributes(),
                description: sprintf(
                    'Updated administrative unit: %s (%s)',
                    $unit->name,
                    $unit->code
                ),
            );
        });

        $unit->load('parent:id,name,code,level');

        return ApiResponse::success(
            new AdminUnitResource($unit),
            'Administrative unit updated successfully'
        );
    }

    /**
     * Delete an administrative unit and log its deletion.
     *
     * Units with child units cannot be deleted.
     */
    public function destroy(
        string $id,
        SystemLogService $systemLogService
    ) {
        $unit = AdministrativeUnit::findOrFail($id);

        $this->authorize('delete', $unit);

        if ($unit->children()->exists()) {
            return ApiResponse::error(
                'Cannot delete an administrative unit that has child units.',
                422
            );
        }

        DB::transaction(function () use (
            $unit,
            $systemLogService
        ): void {
            $oldValues = $unit->getAttributes();

            $systemLogService->deleted(
                resource: $unit,
                module: self::MODULE,
                description: sprintf(
                    'Deleted administrative unit: %s (%s)',
                    $unit->name,
                    $unit->code
                ),
                oldValues: $oldValues,
            );

            $unit->delete();
        });

        return ApiResponse::success(
            null,
            'Administrative unit deleted successfully'
        );
    }

    /**
     * Activate an administrative unit.
     */
    public function activate(
        string $id,
        SystemLogService $systemLogService
    ) {
        return $this->setActiveStatus(
            $id,
            true,
            $systemLogService
        );
    }

    /**
     * Deactivate an administrative unit.
     */
    public function deactivate(
        string $id,
        SystemLogService $systemLogService
    ) {
        return $this->setActiveStatus(
            $id,
            false,
            $systemLogService
        );
    }

    /**
     * Change activation status and log the transition.
     */
    private function setActiveStatus(
        string $id,
        bool $active,
        SystemLogService $systemLogService
    ) {
        $unit = AdministrativeUnit::findOrFail($id);

        $this->authorize('update', $unit);

        if ((bool) $unit->is_active === $active) {
            $unit->load('parent:id,name,code,level');

            return ApiResponse::success(
                new AdminUnitResource($unit),
                $active
                    ? 'Administrative unit is already active.'
                    : 'Administrative unit is already inactive.'
            );
        }

        DB::transaction(function () use (
            $unit,
            $active,
            $systemLogService
        ): void {
            $oldActive = (bool) $unit->is_active;

            $unit->update([
                'is_active' => $active,
            ]);

            $unit->refresh();

            $systemLogService->statusChanged(
                resource: $unit,
                module: self::MODULE,
                oldStatus: $oldActive,
                newStatus: (bool) $unit->is_active,
                description: sprintf(
                    '%s administrative unit: %s (%s)',
                    $active ? 'Activated' : 'Deactivated',
                    $unit->name,
                    $unit->code
                ),
                metadata: [
                    'field' => 'is_active',
                ],
            );
        });

        $unit->load('parent:id,name,code,level');

        return ApiResponse::success(
            new AdminUnitResource($unit),
            $active
                ? 'Administrative unit activated successfully.'
                : 'Administrative unit deactivated successfully.'
        );
    }
}