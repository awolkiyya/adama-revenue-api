<?php

declare(strict_types=1);

namespace App\Modules\System\AccessManagement\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Services\SystemLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RoleService
{
    private const GUARD = 'api';

    private const MODULE = 'Access Management';

    public function __construct(
        private readonly SystemLogService $systemLogService,
    ) {
    }

    /**
     * Paginate API-guard roles.
     *
     * Supported filters:
     * - search
     * - sort_by
     * - sort_direction
     * - per_page
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $allowedSorts = [
            'name',
            'created_at',
            'updated_at',
            'users_count',
            'permissions_count',
        ];

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $sortDirection = strtolower(
            (string) ($filters['sort_direction'] ?? 'desc')
        );

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));

        $query = Role::query()
            ->where('guard_name', self::GUARD)
            ->with([
                'permissions' => static function ($query): void {
                    $query->where(
                        'guard_name',
                        self::GUARD
                    )->orderBy('name');
                },
            ])
            ->withCount(['users', 'permissions']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where('name', 'ilike', '%' . $search . '%');
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find a role and load its API permissions.
     */
    public function find(string|int $roleId): Role
    {
        return Role::query()
            ->where('guard_name', self::GUARD)
            ->with([
                'permissions' => static function ($query): void {
                    $query->where(
                        'guard_name',
                        self::GUARD
                    )->orderBy('name');
                },
            ])
            ->withCount(['users', 'permissions'])
            ->findOrFail($roleId);
    }

