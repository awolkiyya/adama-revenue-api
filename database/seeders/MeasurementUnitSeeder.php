<?php

namespace Database\Seeders;

use App\Models\MeasurementUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeasurementUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            /*
            |--------------------------------------------------------------------------
            | General Units
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'PCS',
                'name' => 'Piece',
                'symbol' => 'pcs',
                'description' => 'Individual countable item',
            ],
            [
                'code' => 'M2',
                'name' => 'Square Meter',
                'symbol' => 'm²',
                'description' => 'Used for land and building area',
            ],
            [
                'code' => 'M3',
                'name' => 'Cubic Meter',
                'symbol' => 'm³',
                'description' => 'Volume measurement',
            ],
            [
                'code' => 'KM',
                'name' => 'Kilometer',
                'symbol' => 'km',
                'description' => 'Distance measurement',
            ],
            [
                'code' => 'KG',
                'name' => 'Kilogram',
                'symbol' => 'kg',
                'description' => 'Weight measurement',
            ],
            [
                'code' => 'L',
                'name' => 'Liter',
                'symbol' => 'L',
                'description' => 'Liquid measurement',
            ],

            /*
            |--------------------------------------------------------------------------
            | Time Units
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'DAY',
                'name' => 'Day',
                'symbol' => 'day',
                'description' => 'Daily calculation period',
            ],
            [
                'code' => 'MONTH',
                'name' => 'Month',
                'symbol' => 'month',
                'description' => 'Monthly calculation period',
            ],
            [
                'code' => 'YEAR',
                'name' => 'Year',
                'symbol' => 'year',
                'description' => 'Yearly calculation period',
            ],

            /*
            |--------------------------------------------------------------------------
            | Municipal Revenue Units
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'PERSON',
                'name' => 'Person',
                'symbol' => 'person',
                'description' => 'Number of people or employees',
            ],
            [
                'code' => 'VEHICLE',
                'name' => 'Vehicle',
                'symbol' => 'vehicle',
                'description' => 'Vehicle count',
            ],
            [
                'code' => 'LICENSE',
                'name' => 'License',
                'symbol' => 'license',
                'description' => 'License or permit count',
            ],

            /*
            |--------------------------------------------------------------------------
            | Percentage
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'PERCENT',
                'name' => 'Percentage',
                'symbol' => '%',
                'description' => 'Percentage calculation',
            ],
        ];

        $created = 0;
        $existing = 0;

        DB::transaction(function () use (
            $units,
            &$created,
            &$existing
        ) {
            foreach ($units as $index => $unit) {

                $measurementUnit = MeasurementUnit::query()
                    ->firstOrNew([
                        'code' => $unit['code'],
                    ]);

                if ($measurementUnit->exists) {
                    $existing++;
                    continue;
                }

                $measurementUnit->id = (string) Str::uuid();
                $measurementUnit->name = $unit['name'];
                $measurementUnit->symbol = $unit['symbol'];
                $measurementUnit->description = $unit['description'];
                $measurementUnit->is_active = true;
                $measurementUnit->sort_order = $index + 1;

                $measurementUnit->save();

                $created++;
            }
        });

        $this->command?->info(
            "Measurement units seeding completed. Created: {$created}, Existing: {$existing}."
        );
    }
}
