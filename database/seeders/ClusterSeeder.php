<?php

namespace Database\Seeders;

use App\Models\AdministrativeUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ClusterSeeder extends Seeder
{
    public function run(): void
    {
        $city = AdministrativeUnit::query()
            ->where('name', 'Adama')
            ->where('level', 'CITY')
            ->first();

        if (!$city) {
            throw new RuntimeException(
                'City "Adama" not found. Please seed administrative units first.'
            );
        }

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

        DB::transaction(function () use ($city, $clusters) {

            foreach ($clusters as $cluster) {

                $existing = DB::table('clusters')
                    ->where('city_id', $city->id)
                    ->where('name', $cluster['name'])
                    ->exists();

                if ($existing) {
                    continue;
                }

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
        });

        $this->command?->info('Clusters seeded successfully.');
    }
}
