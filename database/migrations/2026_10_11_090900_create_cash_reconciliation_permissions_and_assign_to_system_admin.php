
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions managed by this migration.
     */
    private array $permissions = [
        [
            'name' => 'cash_reconciliations.view',
            'label' => 'View Cash Reconciliations',
            'module' => 'cash_reconciliations',
            'description' => 'Allows accessing the cash reconciliation management interface.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.view-all',
            'label' => 'View All Cash Reconciliations',
            'module' => 'cash_reconciliations',
            'description' => 'Allows viewing cash reconciliations belonging to all authorized revenue collectors.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.create',
            'label' => 'Create Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows creating cash reconciliation drafts.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.submit',
            'label' => 'Submit Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows submitting cash reconciliations for review.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.review',
            'label' => 'Review Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows reviewing, reconciling, flagging discrepancies, or returning cash closings.',
            'is_system' => false,
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $role = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', 'api')
                ->first();

            if (!$role) {
                throw new RuntimeException(
                    'The SYSTEM_ADMIN role with guard_name "api" was not found. ' .
                    'Create the role before running this migration.'
                );
            }

            foreach ($this->permissions as $permissionData) {
                $now = now();

                DB::table('permissions')->updateOrInsert(
                    [
                        'name' => $permissionData['name'],
                        'guard_name' => 'api',
                    ],
                    [
                        'label' => $permissionData['label'],
                        'module' => $permissionData['module'],
                        'description' => $permissionData['description'],
                        'is_system' => $permissionData['is_system'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $permission = DB::table('permissions')
                    ->where('name', $permissionData['name'])
                    ->where('guard_name', 'api')
                    ->first();

                $alreadyAssigned = DB::table('role_has_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists();

                if (!$alreadyAssigned) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permission->id,
                        'role_id' => $role->id,
                    ]);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $role = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', 'api')
                ->first();

            foreach ($this->permissions as $permissionData) {
                $permission = DB::table('permissions')
                    ->where('name', $permissionData['name'])
                    ->where('guard_name', 'api')
                    ->first();

                if (!$permission) {
                    continue;
                }

                // Remove the assignment made for SYSTEM_ADMIN.
                if ($role) {
                    DB::table('role_has_permissions')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permission->id)
                        ->delete();
                }

                // Preserve permissions still assigned to other roles or users.
                $assignedToRole = DB::table('role_has_permissions')
                    ->where('permission_id', $permission->id)
                    ->exists();

                $assignedDirectlyToUser = DB::table('model_has_permissions')
                    ->where('permission_id', $permission->id)
                    ->exists();

                if (!$assignedToRole && !$assignedDirectlyToUser) {
                    DB::table('permissions')
                        ->where('id', $permission->id)
                        ->delete();
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};