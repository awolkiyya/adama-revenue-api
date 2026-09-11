<?php

namespace Database\Seeders;

use App\Models\AdministrativeUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClusterSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Get Adama City Administrative Unit
        |--------------------------------------------------------------------------
        */

        $city = AdministrativeUnit::query()
            ->where('name', 'Adama')
            ->where('level', 'CITY')
            ->first();

        if (!$city) {
            $this->command->error(
                'City "Adama" not found. Please seed administrative units first.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Clusters Data
        |--------------------------------------------------------------------------
        */

        $clusters = [
            [
                'name' => 'Bulchiinsa',
                'code' => 'BUL',
                'description' => 'Kutaa bulchiinsa Magaalaa Adaamaa keessaa tokko',
            ],

            [
                'name' => 'Dinagdee',
                'code' => 'DIN',
                'description' => 'Kutaa dinagdee Magaalaa Adaamaa keessaa tokko',
            ],

            [
                'name' => 'Hawaasummaa',
                'code' => 'HAW',
                'description' => 'Kutaa hawaasummaa Magaalaa Adaamaa keessaa tokko',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Insert / Update Clusters
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Existing cluster UUIDs must never be replaced because other tables
        | such as "sectors" reference clusters.id.
        |
        */

        foreach ($clusters as $cluster) {

            $existingCluster = DB::table('clusters')
                ->where('city_id', $city->id)
                ->where('name', $cluster['name'])
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Update Existing Cluster
            |--------------------------------------------------------------------------
            */

            if ($existingCluster) {
                DB::table('clusters')
                    ->where('id', $existingCluster->id)
                    ->update([
                        'code' => $cluster['code'],
                        'description' => $cluster['description'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Insert New Cluster
            |--------------------------------------------------------------------------
            */

            DB::table('clusters')->insert([
                'id' => (string) Str::uuid(),
                'city_id' => $city->id,
                'name' => $cluster['name'],
                'code' => $cluster['code'],
                'description' => $cluster['description'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info(
            'Clusters seeded successfully.'
        );
    }
}