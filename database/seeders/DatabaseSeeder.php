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
        $this->call([

            /*
            |--------------------------------------------------------------------------
            | Authorization
            |--------------------------------------------------------------------------
            */

            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Administrative Structure
            |--------------------------------------------------------------------------
            */

            AdministrativeUnitSeeder::class,
            SystemAdminSeeder::class,
            ClusterSeeder::class,
            SectorSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Revenue Foundation
            |--------------------------------------------------------------------------
            */

            RevenueCategorySeeder::class,
            MeasurementUnitSeeder::class,
            BaseFieldSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Revenue Global Configuration
            |--------------------------------------------------------------------------
            */

            RevenueSettingSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Citizens
            |--------------------------------------------------------------------------
            */

            CitizenSeeder::class,
        ]);
    }
}