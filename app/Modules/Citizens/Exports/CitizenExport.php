<?php

namespace App\Modules\Citizens\Exports;

use App\Models\Citizen;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;


class CitizenExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize
{

    private array $filters;


    public function __construct(
        array $filters = []
    ) {

        $this->filters = $filters;

    }



    /**
     * Database query.
     */
    public function query()
    {

        return Citizen::query()

            ->with([
                'administrativeUnit',
            ])



            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            ->when(
                isset($this->filters['search']),
                function ($query) {

                    $search =
                        $this->filters['search'];


                    $query->where(function ($q) use ($search) {

                        $q->where(
                            'full_name',
                            'ILIKE',
                            "%{$search}%"
                        )


                        ->orWhere(
                            'national_id',
                            'ILIKE',
                            "%{$search}%"
                        )


                        ->orWhere(
                            'phone',
                            'ILIKE',
                            "%{$search}%"
                        );

                    });

                }
            )



            /*
            |--------------------------------------------------------------------------
            | Source Filter
            |--------------------------------------------------------------------------
            */

            ->when(
                isset($this->filters['source']),
                fn($query) =>
                    $query->where(
                        'source',
                        $this->filters['source']
                    )
            )



            /*
            |--------------------------------------------------------------------------
            | Gender Filter
            |--------------------------------------------------------------------------
            */

            ->when(
                isset($this->filters['gender']),
                fn($query) =>
                    $query->where(
                        'gender',
                        $this->filters['gender']
                    )
            )



            /*
            |--------------------------------------------------------------------------
            | Administrative Unit Filter
            |--------------------------------------------------------------------------
            */

            ->when(
                isset(
                    $this->filters['administrative_unit_id']
                ),

                fn($query) =>
                    $query->where(
                        'administrative_unit_id',
                        $this->filters['administrative_unit_id']
                    )
            )



            /*
            |--------------------------------------------------------------------------
            | Status Filter
            |--------------------------------------------------------------------------
            */

            ->when(
                isset($this->filters['is_active']),

                fn($query) =>
                    $query->where(
                        'is_active',
                        $this->filters['is_active']
                    )
            )


            ->latest();

    }





    /**
     * Excel column headers.
     */
    public function headings(): array
    {

        return [

            'Citizen UID',

            'Full Name',

            'National ID',

            'Phone',

            'Gender',

            'Birth Date',

            'Address',

            'Source',

            'Status',

            'Registered Date',

        ];

    }





    /**
     * Map database row to Excel row.
     */
    public function map($citizen): array
    {

        return [

            $citizen->citizen_uid,

            $citizen->full_name,

            $citizen->national_id,

            $citizen->phone,

            $citizen->gender,

            $citizen->date_of_birth,

            $citizen->address,

            $citizen->source,

            $citizen->is_active
                ? 'Active'
                : 'Inactive',

            optional(
                $citizen->registered_at
            )
            ->format('Y-m-d'),

        ];

    }


}