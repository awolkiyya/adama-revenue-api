<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Authorization Foundation
        |--------------------------------------------------------------------------
        |
        | Permissions must exist before roles are assigned permissions.
        |
        */

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. Administrative Structure
        |--------------------------------------------------------------------------
        |
        | Administrative units must exist before clusters and sectors.
        |
        */

        $this->call([
            AdministrativeUnitSeeder::class,
            ClusterSeeder::class,
            SectorSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 3. Revenue Foundation
        |--------------------------------------------------------------------------
        |
        | Measurement units must exist before base fields.
        |
        */

        $this->call([
            MeasurementUnitSeeder::class,
            RevenueCategorySeeder::class,
            BaseFieldSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Revenue Actions and Configuration
        |--------------------------------------------------------------------------
        */

        $this->call([
            ServiceActionSeeder::class,
            RevenueSettingSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Payment Configuration
        |--------------------------------------------------------------------------
        */

        $this->call([
            PaymentProviderSeeder::class,
            BankAccountSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 6. System Administrators
        |--------------------------------------------------------------------------
        */

        $this->call([
            SystemAdminSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 7. Field Collectors
        |--------------------------------------------------------------------------
        |
        | Requires roles and administrative units to exist.
        |
        */

        $this->call([
            FieldCollectorSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 8. Demo / Development Data
        |--------------------------------------------------------------------------
        |
        | These seeders create sample records.
        | Do not execute them in production.
        |
        */

        if (app()->environment(['local', 'testing', 'staging'])) {
            $this->call([
                CitizenSeeder::class,
            ]);
        }
    }
}
