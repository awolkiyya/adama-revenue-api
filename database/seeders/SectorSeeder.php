<?php

namespace Database\Seeders;

use App\Models\Cluster;
use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Get Adama City Clusters
        |--------------------------------------------------------------------------
        */

        $clusters = Cluster::query()
            ->whereHas('city', function ($query) {
                $query->where('name', 'Adama')
                    ->where('level', 'CITY');
            })
            ->whereIn('code', ['BUL', 'DIN', 'HAW'])
            ->get()
            ->keyBy('code');

        foreach (['BUL', 'DIN', 'HAW'] as $code) {
            if (!$clusters->has($code)) {
                throw new RuntimeException(
                    "Cluster '{$code}' was not found. Please seed clusters first."
                );
            }
        }

        $bulchiinsa = $clusters->get('BUL');
        $dinagdee = $clusters->get('DIN');
        $hawasummaa = $clusters->get('HAW');

        /*
        |--------------------------------------------------------------------------
        | Sector Reference Data
        |--------------------------------------------------------------------------
        */

        $sectors = [
            /*
            |--------------------------------------------------------------------------
            | Dinagdee Cluster
            |--------------------------------------------------------------------------
            */

            [
                'cluster_id' => $dinagdee->id,
                'name' => 'Dhaabbata Bishaanii fi Dhangala’aa',
                'code' => 'WTR',
                'description' => 'Water and Sewerage Authority',
            ],
            [
                'cluster_id' => $dinagdee->id,
                'name' => 'Waajjira Galii',
                'code' => 'REV',
                'description' => 'Revenue Office',
            ],

            /*
            |--------------------------------------------------------------------------
            | Hawaasummaa Cluster
            |--------------------------------------------------------------------------
            */

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Lafaa',
                'code' => 'LAND',
                'description' => 'Land Administration Office',
            ],
            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Geejjibaa',
                'code' => 'TRN',
                'description' => 'Transport Office',
            ],
            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Mana Qopheessaa',
                'code' => 'HOU',
                'description' => 'Housing Office',
            ],
            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Konistraakshinii',
                'code' => 'CON',
                'description' => 'Construction Office',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Insert Missing Sectors
        |--------------------------------------------------------------------------
        |
        | Existing sector UUIDs and administrator-managed fields
        | must never be overwritten by routine seeding.
        |
        */

        $created = 0;
        $existing = 0;

        DB::transaction(function () use (
            $sectors,
            &$created,
            &$existing
        ) {
            foreach ($sectors as $sector) {

                $exists = Sector::query()
                    ->where('cluster_id', $sector['cluster_id'])
                    ->where('code', $sector['code'])
                    ->exists();

                if ($exists) {
                    $existing++;
                    continue;
                }

                Sector::query()->create([
                    'id' => (string) Str::uuid(),
                    'cluster_id' => $sector['cluster_id'],
                    'name' => $sector['name'],
                    'code' => $sector['code'],
                    'description' => $sector['description'],
                    'is_active' => true,
                ]);

                $created++;
            }
        });

        $this->command?->info(
            "Sector seeding completed. Created: {$created}, Existing: {$existing}."
        );
    }
}
