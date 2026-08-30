<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\AdministrativeUnit;

class ClusterSeeder extends Seeder
{

    public function run(): void
    {


        /*
        |--------------------------------------------------------------------------
        | Get Adama City Administrative Unit
        |--------------------------------------------------------------------------
        */

        $city = AdministrativeUnit::where('name', 'Adama')
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
        */

        foreach ($clusters as $cluster) {


            DB::table('clusters')->updateOrInsert(

                [

                    'city_id' => $city->id,

                    'name' => $cluster['name'],

                ],


                [

                    /*
                    |--------------------------------------------------------------------------
                    | UUID Primary Key
                    |--------------------------------------------------------------------------
                    */

                    'id' => Str::uuid(),


                    'code' => $cluster['code'],

                    'description' => $cluster['description'],

                    'is_active' => true,

                    'updated_at' => now(),

                    'created_at' => now(),

                ]

            );

        }



        $this->command->info(
            'Clusters seeded successfully.'
        );

    }

}