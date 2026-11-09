<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevenueCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            /*
            |--------------------------------------------------------------------------
            | 1701 - 1719
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'TAX',
                'name' => 'Galii Taaksii Mana Gopheessaa',
                'start_code' => 1701,
                'end_code' => 1719,
                'description' => 'Galii gibira mana jireenyaa fi mana hojii irraa argamu',
                'sort_order' => 1,
            ],

            /*
            |--------------------------------------------------------------------------
            | 1720 - 1729
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'RENT',
                'name' => 'Galii Kiraarraa Argamu',
                'start_code' => 1720,
                'end_code' => 1729,
                'description' => 'Galii kiraa lafa, mana mootummaa fi qabeenya mootummaa irraa argamu',
                'sort_order' => 2,
            ],

            /*
            |--------------------------------------------------------------------------
            | 1731 - 1734
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'INVESTMENT',
                'name' => 'Galii Investimantii',
                'start_code' => 1731,
                'end_code' => 1734,
                'description' => 'Galii invastimantii fi bu’aa qabeenya mootummaa irraa argamu',
                'sort_order' => 3,
            ],

            /*
            |--------------------------------------------------------------------------
            | 1740 - 1749
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'SERVICE',
                'name' => 'Kaffaltii Tajaajilaa',
                'start_code' => 1740,
                'end_code' => 1749,
                'description' => 'Kaffaltii tajaajiloota mootummaa irraa argamu',
                'sort_order' => 4,
            ],

            /*
            |--------------------------------------------------------------------------
            | 1750 - 1789
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'SALE',
                'name' => 'Galii Gurgurtaa Tajaajilaa fi Meeshaawwanii',
                'start_code' => 1750,
                'end_code' => 1789,
                'description' => 'Galii gurgurtaa meeshaalee fi tajaajiloota mootummaa',
                'sort_order' => 5,
            ],

            /*
            |--------------------------------------------------------------------------
            | 1790 - 1799
            |--------------------------------------------------------------------------
            */
            [
                'revenue_domain' => 'CAPITAL',
                'name' => 'Galii Kaapitaalaa',
                'start_code' => 1790,
                'end_code' => 1799,
                'description' => 'Galii qabeenya kaapitaalaa irraa argamu',
                'sort_order' => 6,
            ],
        ];

        DB::transaction(function () use ($categories): void {
            foreach ($categories as $category) {
                $existing = DB::table('revenue_categories')
                    ->where('revenue_domain', $category['revenue_domain'])
                    ->where('start_code', $category['start_code'])
                    ->first();

                $now = now();

                $values = [
                    'revenue_domain' => $category['revenue_domain'],
                    'name' => $category['name'],
                    'start_code' => $category['start_code'],
                    'end_code' => $category['end_code'],
                    'description' => $category['description'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('revenue_categories')
                        ->where('id', $existing->id)
                        ->update($values);
                } else {
                    DB::table('revenue_categories')->insert([
                        'id' => (string) Str::uuid(),
                        ...$values,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        $this->command?->info(
            count($categories) . ' revenue categories seeded successfully.'
        );
    }
}
