<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sector;
use App\Models\Cluster;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * =====================================================
         * GET CLUSTERS
         * =====================================================
         */
        $bulchiinsa = Cluster::where('code', 'BUL')->first();
        $dinagdee = Cluster::where('code', 'DIN')->first();
        $hawasummaa = Cluster::where('code', 'HAW')->first();

        if (!$bulchiinsa || !$dinagdee || !$hawasummaa) {
            $this->command->error('Clusters not found. Please seed clusters first.');
            return;
        }

        /**
         * =====================================================
         * SECTORS
         * =====================================================
         */
        $sectors = [


                /**
                 * =====================================================
                 * DINAGDEE CLUSTER (12)
                 * =====================================================
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


            /**
             * =====================================================
             * HAWAASUMMAA CLUSTER (16)
             * =====================================================
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

        /**
         * =====================================================
         * INSERT / UPDATE
         * =====================================================
         */
        foreach ($sectors as $sector) {

            Sector::updateOrCreate(
                [
                    'cluster_id' => $sector['cluster_id'],
                    'code' => $sector['code'],
                ],
                [
                    'name' => $sector['name'],
                    'description' => $sector['description'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('34 sectors seeded successfully.');
    }
}