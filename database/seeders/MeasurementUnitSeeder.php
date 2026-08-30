<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\MeasurementUnit;

class MeasurementUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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
                'description' => 'Individual countable item'
            ],

            [
                'code' => 'M2',
                'name' => 'Square Meter',
                'symbol' => 'm²',
                'description' => 'Used for land and building area'
            ],

            [
                'code' => 'M3',
                'name' => 'Cubic Meter',
                'symbol' => 'm³',
                'description' => 'Volume measurement'
            ],


            [
                'code' => 'KM',
                'name' => 'Kilometer',
                'symbol' => 'km',
                'description' => 'Distance measurement'
            ],


            [
                'code' => 'KG',
                'name' => 'Kilogram',
                'symbol' => 'kg',
                'description' => 'Weight measurement'
            ],


            [
                'code' => 'L',
                'name' => 'Liter',
                'symbol' => 'L',
                'description' => 'Liquid measurement'
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
                'description' => 'Daily calculation period'
            ],


            [
                'code' => 'MONTH',
                'name' => 'Month',
                'symbol' => 'month',
                'description' => 'Monthly calculation period'
            ],


            [
                'code' => 'YEAR',
                'name' => 'Year',
                'symbol' => 'year',
                'description' => 'Yearly calculation period'
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
                'description' => 'Number of people or employees'
            ],


            [
                'code' => 'VEHICLE',
                'name' => 'Vehicle',
                'symbol' => 'vehicle',
                'description' => 'Vehicle count'
            ],


            [
                'code' => 'LICENSE',
                'name' => 'License',
                'symbol' => 'license',
                'description' => 'License or permit count'
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
                'description' => 'Percentage calculation'
            ],

        ];



        foreach ($units as $index => $unit) {

            MeasurementUnit::updateOrCreate(

                [
                    'code' => $unit['code']
                ],

                [
                    'id' => Str::uuid(),

                    'name' => $unit['name'],

                    'symbol' => $unit['symbol'],

                    'description' => $unit['description'],

                    'is_active' => true,

                    'sort_order' => $index + 1,
                ]

            );

        }
    }
}