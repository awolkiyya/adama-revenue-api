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
     * Lease Amendment permissions.
     */
    private array $permissions = [
        [
            'name' => 'lease_amendments.view',
            'label' => 'View Lease Amendments',
            'description' => 'Allows accessing the Lease Amendment Management interface and related screens.',
        ],
        [
            'name' => 'lease_amendments.read',
            'label' => 'Read Lease Amendments',
            'description' => 'Allows retrieving and viewing lease amendment records, original lease details, proposed changes, assessment references, workflow status, and application results.',
        ],
        [
            'name' => 'lease_amendments.create',
            'label' => 'Create Lease Amendment',
            'description' => 'Allows creating a lease amendment for an eligible existing lease agreement or revenue assessment.',
        ],
        [
            'name' => 'lease_amendments.update',
            'label' => 'Update Lease Amendment',
            'description' => 'Allows updating eligible draft lease amendments before submission or finalization.',
        ],
        [
            'name' => 'lease_amendments.submit',
            'label' => 'Submit Lease Amendment',
            'description' => 'Allows submitting eligible lease amendments for review and an authorized decision.',
        ],
        [
            'name' => 'lease_amendments.approve',
            'label' => 'Approve Lease Amendment',
            'description' => 'Allows approving submitted lease amendments after the required review and validation.',
        ],
        [
            'name' => 'lease_amendments.reject',
            'label' => 'Reject Lease Amendment',
            'description' => 'Allows rejecting submitted lease amendments and recording the decision according to workflow rules.',
        ],
        [
            'name' => 'lease_amendments.apply',
            'label' => 'Apply Lease Amendment',
            'description' => 'Allows applying an approved lease amendment to the designated replacement assessment and associated financial schedules according to system rules.',
        ],
        [
            'name' => 'lease_amendments.cancel',
            'label' => 'Cancel Lease Amendment',
            'description' => 'Allows cancelling an eligible lease amendment before it reaches a workflow state that prohibits cancellation.',
        ],
        [
            'name' => 'lease_amendments.view_history',
            'label' => 'View Lease Amendment History',
            'description' => 'Allows viewing the history of lease amendment creation, updates, submissions, decisions, cancellations, and application.',
        ],
    ];

    /**
     * Apply the migration.
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
            | Find Existing System Admin Role
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
            | Create Missing Permissions
            |--------------------------------------------------------------------------
            |
            | Existing permission records are preserved, including their
            | current metadata and role assignments.
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
                        'module' => 'lease_amendments',
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
                | Assign Permission to System Admin
                |--------------------------------------------------------------------------
                |
                | Insert only if the relationship does not already exist.
                |
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
     * Reverse the migration.
     *
     * Removes only the specified permission assignments from SYSTEM_ADMIN.
     * Permission catalog records are deliberately retained because other
     * roles or application code may depend on them.
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
