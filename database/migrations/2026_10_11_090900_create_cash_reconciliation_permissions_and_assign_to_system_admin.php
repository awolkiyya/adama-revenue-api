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
            'description' => 'Allows viewing cash reconciliation records within the user\'s authorized access scope.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.view_all',
            'label' => 'View All Cash Reconciliations',
            'module' => 'cash_reconciliations',
            'description' => 'Allows viewing cash reconciliations belonging to all authorized revenue collectors.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.create',
            'label' => 'Create Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows creating cash reconciliation drafts for the authenticated revenue collector.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.update',
            'label' => 'Update Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows updating eligible draft or returned cash reconciliations owned by the authenticated revenue collector.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.submit',
            'label' => 'Submit Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows submitting cash reconciliations for finance review.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.review',
            'label' => 'Review Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows reviewing submitted cash reconciliations, documenting discrepancies, and returning records for correction.',
            'is_system' => false,
        ],
        [
            'name' => 'cash_reconciliations.cancel',
            'label' => 'Cancel Cash Reconciliation',
            'module' => 'cash_reconciliations',
            'description' => 'Allows cancelling eligible draft or returned cash reconciliations owned by the authenticated revenue collector.',
            'is_system' => false,
        ],
    ];

    /**
     * Track permissions created by this migration.
     *
     * This is used to avoid deleting pre-existing permissions
     * during rollback.
     */
    private array $createdPermissionNames = [];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $role = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', 'api')
                ->first();

            if (! $role) {
                throw new RuntimeException(
                    'The SYSTEM_ADMIN role with guard_name "api" was not found. '
                    . 'Create the role before running this migration.'
                );
            }

            foreach ($this->permissions as $permissionData) {
                $now = now();

                $permission = DB::table('permissions')
                    ->where('name', $permissionData['name'])
                    ->where('guard_name', 'api')
                    ->first();

                if ($permission) {
                    // Update existing metadata without resetting created_at.
                    DB::table('permissions')
                        ->where('id', $permission->id)
                        ->update([
                            'label' => $permissionData['label'],
                            'module' => $permissionData['module'],
                            'description' => $permissionData['description'],
                            'is_system' => $permissionData['is_system'],
                            'updated_at' => $now,
                        ]);

                    $permissionId = $permission->id;
                } else {
                    $permissionId = DB::table('permissions')
                        ->insertGetId([
                            'name' => $permissionData['name'],
                            'guard_name' => 'api',
                            'label' => $permissionData['label'],
                            'module' => $permissionData['module'],
                            'description' => $permissionData['description'],
                            'is_system' => $permissionData['is_system'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                    $this->createdPermissionNames[] =
                        $permissionData['name'];
                }

                DB::table('role_has_permissions')->updateOrInsert(
                    [
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ],
                    []
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * IMPORTANT:
         *
         * Laravel creates a fresh migration instance during rollback.
         * Therefore, $createdPermissionNames populated in up() is not
         * reliably available here.
         *
         * To guarantee safe rollback of pre-existing permissions,
         * remove only assignments for this module's permissions and
         * leave the permission records intact.
         *
         * If you require full rollback of newly created permissions,
         * persist their IDs in a dedicated migration tracking table.
         */

        DB::transaction(function (): void {
            $role = DB::table('roles')
                ->where('name', 'SYSTEM_ADMIN')
                ->where('guard_name', 'api')
                ->first();

            foreach ($this->permissions as $permissionData) {
                $permission = DB::table('permissions')
                    ->where('name', $permissionData['name'])
                    ->where('guard_name', 'api')
                    ->first();

                if (! $permission || ! $role) {
                    continue;
                }

                DB::table('role_has_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
