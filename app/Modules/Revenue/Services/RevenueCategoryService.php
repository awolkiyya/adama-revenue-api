<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCategory;
use App\Models\RevenueCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RevenueCategoryService
{
    /**
     * Get all revenue categories.
     */
    public function all(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {

        return RevenueCategory::query()
            ->withCount('codes')

            ->when(
                array_key_exists('revenue_domain', $filters),
                fn ($query) => $query->where(
                    'revenue_domain',
                    $filters['revenue_domain']
                )
            )

            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where(
                    'is_active',
                    $filters['is_active']
                )
            )

            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Revenue category summary.
     *
     * Used for dashboard cards.
     */
    public function summary(array $filters = []): array
    {
        $query = RevenueCategory::query();

        $query->when(
            array_key_exists('revenue_domain', $filters),
            fn ($query) => $query->where(
                'revenue_domain',
                $filters['revenue_domain']
            )
        );

        $codesQuery = RevenueCode::query()
            ->whereHas('category', function ($query) use ($filters) {

                $query->when(
                    array_key_exists('revenue_domain', $filters),
                    fn ($query) => $query->where(
                        'revenue_domain',
                        $filters['revenue_domain']
                    )
                );

            });

        return [

            'total' => (clone $query)->count(),

            'active' => (clone $query)
                ->where('is_active', true)
                ->count(),

            'inactive' => (clone $query)
                ->where('is_active', false)
                ->count(),

            'totalCodes' => (clone $codesQuery)->count(),

        ];
    }

    /**
     * Find category with revenue codes.
     */
    public function find(
        RevenueCategory $category
    ): RevenueCategory {

        return $category->load('codes');
    }

    /**
     * Create category with revenue codes.
     *
     * Soft-deleted codes from another category are not
     * automatically moved because they may have historical
     * financial relationships.
     */
    public function create(array $data): RevenueCategory
    {
        return DB::transaction(function () use ($data) {

            $codes = $data['codes'] ?? [];

            unset($data['codes']);

            /*
            |--------------------------------------------------------------------------
            | Create Category
            |--------------------------------------------------------------------------
            */

            $category = RevenueCategory::create($data);

            /*
            |--------------------------------------------------------------------------
            | Create Revenue Codes
            |--------------------------------------------------------------------------
            */

            foreach ($codes as $code) {

                $category->codes()->create([

                    'code' => $code['code'],

                    'name' => $code['name'],

                    'description' => $code['description'] ?? null,

                    'is_active' => $code['is_active'] ?? true,

                ]);

            }

            return $category->load('codes');

        });
    }

    /**
     * Update category with revenue codes.
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

            /*
            |--------------------------------------------------------------------------
            | Update Category
            |--------------------------------------------------------------------------
            */

            $category->update($data);

            /*
            |--------------------------------------------------------------------------
            | Sync Revenue Codes
            |--------------------------------------------------------------------------
            */

            if ($codes !== null) {

                $keepIds = [];

                foreach ($codes as $codeData) {

                    /*
                    |--------------------------------------------------------------------------
                    | Update Existing Revenue Code
                    |--------------------------------------------------------------------------
                    */

                    if (!empty($codeData['id'])) {

                        $existingCode = $category
                            ->codes()
                            ->withTrashed()
                            ->findOrFail($codeData['id']);

                        /*
                        | Restore if previously soft deleted.
                        */

                        if ($existingCode->trashed()) {
                            $existingCode->restore();
                        }

                        $existingCode->update([

                            'code' => $codeData['code'],

                            'name' => $codeData['name'],

                            'description' =>
                                $codeData['description'] ?? null,

                            'is_active' =>
                                $codeData['is_active'] ?? true,

                        ]);

                        $keepIds[] = $existingCode->id;

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Find Existing Code Within Same Category
                    |--------------------------------------------------------------------------
                    |
                    | Restore the original record if it was deleted.
                    | This preserves historical relationships.
                    |
                    */

                    $existingDeletedCode = $category
                        ->codes()
                        ->withTrashed()
                        ->where('code', $codeData['code'])
                        ->first();

                    if ($existingDeletedCode) {

                        if ($existingDeletedCode->trashed()) {
                            $existingDeletedCode->restore();
                        }

                        $existingDeletedCode->update([

                            'name' => $codeData['name'],

                            'description' =>
                                $codeData['description'] ?? null,

                            'is_active' =>
                                $codeData['is_active'] ?? true,

                        ]);

                        $newCode = $existingDeletedCode;

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Create New Revenue Code
                        |--------------------------------------------------------------------------
                        */

                        $newCode = $category
                            ->codes()
                            ->create([

                                'code' => $codeData['code'],

                                'name' => $codeData['name'],

                                'description' =>
                                    $codeData['description'] ?? null,

                                'is_active' =>
                                    $codeData['is_active'] ?? true,

                            ]);

                    }

                    $keepIds[] = $newCode->id;

                }

                /*
                |--------------------------------------------------------------------------
                | Soft Delete Removed Codes
                |--------------------------------------------------------------------------
                |
                | Codes removed from the submitted list are soft deleted.
                | Historical records remain in the database.
                |
                */

                $category
                    ->codes()
                    ->whereNotIn('id', $keepIds)
                    ->delete();

            }

            /*
            |--------------------------------------------------------------------------
            | Return Updated Category
            |--------------------------------------------------------------------------
            */

            return $category
                ->fresh()
                ->load('codes');

        });
    }

    /**
     * Delete category and its active revenue codes.
     */
    public function delete(
        RevenueCategory $category
    ): bool {

        return DB::transaction(function () use ($category) {

            /*
            | Soft delete active revenue codes first.
            */

            $category->codes()->delete();

            /*
            | Soft delete category.
            */

            return (bool) $category->delete();

        });
    }

    /**
     * Activate category.
     */
    public function activate(
        RevenueCategory $category
    ): bool {

        return $category->update([
            'is_active' => true,
        ]);
    }

    /**
     * Deactivate category.
     */
    public function deactivate(
        RevenueCategory $category
    ): bool {

        return $category->update([
            'is_active' => false,
        ]);
    }

    /**
     * Check whether an active revenue code exists.
     *
     * Checks globally, not just within one category.
     * Soft-deleted revenue codes are excluded.
     */
    public function codeExists(
        RevenueCategory $category,
        string $code
    ): bool {

        return RevenueCode::query()
            ->where('code', $code)
            ->exists();
    }
}
