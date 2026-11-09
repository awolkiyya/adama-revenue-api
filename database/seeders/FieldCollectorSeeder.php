<?php

namespace Database\Seeders;

use App\Models\AdministrativeUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class FieldCollectorSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */

        $cityUnit = AdministrativeUnit::where('name', 'Aanaa Buttaa')
            ->first();

        if (!$cityUnit) {
            throw new \RuntimeException(
                'Administrative unit "Aanaa Buttaa" was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Collector Account
        |--------------------------------------------------------------------------
        */

        $collector = User::firstOrCreate(
            ['email' => 'collector@adama.gov.et'],
            [
                'name' => 'field_collector',
                'label' => 'Field Revenue Collector',
                'phone' => '+251911000001',
                'password' => Hash::make('ChangeMe@12345'),
                'user_type' => 'employee',
                'is_phone_verified' => true,
                'is_active' => true,
                'administrative_unit_id' => $cityUnit->id,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Assign Revenue Collector Role
        |--------------------------------------------------------------------------
        */

        $role = Role::where('name', 'REVENUE_COLLECTOR')
            ->where('guard_name', 'api')
            ->first();

        if (!$role) {
            throw new \RuntimeException(
                'REVENUE_COLLECTOR role with api guard was not found.'
            );
        }

        if (!$collector->hasRole($role)) {
            $collector->assignRole($role);
        }
    }
}