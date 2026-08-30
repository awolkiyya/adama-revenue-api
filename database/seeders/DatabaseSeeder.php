<?php

namespace Database\Seeders;

use App\Models\User;
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

            PermissionSeeder::class,
    
            RoleSeeder::class,
    
            RolePermissionSeeder::class,

            AdministrativeUnitSeeder::class,
            
            SystemAdminSeeder::class,

            ClusterSeeder::class,

            SectorSeeder::class,

            RevenueCategorySeeder::class,

            CitizenSeeder::class,

            MeasurementUnitSeeder::class,
            BaseFieldSeeder::class,



    
        ]);
    }
}
