<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdministrativeUnit;


class AdministrativeUnitSeeder extends Seeder
{

    public function run(): void
    {


        /*
        |--------------------------------------------------------------------------
        | CITY
        |--------------------------------------------------------------------------
        */

        $city = AdministrativeUnit::updateOrCreate(

            [
                'code' => 'ADAMA',
            ],

            [
                'name' => 'Adama',
                'level' => 'CITY',
                'parent_id' => null,
                'is_active' => true,
            ]

        );



        /*
        |--------------------------------------------------------------------------
        | SUBCITIES
        |--------------------------------------------------------------------------
        */

        $subcitiesData = [

            [
                'key' => 'Abbaa Gadaa',
                'name' => 'Kutaa Magaalaa Abbaa Gadaa',
                'code' => 'ADM-AG',
            ],

            [
                'key' => 'Boolee',
                'name' => 'Kutaa Magaalaa Boolee',
                'code' => 'ADM-BO',
            ],

            [
                'key' => 'Daabee',
                'name' => 'Kutaa Magaalaa Daabee',
                'code' => 'ADM-DA',
            ],

            [
                'key' => 'Bokkuu Shanan',
                'name' => 'Kutaa Magaalaa Bokkuu Shanan',
                'code' => 'ADM-BS',
            ],

            [
                'key' => 'Luugoo',
                'name' => 'Kutaa Magaalaa Luugoo',
                'code' => 'ADM-LG',
            ],

            [
                'key' => 'Dambalaa',
                'name' => 'Kutaa Magaalaa Dambalaa',
                'code' => 'ADM-DM',
            ],

        ];



        $subcities = [];



        foreach ($subcitiesData as $item) {


            $subcity = AdministrativeUnit::updateOrCreate(

                [
                    'code' => $item['code'],
                ],

                [
                    'name' => $item['name'],
                    'level' => 'SUBCITY',
                    'parent_id' => $city->id,
                    'is_active' => true,
                ]

            );


            $subcities[$item['key']] = $subcity->id;

        }




        /*
        |--------------------------------------------------------------------------
        | WEREDAS
        |--------------------------------------------------------------------------
        */

        $weredas = [

            'Abbaa Gadaa' => [

                [
                    'name' => 'Aanaa Buttaa',
                    'code' => 'ADM-AG-W01'
                ],

                [
                    'name' => 'Aanaa Badhaatuu',
                    'code' => 'ADM-AG-W02'
                ],

                [
                    'name' => 'Aanaa Odaa',
                    'code' => 'ADM-AG-W03'
                ],

                [
                    'name' => 'Aanaa Dagaagaa',
                    'code' => 'ADM-AG-W04'
                ],

            ],


            'Boolee' => [

                [
                    'name' => 'Aanaa Dhaddacha Araaraa',
                    'code' => 'ADM-BO-W01'
                ],

                [
                    'name' => 'Aanaa Dhagaa Adii',
                    'code' => 'ADM-BO-W02'
                ],

                [
                    'name' => 'Aanaa Gooroo',
                    'code' => 'ADM-BO-W03'
                ],

            ],


            'Daabee' => [

                [
                    'name' => 'Aanaa Caffee',
                    'code' => 'ADM-DA-W01'
                ],

                [
                    'name' => 'Aanaa Hangaatuu',
                    'code' => 'ADM-DA-W02'
                ],

                [
                    'name' => 'Aanaa Daabee Dongorree',
                    'code' => 'ADM-DA-W03'
                ],

            ],


            'Bokkuu Shanan' => [

                [
                    'name' => 'Aanaa Torban Oboo',
                    'code' => 'ADM-BS-W01'
                ],

                [
                    'name' => 'Aanaa Aroorettii',
                    'code' => 'ADM-BS-W02'
                ],

                [
                    'name' => "Aanaa Awaash Malkaa Sa'aa",
                    'code' => 'ADM-BS-W03'
                ],

            ],


            'Luugoo' => [

                [
                    'name' => 'Aanaa Barreechaa',
                    'code' => 'ADM-LG-W01'
                ],

                [
                    'name' => 'Aanaa Migiraa',
                    'code' => 'ADM-LG-W02'
                ],

                [
                    'name' => 'Aanaa Dirree Nagaa',
                    'code' => 'ADM-LG-W03'
                ],

            ],


            'Dambalaa' => [

                [
                    'name' => 'Aanaa Irreechaa',
                    'code' => 'ADM-DM-W01'
                ],

                [
                    'name' => 'Aanaa Malkaa Adaamaa',
                    'code' => 'ADM-DM-W02'
                ],

                [
                    'name' => 'Aanaa Wanjii',
                    'code' => 'ADM-DM-W03'
                ],

            ],

        ];



        foreach ($weredas as $subcity => $items) {


            foreach ($items as $wereda) {


                AdministrativeUnit::updateOrCreate(

                    [
                        'code' => $wereda['code'],
                    ],

                    [
                        'name' => $wereda['name'],
                        'level' => 'WEREDA',
                        'parent_id' => $subcities[$subcity],
                        'is_active' => true,
                    ]

                );

            }

        }



        $this->command->info(
            'Administrative units seeded successfully.'
        );

    }

}