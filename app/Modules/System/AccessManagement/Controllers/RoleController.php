<?php

namespace App\Modules\System\AccessManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\System\AccessManagement\Requests\StoreRoleRequest;
use App\Modules\System\AccessManagement\Requests\UpdateRoleRequest;
use App\Modules\System\AccessManagement\Resources\RoleResource;
use App\Modules\System\AccessManagement\Services\RoleService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $service
    ) {}

    /**
     * ============================================================
     * LIST ROLES
     * ============================================================
     *
     * Permission:
     *
     *     roles.view
     */
    public function index(Request $request)
    {
        $this->authorize(
            'viewAny',
            Role::class
        );

        $roles = $this->service->paginate(
            $request->all()
        );

        return ApiResponse::success(
            RoleResource::collection($roles),
            'Roles retrieved successfully',
        );
    }

    /**
     * ============================================================
     * SHOW ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.view
     */
    public function show(string $id)
    {
        $role = $this->service->findById($id);

        $this->authorize(
            'view',
            $role
        );

        return ApiResponse::success(
            new RoleResource($role),
            'Role retrieved successfully',
        );
    }

    /**
     * ============================================================
     * CREATE ROLE
     * ============================================================
     *
     * Permissions:
     *
     *     roles.create
     *     roles.assign_permissions
     *
     * The role itself is created using roles.create.
     *
     * If permissions are supplied, the caller must additionally
     * have roles.assign_permissions.
     *
     * Permission definitions are NOT created here.
     */
    public function store(StoreRoleRequest $request)
    {
        $this->authorize(
            'create',
            Role::class
        );

        /*
         * If the request contains permissions, the caller must
         * also be authorized to assign permissions.
         *
         * The role does not exist yet, so the policy receives
         * a temporary/new Role instance.
         */
        if ($request->filled('permissions')) {
            $this->authorize(
                'assignPermissions',
                new Role([
                    'guard_name' => 'api',
                ])
            );
        }

        $role = DB::transaction(function () use ($request) {
            $role = Role::create([
                ...$request->safe()->only([
                    'name',
                    'description',
                ]),
                'guard_name' => 'api',
            ]);

            if ($request->filled('permissions')) {
                $permissionIds = Permission::query()
                    ->whereIn(
                        'name',
                        $request->validated('permissions')
                    )
                    ->where(
                        'guard_name',
                        'api'
                    )
                    ->pluck('id');

                $role->permissions()->sync(
                    $permissionIds
                );
            }

            return $role;
        });

        return ApiResponse::success(
            new RoleResource(
                $role->loadCount([
                    'users',
                    'permissions',
                ])
            ),
            'Role created successfully',
        );
    }

    /**
     * ============================================================
     * UPDATE ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.update
     *
     * This operation updates role metadata only.
     */
    public function update(
        UpdateRoleRequest $request,
        Role $role
    ) {
        $this->authorize(
            'update',
            $role
        );

        DB::transaction(function () use (
            $request,
            $role
        ) {
            $role->update(
                $request->safe()->only([
                    'name',
                    'description',
                ])
            );
        });

        return ApiResponse::success(
            new RoleResource(
                $role->loadCount([
                    'users',
                    'permissions',
                ])
            ),
            'Role updated successfully',
        );
    }

    /**
     * ============================================================
     * ASSIGN PERMISSIONS TO ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.assign_permissions
     */
    public function assignPermissions(
        Request $request,
        Role $role
    ) {
        $this->authorize(
            'assignPermissions',
            $role
        );

        $validated = $request->validate([
            'permissions' => [
                'required',
                'array',
                'min:1',
            ],

            'permissions.*' => [
                'required',
                'string',
                Rule::exists('permissions', 'name')
                    ->where('guard_name', $role->guard_name),
            ],
        ]);

        DB::transaction(function () use (
            $validated,
            $role
        ) {
            $permissions = Permission::query()
                ->whereIn(
                    'name',
                    $validated['permissions']
                )
                ->where(
                    'guard_name',
                    $role->guard_name
                )
                ->get();

            /*
             * Adds permissions without removing existing ones.
             */
            $role->givePermissionTo(
                $permissions
            );
        });

        return ApiResponse::success(
            new RoleResource(
                $role
                    ->loadCount([
                        'users',
                        'permissions',
                    ])
                    ->load('permissions')
            ),
            'Permissions assigned successfully',
        );
    }

    /**
     * ============================================================
     * REVOKE PERMISSIONS FROM ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.revoke_permissions
     */
    public function revokePermissions(
        Request $request,
        Role $role
    ) {
        $this->authorize(
            'revokePermissions',
            $role
        );

        $validated = $request->validate([
            'permissions' => [
                'required',
                'array',
                'min:1',
            ],

            'permissions.*' => [
                'required',
                'string',
                Rule::exists('permissions', 'name')
                    ->where('guard_name', $role->guard_name),
            ],
        ]);

        DB::transaction(function () use (
            $validated,
            $role
        ) {
            $permissions = Permission::query()
                ->whereIn(
                    'name',
                    $validated['permissions']
                )
                ->where(
                    'guard_name',
                    $role->guard_name
                )
                ->get();

            $role->revokePermissionTo(
                $permissions
            );
        });

        return ApiResponse::success(
            new RoleResource(
                $role
                    ->loadCount([
                        'users',
                        'permissions',
                    ])
                    ->load('permissions')
            ),
            'Permissions revoked successfully',
        );
    }

    /**
     * ============================================================
     * VIEW ROLE HISTORY
     * ============================================================
     *
     * Permission:
     *
     *     roles.view_history
     */
    public function history(Role $role)
    {
        $this->authorize(
            'viewHistory',
            $role
        );

        $history = $this->service->history(
            $role
        );

        return ApiResponse::success(
            $history,
            'Role history retrieved successfully',
        );
    }

    /**
     * ============================================================
     * DELETE ROLE
     * ============================================================
     *
     * Permission:
     *
     *     roles.delete
     *
     * RolePolicy prevents deletion of system roles.
     */
    public function destroy(Role $role)
    {
        $this->authorize(
            'delete',
            $role
        );

        $role->delete();

        return ApiResponse::success(
            null,
            'Role deleted successfully',
        );
    }
}