    /**
     * Create a role and synchronize its permissions.
     */
    public function create(array $data): Role
    {
        $name = $this->validateRoleName(
            (string) ($data['name'] ?? '')
        );

        $permissions = $this->normalizePermissions(
            $data['permissions'] ?? []
        );

        $this->validatePermissions($permissions);

        try {
            $role = DB::transaction(function () use (
                $name,
                $data,
                $permissions
            ): Role {
                $role = Role::query()->create([
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'description' => $data['description'] ?? null,
                ]);

                $role->syncPermissions($permissions);

                // Reload the persisted permissions before logging.
                $role->refresh();

                $actualPermissions = $this->getPermissionNames($role);

                $this->assertPermissionsMatch(
                    $permissions,
                    $actualPermissions
                );

                $this->systemLogService->created(
                    resource: $role,
                    module: self::MODULE,
                    description: 'Role created successfully.',
                    newValues: [
                        'name' => $role->name,
                        'guard_name' => $role->guard_name,
                        'description' => $role->description,
                        'permissions' => $actualPermissions,
                    ],
                    metadata: [
                        'event' => 'role.created',
                        'role_id' => (string) $role->getKey(),
                        'permissions_count' => count($actualPermissions),
                    ],
                );

                return $role;
            });

            return $this->find($role->getKey());
        } catch (Throwable $exception) {
            Log::error('Failed to create role.', [
                'name' => $name,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Update a role and synchronize its permissions.
     *
     * If permissions are omitted, existing permissions are preserved.
     * Pass "permissions": [] to explicitly remove all permissions.
     */
    public function update(Role $role, array $data): Role
    {
        $this->ensureApiGuard($role);
        $this->ensureRoleCanBeModified($role);

        $roleId = $role->getKey();

        try {
            DB::transaction(function () use (
                $roleId,
                $data
            ): void {
                /** @var Role $lockedRole */
                $lockedRole = Role::query()
                    ->where('guard_name', self::GUARD)
                    ->lockForUpdate()
                    ->findOrFail($roleId);

                $this->ensureApiGuard($lockedRole);
                $this->ensureRoleCanBeModified($lockedRole);

                $oldName = $lockedRole->name;
                $oldDescription = $lockedRole->description;
                $oldPermissions = $this->getPermissionNames($lockedRole);

                $newName = array_key_exists('name', $data)
                    ? $this->validateRoleName((string) $data['name'])
                    : $oldName;

                $newPermissions = array_key_exists('permissions', $data)
                    ? $this->normalizePermissions($data['permissions'])
                    : $oldPermissions;

                $this->validatePermissions($newPermissions);

                $lockedRole->name = $newName;

                if (array_key_exists('description', $data)) {
                    $lockedRole->description = $data['description'];
                }

                $lockedRole->save();

                $lockedRole->syncPermissions($newPermissions);

                // Query persisted permissions instead of trusting the input.
                $lockedRole->unsetRelation('permissions');

                $actualPermissions = $this->getPermissionNames($lockedRole);

                $this->assertPermissionsMatch(
                    $newPermissions,
                    $actualPermissions
                );

                $addedPermissions = array_values(
                    array_diff($actualPermissions, $oldPermissions)
                );

                $removedPermissions = array_values(
                    array_diff($oldPermissions, $actualPermissions)
                );

                sort($addedPermissions);
                sort($removedPermissions);

                $this->systemLogService->updated(
                    resource: $lockedRole,
                    module: self::MODULE,
                    oldValues: [
                        'name' => $oldName,
                        'description' => $oldDescription,
                        'permissions' => $oldPermissions,
                    ],
                    newValues: [
                        'name' => $lockedRole->name,
                        'description' => $lockedRole->description,
                        'permissions' => $actualPermissions,
                    ],
                    description: 'Role updated successfully.',
                    metadata: [
                        'event' => 'role.updated',
                        'role_id' => (string) $lockedRole->getKey(),
                        'permissions_count' => count($actualPermissions),
                        'added_permissions' => $addedPermissions,
                        'removed_permissions' => $removedPermissions,
                    ],
                );
            });

            return $this->find($roleId);
        } catch (Throwable $exception) {
            Log::error('Failed to update role.', [
                'role_id' => (string) $roleId,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Delete a role and record its previous state.
     */
    public function delete(Role $role): void
    {
        $this->ensureApiGuard($role);
        $this->ensureRoleCanBeDeleted($role);

        $roleId = $role->getKey();

        try {
            DB::transaction(function () use ($roleId): void {
                /** @var Role $lockedRole */
                $lockedRole = Role::query()
                    ->where('guard_name', self::GUARD)
                    ->lockForUpdate()
                    ->with('permissions')
                    ->findOrFail($roleId);

                $this->ensureApiGuard($lockedRole);
                $this->ensureRoleCanBeDeleted($lockedRole);

                if ($lockedRole->users()->exists()) {
                    throw new InvalidArgumentException(
                        'This role cannot be deleted because it is assigned to users.'
                    );
                }

                $oldValues = [
                    'name' => $lockedRole->name,
                    'guard_name' => $lockedRole->guard_name,
                    'description' => $lockedRole->description,
                    'permissions' => $this->getPermissionNames($lockedRole),
                ];

                // Remove role-permission assignments before deleting the role.
                $lockedRole->syncPermissions([]);

                $lockedRole->delete();

                $this->systemLogService->deleted(
                    resource: $lockedRole,
                    module: self::MODULE,
                    description: 'Role deleted successfully.',
                    oldValues: $oldValues,
                    metadata: [
                        'event' => 'role.deleted',
                        'role_id' => (string) $roleId,
                        'permissions_count' => count(
                            $oldValues['permissions']
                        ),
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::error('Failed to delete role.', [
                'role_id' => (string) $roleId,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Normalize permission names and remove duplicates.
     *
     * @return array<int, string>
     */
    private function normalizePermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            throw new InvalidArgumentException(
                'Permissions must be an array of permission names.'
            );
        }

        $normalized = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission) || trim($permission) === '') {
                throw new InvalidArgumentException(
                    'Each permission must be a non-empty string.'
                );
            }

            $normalized[] = trim($permission);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Validate the role name format.
     */
    private function validateRoleName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'Role name is required.'
            );
        }

        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                'Role name must start with an uppercase letter and contain only uppercase letters, numbers, and underscores.'
            );
        }

        return $name;
    }

    /**
     * Ensure every permission exists and uses the API guard.
     *
     * @param array<int, string> $permissions
     */
    private function validatePermissions(array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $existingPermissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', $permissions)
            ->pluck('name')
            ->all();

        $missingPermissions = array_values(
            array_diff($permissions, $existingPermissions)
        );

        if ($missingPermissions !== []) {
            throw new InvalidArgumentException(
                'The following permissions do not exist for the API guard: '
                . implode(', ', $missingPermissions)
            );
        }
    }

    /**
     * Verify the role belongs to the API guard.
     */
    private function ensureApiGuard(Role $role): void
    {
        if ($role->guard_name !== self::GUARD) {
            throw new InvalidArgumentException(
                'The specified role does not belong to the API guard.'
            );
        }
    }

    /**
     * Prevent changes to protected system roles.
     */
    private function ensureRoleCanBeModified(Role $role): void
    {
        if (
            $role->name === 'SYSTEM_ADMIN'
            || (bool) $role->getAttribute('is_system')
        ) {
            throw new InvalidArgumentException(
                'System roles cannot be modified.'
            );
        }
    }

    /**
     * Prevent deletion of protected system roles.
     */
    private function ensureRoleCanBeDeleted(Role $role): void
    {
        if (
            $role->name === 'SYSTEM_ADMIN'
            || (bool) $role->getAttribute('is_system')
        ) {
            throw new InvalidArgumentException(
                'System roles cannot be deleted.'
            );
        }
    }

    /**
     * Return the persisted permission names in a stable order.
     *
     * @return array<int, string>
     */
    private function getPermissionNames(Role $role): array
    {
        $permissions = $role->permissions()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return array_values($permissions);
    }

    /**
     * Ensure the persisted permissions exactly match the requested ones.
     *
     * @param array<int, string> $expected
     * @param array<int, string> $actual
     */
    private function assertPermissionsMatch(
        array $expected,
        array $actual
    ): void {
        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            throw new RuntimeException(
                'Role permissions could not be synchronized correctly.'
            );
        }
    }
}
