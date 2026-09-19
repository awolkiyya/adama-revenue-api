<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'payment_schedule_rules.view',
                'label' => 'View Payment Schedule Rules',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows viewing payment schedule configuration for revenue codes.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_schedule_rules.create',
                'label' => 'Create Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows creating payment schedule configuration for revenue codes.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_schedule_rules.update',
                'label' => 'Update Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows updating payment schedule configuration for revenue codes, including schedule availability and first-installment percentage.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_schedule_rules.activate',
                'label' => 'Activate Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows enabling payment schedule processing for a revenue code.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_schedule_rules.deactivate',
                'label' => 'Deactivate Payment Schedule Rule',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows disabling payment schedule processing for a revenue code without deleting its configuration.',
                'is_system' => false,
            ],
            [
                'name' => 'payment_schedule_rules.view_history',
                'label' => 'View Payment Schedule Rule History',
                'module' => 'payment_schedule_rules',
                'description' => 'Allows viewing the historical configuration and changes of payment schedule rules.',
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
            'payment_schedule_rules.view',
            'payment_schedule_rules.create',
            'payment_schedule_rules.update',
            'payment_schedule_rules.activate',
            'payment_schedule_rules.deactivate',
            'payment_schedule_rules.view_history',
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