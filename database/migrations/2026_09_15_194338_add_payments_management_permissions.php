
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Payments Management Permissions
        |--------------------------------------------------------------------------
        */

        $permissions = [
            [
                'name' => 'payments.view',
                'label' => 'View Payments',
                'module' => 'payments',
                'description' => 'Allows accessing the payment collection and payment management module and related screens.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.read',
                'label' => 'Read Payments',
                'module' => 'payments',
                'description' => 'Allows retrieving and viewing payment records, payment amounts, payment methods, transaction references, and payment status.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.collect',
                'label' => 'Collect Payment',
                'module' => 'payments',
                'description' => 'Allows collecting payments against eligible invoices using authorized payment methods such as cash or bank transfer.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.cancel',
                'label' => 'Cancel Payment',
                'module' => 'payments',
                'description' => 'Allows cancelling an eligible payment according to payment lifecycle and financial control rules.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.reverse',
                'label' => 'Reverse Payment',
                'module' => 'payments',
                'description' => 'Allows reversing a completed payment according to authorized financial reversal procedures.',
                'is_system' => false,
            ],

            [
                'name' => 'payments.view_history',
                'label' => 'View Payment History',
                'module' => 'payments',
                'description' => 'Allows viewing the history of payment collection, cancellation, reversal, and other recorded payment changes.',
                'is_system' => false,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Create Permissions
        |--------------------------------------------------------------------------
        */

        $permissionModels = [];

        foreach ($permissions as $permission) {
            $permissionModels[] = Permission::updateOrCreate(
                [
                    'name' => $permission['name'],
                    'guard_name' => 'api',
                ],
                $permission + [
                    'guard_name' => 'api',
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assign Permissions to SYSTEM_ADMIN
        |--------------------------------------------------------------------------
        */

        $systemAdminRole = Role::where('name', 'SYSTEM_ADMIN')
            ->where('guard_name', 'api')
            ->first();

        if ($systemAdminRole) {
            $systemAdminRole->givePermissionTo($permissionModels);
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Permission Cache
        |--------------------------------------------------------------------------
        */

        app(\Spatie\Permission\PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionNames = [
            'payments.view',
            'payments.read',
            'payments.collect',
            'payments.cancel',
            'payments.reverse',
            'payments.view_history',
        ];

        /*
        |--------------------------------------------------------------------------
        | Remove Permissions from SYSTEM_ADMIN
        |--------------------------------------------------------------------------
        */

        $systemAdminRole = Role::where('name', 'SYSTEM_ADMIN')
            ->where('guard_name', 'api')
            ->first();

        if ($systemAdminRole) {
            $permissions = Permission::where(
                'guard_name',
                'api'
            )->whereIn(
                'name',
                $permissionNames
            )->get();

            $systemAdminRole->revokePermissionTo($permissions);
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Permissions
        |--------------------------------------------------------------------------
        */

        Permission::query()
            ->where('guard_name', 'api')
            ->whereIn('name', $permissionNames)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Clear Permission Cache
        |--------------------------------------------------------------------------
        */

        app(\Spatie\Permission\PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }
};