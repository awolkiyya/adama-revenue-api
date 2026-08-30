<?php

namespace Database\Seeders;

use App\Models\BaseField;
use App\Models\BaseFieldOption;
use App\Models\MeasurementUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BaseFieldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Measurement Units
        |--------------------------------------------------------------------------
        |
        | Load all measurement units using their unique code.
        |
        */

        $units = MeasurementUnit::pluck('id', 'code');


        /*
        |--------------------------------------------------------------------------
        | Base Fields
        |--------------------------------------------------------------------------
        |
        | These are reusable field definitions.
        |
        | Supported data types:
        |
        | - NUMBER
        | - DECIMAL
        | - TEXT
        | - BOOLEAN
        | - DATE
        | - SELECT
        | - RADIO
        | - CHECKBOX
        | - FILE
        |
        | SELECT / RADIO / CHECKBOX fields can additionally
        | have records in base_field_options.
        |
        */

        $fields = [

            /*
            |--------------------------------------------------------------------------
            | Numeric Fields
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'LAND_AREA',
                'name' => 'Land Area',
                'description' => 'Land area used for tariff calculation.',
                'measurement_unit' => 'M2',
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'BUILDING_AREA',
                'name' => 'Building Area',
                'description' => 'Building floor area used for assessment.',
                'measurement_unit' => 'M2',
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'WATER_USAGE',
                'name' => 'Water Usage',
                'description' => 'Water consumption quantity.',
                'measurement_unit' => 'M3',
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'QUANTITY',
                'name' => 'Quantity',
                'description' => 'Generic quantity measurement.',
                'measurement_unit' => 'PCS',
                'data_type' => 'NUMBER',
            ],

            [
                'code' => 'WEIGHT',
                'name' => 'Weight',
                'description' => 'Weight value.',
                'measurement_unit' => 'KG',
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'VOLUME',
                'name' => 'Volume',
                'description' => 'Volume measurement.',
                'measurement_unit' => 'M3',
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'BUSINESS_CAPITAL',
                'name' => 'Business Capital',
                'description' => 'Registered business capital value.',
                'measurement_unit' => null,
                'data_type' => 'DECIMAL',
            ],

            [
                'code' => 'EMPLOYEE_COUNT',
                'name' => 'Employee Count',
                'description' => 'Number of employees.',
                'measurement_unit' => 'PERSON',
                'data_type' => 'NUMBER',
            ],

            [
                'code' => 'VEHICLE_COUNT',
                'name' => 'Vehicle Count',
                'description' => 'Number of vehicles.',
                'measurement_unit' => 'VEHICLE',
                'data_type' => 'NUMBER',
            ],

            [
                'code' => 'ROOM_COUNT',
                'name' => 'Room Count',
                'description' => 'Number of rooms.',
                'measurement_unit' => 'PCS',
                'data_type' => 'NUMBER',
            ],


            /*
            |--------------------------------------------------------------------------
            | Selectable Fields
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'PROPERTY_TYPE',
                'name' => 'Property Type',
                'description' => 'Type of property being assessed.',
                'measurement_unit' => null,
                'data_type' => 'SELECT',

                'options' => [
                    [
                        'value' => 'RESIDENTIAL',
                        'label' => 'Residential',
                    ],
                    [
                        'value' => 'COMMERCIAL',
                        'label' => 'Commercial',
                    ],
                    [
                        'value' => 'INDUSTRIAL',
                        'label' => 'Industrial',
                    ],
                ],
            ],

            [
                'code' => 'OWNERSHIP_TYPE',
                'name' => 'Ownership Type',
                'description' => 'Property ownership type.',
                'measurement_unit' => null,
                'data_type' => 'RADIO',

                'options' => [
                    [
                        'value' => 'PRIVATE',
                        'label' => 'Private',
                    ],
                    [
                        'value' => 'GOVERNMENT',
                        'label' => 'Government',
                    ],
                    [
                        'value' => 'LEASE',
                        'label' => 'Lease',
                    ],
                ],
            ],

            [
                'code' => 'PROPERTY_FACILITIES',
                'name' => 'Property Facilities',
                'description' => 'Facilities available on the property.',
                'measurement_unit' => null,
                'data_type' => 'CHECKBOX',

                'options' => [
                    [
                        'value' => 'WATER',
                        'label' => 'Water',
                    ],
                    [
                        'value' => 'ELECTRICITY',
                        'label' => 'Electricity',
                    ],
                    [
                        'value' => 'PARKING',
                        'label' => 'Parking',
                    ],
                    [
                        'value' => 'SEWERAGE',
                        'label' => 'Sewerage',
                    ],
                ],
            ],


            /*
            |--------------------------------------------------------------------------
            | Boolean Field
            |--------------------------------------------------------------------------
            */

            [
                'code' => 'HAS_BUILDING',
                'name' => 'Has Building',
                'description' => 'Whether the property contains a building.',
                'measurement_unit' => null,
                'data_type' => 'BOOLEAN',
            ],


            /*
            |--------------------------------------------------------------------------
            | File Field
            |--------------------------------------------------------------------------
            |
            | FILE fields do not have options.
            |
            */

            [
                'code' => 'PROPERTY_DOCUMENT',
                'name' => 'Property Document',
                'description' => 'Property ownership or supporting document.',
                'measurement_unit' => null,
                'data_type' => 'FILE',
            ],
        ];


        /*
        |--------------------------------------------------------------------------
        | Create / Update Base Fields
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use ($fields, $units): void {

            foreach ($fields as $index => $field) {

                /*
                |--------------------------------------------------------------------------
                | Resolve Measurement Unit
                |--------------------------------------------------------------------------
                |
                | If a measurement unit is specified, it must exist.
                | We intentionally fail instead of silently storing NULL.
                |
                */

                $measurementUnitId = null;

                if (!empty($field['measurement_unit'])) {

                    $measurementUnitCode = $field['measurement_unit'];

                    if (!isset($units[$measurementUnitCode])) {
                        throw new RuntimeException(
                            "Measurement unit '{$measurementUnitCode}' "
                            . "not found for base field '{$field['code']}'."
                        );
                    }

                    $measurementUnitId = $units[$measurementUnitCode];
                }


                /*
                |--------------------------------------------------------------------------
                | Create / Update Base Field
                |--------------------------------------------------------------------------
                |
                | The UUID is generated only when the record is created.
                | Existing records retain their original UUID.
                |
                */

                $baseField = BaseField::firstOrNew([
                    'code' => $field['code'],
                ]);

                $baseField->name = $field['name'];

                $baseField->description = $field['description'];

                $baseField->measurement_unit_id = $measurementUnitId;

                $baseField->data_type = $field['data_type'];

                $baseField->is_active = true;

                $baseField->sort_order = $index + 1;

                if (!$baseField->exists) {
                    $baseField->id = (string) Str::uuid();
                }

                $baseField->save();


                /*
                |--------------------------------------------------------------------------
                | Seed Field Options
                |--------------------------------------------------------------------------
                |
                | Only SELECT / RADIO / CHECKBOX fields should have
                | selectable options.
                |
                */

                $selectableTypes = [
                    'SELECT',
                    'RADIO',
                    'CHECKBOX',
                ];

                if (
                    isset($field['options']) &&
                    in_array(
                        $field['data_type'],
                        $selectableTypes,
                        true
                    )
                ) {

                    foreach ($field['options'] as $optionIndex => $option) {

                        $baseFieldOption = BaseFieldOption::firstOrNew([
                            'base_field_id' => $baseField->id,
                            'value' => $option['value'],
                        ]);

                        $baseFieldOption->label = $option['label'];

                        $baseFieldOption->description =
                            $option['description'] ?? null;

                        $baseFieldOption->sort_order =
                            $optionIndex + 1;

                        $baseFieldOption->is_default =
                            $option['is_default'] ?? false;

                        $baseFieldOption->is_active = true;

                        if (!$baseFieldOption->exists) {
                            $baseFieldOption->id = (string) Str::uuid();
                        }

                        $baseFieldOption->save();
                    }
                }
            }
        });
    }
}