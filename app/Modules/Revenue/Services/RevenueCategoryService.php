<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Throwable;


class RevenueCategoryService
{


    /**
     * Get all revenue categories
     */
    public function all(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {


        return RevenueCategory::query()

            /**
             * Count revenue codes
             */
            ->withCount('codes')


            /**
             * Filter revenue domain
             */
            ->when(
                array_key_exists('revenue_domain', $filters),
                function ($query) use ($filters) {

                    $query->where(
                        'revenue_domain',
                        $filters['revenue_domain']
                    );

                }
            )


            /**
             * Filter status
             */
            ->when(
                array_key_exists('is_active', $filters),
                function ($query) use ($filters) {

                    $query->where(
                        'is_active',
                        $filters['is_active']
                    );

                }
            )


            /**
             * Sorting
             */
            ->orderBy('sort_order')

            ->orderBy('name')


            ->paginate($perPage);

    }

    /**
 * Revenue category summary
 *
 * Used for dashboard cards
 */
public function summary(
    array $filters = []
): array {


    $query = RevenueCategory::query();



    /**
     * Apply same filters
     */
    $query->when(
        array_key_exists('revenue_domain', $filters),
        function ($query) use ($filters) {

            $query->where(
                'revenue_domain',
                $filters['revenue_domain']
            );

        }
    );



    return [

        /**
         * Total categories
         */
        'total' => (clone $query)->count(),



        /**
         * Active categories
         */
        'active' => (clone $query)
            ->where('is_active', true)
            ->count(),



        /**
         * Inactive categories
         */
        'inactive' => (clone $query)
            ->where('is_active', false)
            ->count(),



        /**
         * Total revenue codes
         */
        'totalCodes' => RevenueCategory::query()
            ->withCount('codes')
            ->get()
            ->sum('codes_count'),

    ];

}


    /**
     * Find category
     */
    public function find(
        RevenueCategory $category
    ): RevenueCategory {


        return $category->load('codes');

    }

    /**
     * Create category with codes
     */
    public function create(
        array $data
    ): RevenueCategory {


        return DB::transaction(function () use ($data) {


            $codes = $data['codes'] ?? [];


            unset($data['codes']);



            /**
             * Create category
             */
            $category = RevenueCategory::create(
                $data
            );



            /**
             * Create codes
             */
            if (!empty($codes)) {


                foreach ($codes as $code) {


                    $category->codes()->create([

                        'code' => $code['code'],

                        'name' => $code['name'],

                        'description' =>
                            $code['description'] ?? null,

                        'is_active' =>
                            $code['is_active'] ?? true,

                    ]);


                }

            }



            return $category->load('codes');


        });


    }





/**
 * Update category with codes
 */
public function update(
    RevenueCategory $category,
    array $data
): RevenueCategory {


    return DB::transaction(function () use (
        $category,
        $data
    ) {


        $codes = $data['codes'] ?? null;


        unset($data['codes']);



        /**
         * --------------------------------------------------------------
         * Update Category
         * --------------------------------------------------------------
         */
        $category->update($data);




        /**
         * --------------------------------------------------------------
         * Sync Revenue Codes
         * --------------------------------------------------------------
         */
        if ($codes !== null) {


            $keepIds = collect();



            foreach ($codes as $codeData) {



                /**
                 * ------------------------------------------------------
                 * Update Existing Code
                 * ------------------------------------------------------
                 */
                if (!empty($codeData['id'])) {



                    $existingCode = $category
                        ->codes()
                        ->withTrashed()
                        ->where(
                            'id',
                            $codeData['id']
                        )
                        ->first();



                    if ($existingCode) {



                        /**
                         * Restore if soft deleted
                         */
                        if ($existingCode->trashed()) {

                            $existingCode->restore();

                        }




                        $existingCode->update([

                            'code' =>
                                $codeData['code'],

                            'name' =>
                                $codeData['name'],

                            'description' =>
                                $codeData['description'] ?? null,

                            'is_active' =>
                                $codeData['is_active'] ?? true,

                        ]);



                        $keepIds->push(
                            $existingCode->id
                        );



                        continue;

                    }

                }




                /**
                 * ------------------------------------------------------
                 * Create New Code OR Restore Deleted Code
                 * ------------------------------------------------------
                 */


                $existingDeletedCode = $category
                    ->codes()
                    ->withTrashed()
                    ->where(
                        'code',
                        $codeData['code']
                    )
                    ->first();




                if ($existingDeletedCode) {



                    /**
                     * Restore soft deleted record
                     */
                    if ($existingDeletedCode->trashed()) {

                        $existingDeletedCode->restore();

                    }



                    $existingDeletedCode->update([

                        'name' =>
                            $codeData['name'],

                        'description' =>
                            $codeData['description'] ?? null,

                        'is_active' =>
                            $codeData['is_active'] ?? true,

                    ]);



                    $newCode = $existingDeletedCode;



                } else {



                    /**
                     * Completely new revenue code
                     */
                    $newCode = $category
                        ->codes()
                        ->create([

                            'code' =>
                                $codeData['code'],

                            'name' =>
                                $codeData['name'],

                            'description' =>
                                $codeData['description'] ?? null,

                            'is_active' =>
                                $codeData['is_active'] ?? true,

                        ]);

                }




                $keepIds->push(
                    $newCode->id
                );


            }





            /**
             * ----------------------------------------------------------
             * Soft Delete Removed Codes
             * ----------------------------------------------------------
             *
             * Any code removed from the UI will be soft deleted.
             *
             */
            $category
                ->codes()
                ->whereNotIn(
                    'id',
                    $keepIds->toArray()
                )
                ->delete();


        }




        /**
         * --------------------------------------------------------------
         * Return Updated Category
         * --------------------------------------------------------------
         */
        return $category
            ->fresh()
            ->load([
                'codes'
            ]);


    });

}







    /**
     * Delete category
     */
    public function delete(
        RevenueCategory $category
    ): bool {


        return DB::transaction(function () use ($category) {


            /**
             * Remove related codes first
             */
            $category->codes()
                ->delete();



            /**
             * Soft delete category
             */
            return $category->delete();


        });


    }






    /**
     * Activate category
     */
    public function activate(
        RevenueCategory $category
    ): bool {


        return $category->update([

            'is_active' => true

        ]);

    }







    /**
     * Deactivate category
     */
    public function deactivate(
        RevenueCategory $category
    ): bool {


        return $category->update([

            'is_active' => false

        ]);

    }






    /**
     * Check duplicate revenue code
     */
    public function codeExists(
        RevenueCategory $category,
        string $code
    ): bool {


        return $category->codes()

            ->where(
                'code',
                $code
            )

            ->exists();

    }


}