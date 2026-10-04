<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guard = 'api';

        $permissions = [
            [
                'name' => 'office.portal_access',
                'label' => 'Access Office Portal',
                'module' => 'office',
                'description' => 'Allows accessing the municipal Office portal.',
                'is_system' => true,
            ],
            [
                'name' => 'agent.portal_access',
                'label' => 'Access Agent Portal',
                'module' => 'agent',
                'description' => 'Allows accessing the Agent portal for authorized taxpayer assistance and payment services.',
                'is_system' => true,
            ],
        ];

        DB::transaction(function () use ($permissions, $guard) {

            /*
            |--------------------------------------------------------------------------
            | Create / Update Portal Permissions
            |--------------------------------------------------------------------------
            */

            foreach ($permissions as $permission) {
                Permission::updateOrCreate(
                    [
                        'name' => $permission['name'],
                        'guard_name' => $guard,
                    ],
                    [
                        'label' => $permission['label'],
                        'module' => $permission['module'],
                        'description' => $permission['description'],
                        'is_system' => $permission['is_system'],
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Assign Portal Permissions to SYSTEM_ADMIN
            |--------------------------------------------------------------------------
            |
            | SYSTEM_ADMIN can access both the Office and Agent portals.
            |
            */

            $systemAdmin = Role::where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $guard)
                ->first();

            if (! $systemAdmin) {
                throw new RuntimeException(
                    'SYSTEM_ADMIN role was not found for guard [' . $guard . '].'
                );
            }

            $systemAdmin->givePermissionTo([
                'office.portal_access',
                'agent.portal_access',
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Clear Spatie Permission Cache
        |--------------------------------------------------------------------------
        |
        | Ensures the newly created permissions and role assignments are
        | immediately available to authorization checks.
        |
        */

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Clear Application Permission Catalog Cache
        |--------------------------------------------------------------------------
        */

        cache()->forget('permission_catalog');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $guard = 'api';

        DB::transaction(function () use ($guard) {

            /*
            |--------------------------------------------------------------------------
            | Remove Portal Permissions from SYSTEM_ADMIN
            |--------------------------------------------------------------------------
            */

            $systemAdmin = Role::where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', $guard)
                ->first();

            if ($systemAdmin) {
                $permissions = Permission::where('guard_name', $guard)
                    ->whereIn('name', [
                        'office.portal_access',
                        'agent.portal_access',
                    ])
                    ->get();

                if ($permissions->isNotEmpty()) {
                    $systemAdmin->revokePermissionTo($permissions);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Delete Portal Permissions
            |--------------------------------------------------------------------------
            */

            Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', [
                    'office.portal_access',
                    'agent.portal_access',
                ])
                ->delete();
        });

        /*
        |--------------------------------------------------------------------------
        | Clear Spatie Permission Cache
        |--------------------------------------------------------------------------
        */

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Clear Application Permission Catalog Cache
        |--------------------------------------------------------------------------
        */

        cache()->forget('permission_catalog');
    }
};