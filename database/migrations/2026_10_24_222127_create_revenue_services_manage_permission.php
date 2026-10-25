<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permission guard used by the application.
     */
    private string $guard = 'api';

    /**
     * Revenue Services permissions.
     */
    private array $permissions = [
        [
            'name' => 'revenue_services.manage',
            'label' => 'Manage Revenue Service Access',
            'description' => 'Allows managing access permissions for revenue services.',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();

        DB::transaction(function () use ($now): void {
            /*
            |--------------------------------------------------------------------------
            | Validate Required Tables
            |--------------------------------------------------------------------------
            */

            foreach ([
                'permissions',
                'roles',
                'role_has_permissions',
            ] as $table) {
                if (!DB::getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeException(
                        "Required table [{$table}] does not exist."
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Find SYSTEM_ADMIN Role
            |--------------------------------------------------------------------------
            */

            $systemAdmin = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $this->guard)
                ->first(['id']);

            if (!$systemAdmin) {
                throw new RuntimeException(
                    'SYSTEM_ADMIN role does not exist for the api guard. ' .
                    'Create the role before running this migration.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Create Permission If Missing
            |--------------------------------------------------------------------------
            |
            | Existing permission records and metadata are preserved.
            |
            */

            foreach ($this->permissions as $permissionData) {
                $permission = DB::table('permissions')
                    ->where('name', $permissionData['name'])
                    ->where('guard_name', $this->guard)
                    ->first(['id']);

                if (!$permission) {
                    $permissionId = DB::table('permissions')->insertGetId([
                        'name' => $permissionData['name'],
                        'guard_name' => $this->guard,
                        'label' => $permissionData['label'],
                        'module' => 'revenue_services',
                        'description' => $permissionData['description'],
                        'is_system' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $permission = (object) [
                        'id' => $permissionId,
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Assign Permission to SYSTEM_ADMIN
                |--------------------------------------------------------------------------
                */

                $assignmentExists = DB::table('role_has_permissions')
                    ->where('role_id', $systemAdmin->id)
                    ->where('permission_id', $permission->id)
                    ->exists();

                if (!$assignmentExists) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permission->id,
                        'role_id' => $systemAdmin->id,
                    ]);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     *
     * Removes the permission assignment from SYSTEM_ADMIN.
     * The permission record itself is retained.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $systemAdmin = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $this->guard)
                ->first(['id']);

            if (!$systemAdmin) {
                return;
            }

            $permissionNames = array_column(
                $this->permissions,
                'name'
            );

            $permissionIds = DB::table('permissions')
                ->where('guard_name', $this->guard)
                ->whereIn('name', $permissionNames)
                ->pluck('id');

            if ($permissionIds->isNotEmpty()) {
                DB::table('role_has_permissions')
                    ->where('role_id', $systemAdmin->id)
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
