<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';


        /*
        |--------------------------------------------------------------------------
        | SYSTEM ADMIN
        |--------------------------------------------------------------------------
        |
        | SYSTEM_ADMIN is the protected system role.
        |
        | It receives every permission available in the system.
        |
        | This is intentionally managed by the seeder so that the system
        | administrator cannot accidentally lose the permissions required
        | to manage users, roles, permissions, and the entire application.
        |
        */

        $systemAdmin = Role::findByName(
            'SYSTEM_ADMIN',
            $guard
        );

        $systemAdmin->syncPermissions(
            Permission::where(
                'guard_name',
                $guard
            )->get()
        );

    }
}