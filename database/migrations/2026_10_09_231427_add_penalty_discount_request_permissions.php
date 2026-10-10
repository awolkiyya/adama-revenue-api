<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private string $guardName = 'api';

    private array $permissions = [
        'penalty_discount_requests.view',
        'penalty_discount_requests.read',
        'penalty_discount_requests.create',
        'penalty_discount_requests.submit',
        'penalty_discount_requests.decide',
        'penalty_discount_requests.cancel',
        'penalty_discount_requests.view_history',
        'penalty_discount_requests.apply',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach ($this->permissions as $permissionName) {
                Permission::findOrCreate(
                    $permissionName,
                    $this->guardName
                );
            }

            $role = Role::query()
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $this->guardName)
                ->first();

            if (! $role) {
                throw new RuntimeException(
                    'SYSTEM_ADMIN role with guard api was not found. Create the role before running this migration.'
                );
            }

            $permissionIds = Permission::query()
                ->where('guard_name', $this->guardName)
                ->whereIn('name', $this->permissions)
                ->pluck('id');

            $role->permissions()->syncWithoutDetaching($permissionIds);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $role = Role::query()
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $this->guardName)
                ->first();

            if ($role) {
                $permissionIds = Permission::query()
                    ->where('guard_name', $this->guardName)
                    ->whereIn('name', $this->permissions)
                    ->pluck('id');

                $role->permissions()->detach($permissionIds);
            }

            Permission::query()
                ->where('guard_name', $this->guardName)
                ->whereIn('name', $this->permissions)
                ->whereDoesntHave('roles')
                ->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
