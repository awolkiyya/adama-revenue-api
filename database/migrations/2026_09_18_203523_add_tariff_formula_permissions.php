<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'tariff_formula.view',
                'label' => 'View Tariff Formulas',
                'module' => 'tariff',
                'description' => 'Allows viewing formulas used in tariff rule calculations.',
                'is_system' => false,
            ],
            [
                'name' => 'tariff_formula.create',
                'label' => 'Create Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows creating formulas for tariff rule calculations.',
                'is_system' => false,
            ],
            [
                'name' => 'tariff_formula.update',
                'label' => 'Update Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows updating formulas used in tariff rule calculations.',
                'is_system' => false,
            ],
            [
                'name' => 'tariff_formula.delete',
                'label' => 'Delete Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows deleting formulas from tariff rule calculations.',
                'is_system' => false,
            ],
            [
                'name' => 'tariff_formula.validate',
                'label' => 'Validate Tariff Formula',
                'module' => 'tariff',
                'description' => 'Allows validating tariff formulas before they are used for revenue calculation.',
                'is_system' => false,
            ],
        ];

        $permissionIds = [];

        foreach ($permissions as $permission) {
            $existing = DB::table('permissions')
                ->where('name', $permission['name'])
                ->where('guard_name', 'api')
                ->first();

            if ($existing) {
                $permissionIds[] = $existing->id;
                continue;
            }

            DB::table('permissions')->insert([
                'name' => $permission['name'],
                'guard_name' => 'api',
                'label' => $permission['label'],
                'module' => $permission['module'],
                'description' => $permission['description'],
                'is_system' => $permission['is_system'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionIds[] = DB::table('permissions')
                ->where('name', $permission['name'])
                ->where('guard_name', 'api')
                ->value('id');
        }

        $systemAdminRole = DB::table('roles')
            ->where('name', 'SYSTEM_ADMIN')
            ->where('guard_name', 'api')
            ->first();

        if (! $systemAdminRole) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_has_permissions')
                ->where('role_id', $systemAdminRole->id)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $systemAdminRole->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionNames = [
            'tariff_formula.view',
            'tariff_formula.create',
            'tariff_formula.update',
            'tariff_formula.delete',
            'tariff_formula.validate',
        ];

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $permissionNames)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $permissionNames)
            ->delete();
    }
};