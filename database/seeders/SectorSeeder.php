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
                 * BULCHIINSA CLUSTER (6)
                 * =====================================================
                 */
                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Waajjira Bulchiinsa Kantiibaa',
                    'code' => 'MAYOR',
                    'description' => 'Mayor Administration Office',
                ],

                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Waajjira Abbaa Alangaa',
                    'code' => 'JUS',
                    'description' => 'Attorney General Office',
                ],

                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Waajjira Milishaa',
                    'code' => 'MIL',
                    'description' => 'Militia Office',
                ],

                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Qajeelcha Poolisii',
                    'code' => 'POL',
                    'description' => 'Police Commission',
                ],

                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Waajjira Bulchiinsaa fi Nageenyaa',
                    'code' => 'SEC',
                    'description' => 'Administration and Security Office',
                ],

                [
                    'cluster_id' => $bulchiinsa->id,
                    'name' => 'Waajjira Dhimmoota Kominikeeshinii Mootummaa',
                    'code' => 'COM',
                    'description' => 'Government Communication Affairs Office',
                ],

                /**
                 * =====================================================
                 * DINAGDEE CLUSTER (12)
                 * =====================================================
                 */
                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Maallaqaa',
                    'code' => 'FIN',
                    'description' => 'Finance Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Daldalaa',
                    'code' => 'TRD',
                    'description' => 'Trade Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Investimentii',
                    'code' => 'INV',
                    'description' => 'Investment Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Qonnaa',
                    'code' => 'AGR',
                    'description' => 'Agriculture Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Dhaabbata Bishaanii fi Dhangala’aa',
                    'code' => 'WTR',
                    'description' => 'Water and Sewerage Authority',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Karooraa fi Misooma Magaalaa',
                    'code' => 'PLAN',
                    'description' => 'City Planning and Development Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Abbaa Taayitaa Eegumsa Naannoo',
                    'code' => 'ENV',
                    'description' => 'Environmental Protection Authority',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Galii',
                    'code' => 'REV',
                    'description' => 'Revenue Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Carraa Hojii Uumuufi Ogummaa',
                    'code' => 'EMP',
                    'description' => 'Employment Creation and Skills Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Waldaa Hojii Gamtaa',
                    'code' => 'COOP',
                    'description' => 'Cooperative Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Waajjira Misooma Albuudaa',
                    'code' => 'MIN',
                    'description' => 'Mineral Development Office',
                ],

                [
                    'cluster_id' => $dinagdee->id,
                    'name' => 'Invastimant Giruuppii Adamaa',
                    'code' => 'AIG',
                    'description' => 'Adama Investment Group',
                ],

            /**
             * =====================================================
             * HAWAASUMMAA CLUSTER (16)
             * =====================================================
             */
            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Fayyaa',
                'code' => 'HLT',
                'description' => 'Health Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Barnootaa',
                'code' => 'EDU',
                'description' => 'Education Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Dargaggoo fi Ispoortii',
                'code' => 'SPRT',
                'description' => 'Youth and Sport Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Saayinsii fi Teknooloojii',
                'code' => 'TECH',
                'description' => 'Science and Technology Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Dhimma Dubartootaa fi Da’immanii',
                'code' => 'WDD',
                'description' => 'Women and Children Affairs Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Dhimma Hojjataa fi Hawaasummaa',
                'code' => 'SOC',
                'description' => 'Labor and Social Affairs Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Galmeessa Ragaalee Bu’uraa',
                'code' => 'REG',
                'description' => 'Vital Events Registration Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Tajaajila Mootummaa Dijitaalaa',
                'code' => 'MESOB',
                'description' => 'Digital Government Service Office',
            ],
            
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

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Aadaa',
                'code' => 'CUL',
                'description' => 'Culture Office',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Komishinii Turizimii',
                'code' => 'TOUR',
                'description' => 'Tourism Commission',
            ],

            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Tajaajila Ummataa fi Misooma Qabeenya Humna Namaa',
                'code' => 'PSHR',
                'description' => 'Public Service and Human Resource Development Office',
            ],
            [
                'cluster_id' => $hawasummaa->id,
                'name' => 'Waajjira Busaa Gonofaa',
                'code' => 'PHE',
                'description' => 'Public Health Emergency / Disease Prevention Office',
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