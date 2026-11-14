<?php

namespace Database\Seeders;

use App\Models\AdministrativeUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class FieldCollectorSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Configuration
        |--------------------------------------------------------------------------
        */

        $email = env('FIELD_COLLECTOR_EMAIL');
        $password = env('FIELD_COLLECTOR_PASSWORD');

        if (empty($email) || empty($password)) {
            throw new RuntimeException(
                'FIELD_COLLECTOR_EMAIL and FIELD_COLLECTOR_PASSWORD must be configured.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */

        $cityUnit = AdministrativeUnit::query()
            ->where('name', 'Aanaa Buttaa')
            ->where('level', 'WEREDA')
            ->first();

        if (!$cityUnit) {
            throw new RuntimeException(
                'Administrative unit "Aanaa Buttaa" (WEREDA) was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Collector Role
        |--------------------------------------------------------------------------
        */

        $role = Role::query()
            ->where('name', 'REVENUE_COLLECTOR')
            ->where('guard_name', 'api')
            ->first();

        if (!$role) {
            throw new RuntimeException(
                'REVENUE_COLLECTOR role with api guard was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create Account
        |--------------------------------------------------------------------------
        |
        | Existing accounts are never overwritten.
        | In particular, passwords and account activation status
        | remain under administrator control.
        |
        */

        DB::transaction(function () use (
            $email,
            $password,
            $cityUnit,
            $role
        ) {
            $collector = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => 'field_collector',
                    'label' => 'Field Revenue Collector',
                    'phone' => '+251911000001',
                    'password' => Hash::make($password),
                    'user_type' => 'employee',
                    'is_phone_verified' => false,
                    'is_active' => true,
                    'administrative_unit_id' => $cityUnit->id,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Existing Account
            |--------------------------------------------------------------------------
            */

            if ($collector->user_type !== 'employee') {
                throw new RuntimeException(
                    "The account {$email} exists but is not an employee account."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Assign Role
            |--------------------------------------------------------------------------
            */

            if (!$collector->hasRole($role)) {
                $collector->assignRole($role);
            }
        });

        $this->command?->info(
            'Field collector account provisioning completed.'
        );
    }
}
