<?php

namespace App\Modules\Revenue\Services;

use App\Models\TariffVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TariffVersionService
{


    /**
     * Paginated tariff versions.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {

        $query = TariffVersion::query()
            ->withCount('tariffRules');



        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['search'])) {

            $search = $filters['search'];


            $query->where(function (Builder $q) use ($search) {

                $q->where(
                    'name',
                    'ILIKE',
                    "%{$search}%"
                )
                ->orWhere(
                    'description',
                    'ILIKE',
                    "%{$search}%"
                )
                ->orWhere(
                    'year',
                    'ILIKE',
                    "%{$search}%"
                );

            });

        }




        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        if (isset($filters['year'])) {

            $query->where(
                'year',
                $filters['year']
            );

        }


        if (isset($filters['is_active'])) {

            $query->where(
                'is_active',
                $filters['is_active']
            );

        }





        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $sortBy =
            $filters['sort_by'] ?? 'year';


        $sortDirection =
            $filters['sort_direction'] ?? 'desc';



        $allowedSorts = [

            'year',
            'version',
            'name',
            'effective_from',
            'created_at',

        ];



        if (in_array($sortBy,$allowedSorts)) {

            $query->orderBy(
                $sortBy,
                $sortDirection
            );

        }



        return $query->paginate(
            $filters['per_page'] ?? 20
        );

    }







    /**
     * Dashboard summary.
     *
     * Returns active tariff information.
     */
    public function summary(): array
    {

        $activeVersion =
            TariffVersion::query()
                ->where(
                    'is_active',
                    true
                )
                ->first();



        if (!$activeVersion) {

            return [

                'message'=>null

            ];

        }



        return [

            'message' =>
                "{$activeVersion->year} is the currently active tariff — all new assessments use its pricing rules."

        ];

    }








    /**
     * Find tariff version.
     */
    public function find(string $id): TariffVersion
    {

        return TariffVersion::query()
            ->withCount('tariffRules')
            ->findOrFail($id);

    }










    /**
     * Create tariff version.
     *
     * Backend controls:
     * - version number
     * - first activation
     */
    public function create(array $data): TariffVersion
    {

        return DB::transaction(function () use ($data) {


            /*
            |--------------------------------------------------------------------------
            | Generate version automatically
            |--------------------------------------------------------------------------
            */

            $latestVersion =
                TariffVersion::where(
                    'year',
                    $data['year']
                )
                ->max('version');



            $data['version'] =
                ($latestVersion ?? 0) + 1;




            /*
            |--------------------------------------------------------------------------
            | Remove client control
            |--------------------------------------------------------------------------
            */

            unset($data['version']);



            /*
            |--------------------------------------------------------------------------
            | First tariff automatically active
            |--------------------------------------------------------------------------
            */

            $hasActive =
                TariffVersion::where(
                    'is_active',
                    true
                )
                ->exists();



            if (!$hasActive){

                $data['is_active']=true;

            }




            /*
            |--------------------------------------------------------------------------
            | If active remove previous active
            |--------------------------------------------------------------------------
            */

            if(
                ($data['is_active'] ?? false) === true
            ){

                TariffVersion::where(
                    'is_active',
                    true
                )
                ->update([

                    'is_active'=>false

                ]);

            }



            return TariffVersion::create($data);


        });

    }










    /**
     * Update tariff version.
     */
    public function update(
        string $id,
        array $data
    ): TariffVersion {


        return DB::transaction(function() use(
            $id,
            $data
        ){


            $tariffVersion =
                TariffVersion::findOrFail($id);




            /*
            |--------------------------------------------------------------------------
            | Version cannot change
            |--------------------------------------------------------------------------
            */

            unset($data['version']);





            /*
            |--------------------------------------------------------------------------
            | Activate requested tariff
            |--------------------------------------------------------------------------
            */

            if(
                isset($data['is_active'])
                &&
                $data['is_active'] === true
            ){

                TariffVersion::where(
                    'id',
                    '!=',
                    $id
                )
                ->where(
                    'is_active',
                    true
                )
                ->update([

                    'is_active'=>false

                ]);

            }




            $tariffVersion->update($data);



            return $tariffVersion->refresh();


        });


    }









    /**
     * Activate tariff version.
     */
    public function activate(
        string $id
    ): TariffVersion {


        return DB::transaction(function() use($id){



            $tariffVersion =
                TariffVersion::findOrFail($id);





            /*
            |--------------------------------------------------------------------------
            | Prevent future tariff activation
            |--------------------------------------------------------------------------
            */

            if(
                $tariffVersion->effective_from > now()
            ){

                throw ValidationException::withMessages([

                    'tariff' =>
                        'Cannot activate tariff before effective date.'

                ]);

            }






            TariffVersion::where(
                'is_active',
                true
            )
            ->where(
                'id',
                '!=',
                $id
            )
            ->update([

                'is_active'=>false

            ]);






            $tariffVersion->update([

                'is_active'=>true

            ]);




            return $tariffVersion->refresh();


        });


    }









    /**
     * Delete tariff version.
     */
    public function delete(string $id): bool
    {


        $tariffVersion =
            TariffVersion::findOrFail($id);




        if($tariffVersion->is_active){


            throw ValidationException::withMessages([

                'tariff'=>
                    'Active tariff version cannot be deleted.'

            ]);

        }



        return (bool)$tariffVersion->delete();


    }









    /**
     * Restore deleted tariff.
     */
    public function restore(string $id): bool
    {

        return (bool)
            TariffVersion::withTrashed()
                ->findOrFail($id)
                ->restore();

    }


}