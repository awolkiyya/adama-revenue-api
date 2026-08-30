<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {

        // Find the root City unit (ensure your AdministrativeUnitSeeder ran first!)
        $cityUnit = \App\Models\AdministrativeUnit::where('name', 'Aanaa Buttaa')->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@adama.gov.et'],
            [
                'name' => 'system_admin',
                'label' => 'System Administrator',
                'phone' => '+251900000000',
                'password' => Hash::make('ChangeMe@12345'),
                'user_type' => 'employee',
                'is_phone_verified' => true,
                'is_active' => true,
                // Now required:
                'administrative_unit_id' => $cityUnit?->id, 
            ]
        );



        /*
        |--------------------------------------------------------------------------
        | Assign System Admin Role
        |--------------------------------------------------------------------------
        */

        $role = Role::where('name', 'SYSTEM_ADMIN')
            ->where('guard_name', 'api')
            ->first();



        if ($role && !$admin->hasRole('SYSTEM_ADMIN')) {

            $admin->assignRole($role);

        }

    }
}