<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RuntimeException;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permission guard used by the application.
     */
    private string $guard = 'api';

    /**
     * Permissions introduced by this migration.
     *
     * @var array<int, array{
     *     name: string,
     *     label: string,
     *     module: string,
     *     description: string,
     *     is_system: bool
     * }>
     */
    private array $permissions = [
        /*
        |--------------------------------------------------------------------------
        | Bank Accounts
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'bank_accounts.view',
            'label' => 'View Bank Accounts',
            'module' => 'bank_accounts',
            'description' => 'Allows viewing bank accounts configured for municipal revenue collection.',
            'is_system' => false,
        ],
        [
            'name' => 'bank_accounts.create',
            'label' => 'Create Bank Account',
            'module' => 'bank_accounts',
            'description' => 'Allows registering a bank account for authorized revenue collection.',
            'is_system' => false,
        ],
        [
            'name' => 'bank_accounts.update',
            'label' => 'Update Bank Account',
            'module' => 'bank_accounts',
            'description' => 'Allows updating eligible bank account configuration.',
            'is_system' => false,
        ],
        [
            'name' => 'bank_accounts.activate',
            'label' => 'Activate Bank Account',
            'module' => 'bank_accounts',
            'description' => 'Allows activating an eligible bank account.',
            'is_system' => false,
        ],
        [
            'name' => 'bank_accounts.deactivate',
            'label' => 'Deactivate Bank Account',
            'module' => 'bank_accounts',
            'description' => 'Allows deactivating an eligible bank account.',
            'is_system' => false,
        ],
        [
            'name' => 'bank_accounts.view_history',
            'label' => 'View Bank Account History',
            'module' => 'bank_accounts',
            'description' => 'Allows viewing recorded bank account configuration changes.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Payment Providers
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'payment_providers.view',
            'label' => 'View Payment Providers',
            'module' => 'payment_providers',
            'description' => 'Allows viewing configured payment providers and their status.',
            'is_system' => false,
        ],
        [
            'name' => 'payment_providers.create',
            'label' => 'Create Payment Provider',
            'module' => 'payment_providers',
            'description' => 'Allows configuring a supported payment provider.',
            'is_system' => false,
        ],
        [
            'name' => 'payment_providers.update',
            'label' => 'Update Payment Provider',
            'module' => 'payment_providers',
            'description' => 'Allows updating eligible payment provider configuration.',
            'is_system' => false,
        ],
        [
            'name' => 'payment_providers.activate',
            'label' => 'Activate Payment Provider',
            'module' => 'payment_providers',
            'description' => 'Allows activating a configured payment provider.',
            'is_system' => false,
        ],
        [
            'name' => 'payment_providers.deactivate',
            'label' => 'Deactivate Payment Provider',
            'module' => 'payment_providers',
            'description' => 'Allows deactivating a configured payment provider.',
            'is_system' => false,
        ],
        [
            'name' => 'payment_providers.view_history',
            'label' => 'View Payment Provider History',
            'module' => 'payment_providers',
            'description' => 'Allows viewing recorded payment provider configuration changes.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Lease Amendments
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'lease_amendments.view',
            'label' => 'View Lease Amendments',
            'module' => 'lease_amendments',
            'description' => 'Allows viewing lease amendment requests and their status.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.create',
            'label' => 'Create Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows creating a lease amendment request.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.update',
            'label' => 'Update Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows updating eligible lease amendment drafts.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.submit',
            'label' => 'Submit Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows submitting a lease amendment for approval.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.approve',
            'label' => 'Approve Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows approving a lease amendment request.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.reject',
            'label' => 'Reject Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows rejecting a lease amendment request.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.apply',
            'label' => 'Apply Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows applying an approved lease amendment according to the amendment workflow.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.cancel',
            'label' => 'Cancel Lease Amendment',
            'module' => 'lease_amendments',
            'description' => 'Allows cancelling an eligible lease amendment.',
            'is_system' => false,
        ],
        [
            'name' => 'lease_amendments.view_history',
            'label' => 'View Lease Amendment History',
            'module' => 'lease_amendments',
            'description' => 'Allows viewing the history of lease amendment requests and decisions.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Field Collection
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'field_collection.view',
            'label' => 'View Field Collections',
            'module' => 'field_collection',
            'description' => 'Allows viewing authorized field collection records.',
            'is_system' => false,
        ],
        [
            'name' => 'field_collection.create',
            'label' => 'Create Field Collection',
            'module' => 'field_collection',
            'description' => 'Allows recording an authorized field collection transaction.',
            'is_system' => false,
        ],
        [
            'name' => 'field_collection.submit',
            'label' => 'Submit Field Collection',
            'module' => 'field_collection',
            'description' => 'Allows submitting field collection records according to the collection workflow.',
            'is_system' => false,
        ],
        [
            'name' => 'field_collection.view_history',
            'label' => 'View Field Collection History',
            'module' => 'field_collection',
            'description' => 'Allows viewing field collection transaction history.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Penalty Operations
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'penalty.view',
            'label' => 'View Penalties',
            'module' => 'penalty',
            'description' => 'Allows viewing penalties applied to eligible revenue obligations.',
            'is_system' => false,
        ],
        [
            'name' => 'penalty.calculate',
            'label' => 'Calculate Penalty',
            'module' => 'penalty',
            'description' => 'Allows calculating penalties according to configured rules.',
            'is_system' => false,
        ],
        [
            'name' => 'penalty.apply',
            'label' => 'Apply Penalty',
            'module' => 'penalty',
            'description' => 'Allows applying an authorized penalty to an eligible revenue obligation.',
            'is_system' => false,
        ],
        [
            'name' => 'penalty.adjust',
            'label' => 'Adjust Penalty',
            'module' => 'penalty',
            'description' => 'Allows making authorized adjustments to penalties.',
            'is_system' => false,
        ],
        [
            'name' => 'penalty.waive',
            'label' => 'Waive Penalty',
            'module' => 'penalty',
            'description' => 'Allows waiving a penalty when authorized by the applicable workflow.',
            'is_system' => false,
        ],
        [
            'name' => 'penalty.view_history',
            'label' => 'View Penalty History',
            'module' => 'penalty',
            'description' => 'Allows viewing penalty calculation, application, adjustment, and waiver history.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Adjustments
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'revenue_adjustments.view',
            'label' => 'View Revenue Adjustments',
            'module' => 'revenue_adjustments',
            'description' => 'Allows viewing revenue adjustment requests and records.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.create',
            'label' => 'Create Revenue Adjustment',
            'module' => 'revenue_adjustments',
            'description' => 'Allows creating a revenue adjustment request.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.review',
            'label' => 'Review Revenue Adjustment',
            'module' => 'revenue_adjustments',
            'description' => 'Allows reviewing a revenue adjustment request.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.approve',
            'label' => 'Approve Revenue Adjustment',
            'module' => 'revenue_adjustments',
            'description' => 'Allows approving a revenue adjustment request.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.reject',
            'label' => 'Reject Revenue Adjustment',
            'module' => 'revenue_adjustments',
            'description' => 'Allows rejecting a revenue adjustment request.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.cancel',
            'label' => 'Cancel Revenue Adjustment',
            'module' => 'revenue_adjustments',
            'description' => 'Allows cancelling an eligible revenue adjustment request.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_adjustments.view_history',
            'label' => 'View Revenue Adjustment History',
            'module' => 'revenue_adjustments',
            'description' => 'Allows viewing revenue adjustment history.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Complaints
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'revenue_complaints.view',
            'label' => 'View Revenue Complaints',
            'module' => 'revenue_complaints',
            'description' => 'Allows viewing revenue complaints and their workflow status.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.create',
            'label' => 'Create Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows creating a revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.update',
            'label' => 'Update Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows updating an eligible revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.review',
            'label' => 'Review Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows reviewing a revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.request_information',
            'label' => 'Request Complaint Information',
            'module' => 'revenue_complaints',
            'description' => 'Allows requesting additional information for a revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.recommend',
            'label' => 'Recommend Complaint Decision',
            'module' => 'revenue_complaints',
            'description' => 'Allows submitting a recommendation for a revenue complaint decision.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.reject',
            'label' => 'Reject Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows rejecting a revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.escalate',
            'label' => 'Escalate Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows escalating a revenue complaint for further review.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.close',
            'label' => 'Close Revenue Complaint',
            'module' => 'revenue_complaints',
            'description' => 'Allows closing a revenue complaint.',
            'is_system' => false,
        ],
        [
            'name' => 'revenue_complaints.view_history',
            'label' => 'View Revenue Complaint History',
            'module' => 'revenue_complaints',
            'description' => 'Allows viewing revenue complaint history.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Reports
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'reports.view',
            'label' => 'View Reports',
            'module' => 'reports',
            'description' => 'Allows accessing authorized system reports.',
            'is_system' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Mobile App Releases
        |--------------------------------------------------------------------------
        */

        [
            'name' => 'mobile_app_releases.view',
            'label' => 'View Mobile App Releases',
            'module' => 'mobile_app_releases',
            'description' => 'Allows viewing mobile application release records and update status.',
            'is_system' => false,
        ],
        [
            'name' => 'mobile_app_releases.create',
            'label' => 'Create Mobile App Release',
            'module' => 'mobile_app_releases',
            'description' => 'Allows creating a mobile application release record.',
            'is_system' => false,
        ],
        [
            'name' => 'mobile_app_releases.update',
            'label' => 'Update Mobile App Release',
            'module' => 'mobile_app_releases',
            'description' => 'Allows updating eligible mobile application release records.',
            'is_system' => false,
        ],
        [
            'name' => 'mobile_app_releases.publish',
            'label' => 'Publish Mobile App Release',
            'module' => 'mobile_app_releases',
            'description' => 'Allows publishing a mobile application release.',
            'is_system' => false,
        ],
        [
            'name' => 'mobile_app_releases.deactivate',
            'label' => 'Deactivate Mobile App Release',
            'module' => 'mobile_app_releases',
            'description' => 'Allows deactivating an eligible mobile application release.',
            'is_system' => false,
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $registrar->forgetCachedPermissions();

        try {
            DB::transaction(function (): void {
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
                        throw new \RuntimeException(
                            "Required table [{$table}] does not exist."
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Validate Permission Definitions
                |--------------------------------------------------------------------------
                */

                $permissionNames = array_column(
                    $this->permissions,
                    'name'
                );

                $duplicateNames = array_keys(
                    array_filter(
                        array_count_values($permissionNames),
                        static fn (int $count): bool => $count > 1
                    )
                );

                if ($duplicateNames !== []) {
                    throw new \RuntimeException(
                        'Duplicate permission names detected: ' .
                        implode(', ', $duplicateNames)
                    );
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
                    throw new \RuntimeException(
                        'SYSTEM_ADMIN role does not exist for the api guard. ' .
                        'Create the role before running this migration.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Create Permissions and Assign to SYSTEM_ADMIN
                |--------------------------------------------------------------------------
                |
                | Existing permission records are preserved. New records are
                | inserted only when the same name and guard do not exist.
                |
                */

                $now = now();

                foreach ($this->permissions as $permissionData) {
                    $permission = DB::table('permissions')
                        ->where('name', $permissionData['name'])
                        ->where('guard_name', $this->guard)
                        ->first(['id']);

                    if (!$permission) {
                        $permissionId = DB::table('permissions')
                            ->insertGetId([
                                'name' => $permissionData['name'],
                                'guard_name' => $this->guard,
                                'label' => $permissionData['label'],
                                'module' => $permissionData['module'],
                                'description' => $permissionData['description'],
                                'is_system' => $permissionData['is_system'],
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
        } finally {
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Reverse the migrations.
     *
     * Removes these permission assignments from SYSTEM_ADMIN.
     * Permission records are retained to avoid deleting permissions
     * that may have been assigned to other roles.
     */
    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $registrar->forgetCachedPermissions();

        try {
            DB::transaction(function (): void {
                if (
                    !DB::getSchemaBuilder()->hasTable('roles') ||
                    !DB::getSchemaBuilder()->hasTable('permissions') ||
                    !DB::getSchemaBuilder()->hasTable('role_has_permissions')
                ) {
                    return;
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
                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | Find Permissions Introduced by This Migration
                |--------------------------------------------------------------------------
                */

                $permissionNames = array_column(
                    $this->permissions,
                    'name'
                );

                $permissionIds = DB::table('permissions')
                    ->where('guard_name', $this->guard)
                    ->whereIn('name', $permissionNames)
                    ->pluck('id');

                /*
                |--------------------------------------------------------------------------
                | Remove SYSTEM_ADMIN Assignments Only
                |--------------------------------------------------------------------------
                |
                | Permission records and assignments to other roles remain
                | untouched.
                |
                */

                if ($permissionIds->isNotEmpty()) {
                    DB::table('role_has_permissions')
                        ->where('role_id', $systemAdmin->id)
                        ->whereIn('permission_id', $permissionIds)
                        ->delete();
                }
            });
        } finally {
            $registrar->forgetCachedPermissions();
        }
    }
};