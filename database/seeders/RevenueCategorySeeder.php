<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevenueCategorySeeder extends Seeder
{
    public function run(): void
    {

        DB::table('revenue_categories')->insert([


            /**
             * 1701 - 1719
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'TAX',

                'name' => 'Galii Taaksii Mana Gopheessaa',

                'start_code' => 1701,

                'end_code' => 1719,

                'description' =>
                    'Galii gibira mana jireenyaa fi mana hojii irraa argamu',

                'sort_order' => 1,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],



            /**
             * 1720 - 1729
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'RENT',

                'name' => 'Galii Kiraarraa Argamu',

                'start_code' => 1720,

                'end_code' => 1729,

                'description' =>
                    'Galii kiraa lafa, mana mootummaa fi qabeenya mootummaa irraa argamu',

                'sort_order' => 2,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],



            /**
             * 1731 - 1734
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'INVESTMENT',

                'name' => 'Galii Investimantii',

                'start_code' => 1731,

                'end_code' => 1734,

                'description' =>
                    'Galii invastimantii fi bu’aa qabeenya mootummaa irraa argamu',

                'sort_order' => 3,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],



            /**
             * 1740 - 1749
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'SERVICE',

                'name' => 'Kaffaltii Tajaajilaa',

                'start_code' => 1740,

                'end_code' => 1749,

                'description' =>
                    'Kaffaltii tajaajiloota mootummaa irraa argamu',

                'sort_order' => 4,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],



            /**
             * 1750 - 1789
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'SALE',

                'name' => 'Galii Gurgurtaa Tajaajilaa fi Meeshaawwanii',

                'start_code' => 1750,

                'end_code' => 1789,

                'description' =>
                    'Galii gurgurtaa meeshaalee fi tajaajiloota mootummaa',

                'sort_order' => 5,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],



            /**
             * 1790 - 1799
             */
            [
                'id' => Str::uuid(),

                'revenue_domain' => 'CAPITAL',

                'name' => 'Galii Kaapitaalaa',

                'start_code' => 1790,

                'end_code' => 1799,

                'description' =>
                    'Galii qabeenya kaapitaalaa irraa argamu',

                'sort_order' => 6,

                'is_active' => true,

                'created_at' => now(),

                'updated_at' => now(),
            ],


        ]);

    }
}