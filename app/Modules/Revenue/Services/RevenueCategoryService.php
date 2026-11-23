<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCategory;
use App\Models\RevenueCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

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

            foreach ($codes as $codeData) {

                $this->assertCodeAvailable(
                    $codeData['code']
                );

                $category->codes()->create([

                    'code' => $codeData['code'],

                    'name' => $codeData['name'],

                    'description' =>
                        $codeData['description'] ?? null,

                    'is_active' =>
                        $codeData['is_active'] ?? true,

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

            /*
            |--------------------------------------------------------------------------
            | Lock Category
            |--------------------------------------------------------------------------
            */

            $category = RevenueCategory::query()
                ->lockForUpdate()
                ->findOrFail($category->id);

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
            | Synchronize Revenue Codes
            |--------------------------------------------------------------------------
            */

            if ($codes !== null) {

                $keepIds = [];

                foreach ($codes as $codeData) {

                    $codeValue = trim(
                        (string) $codeData['code']
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Existing Revenue Code
                    |--------------------------------------------------------------------------
                    */

                    if (!empty($codeData['id'])) {

                        $existingCode = $category
                            ->codes()
                            ->withTrashed()
                            ->lockForUpdate()
                            ->findOrFail($codeData['id']);

                        $codeChanged = $existingCode->code !== $codeValue;

                        if ($codeChanged) {

                            $this->assertCodeAvailable(
                                $codeValue,
                                $existingCode->id
                            );

                        } elseif ($existingCode->trashed()) {

                            /*
                             * Before restoring, ensure no other active
                             * record has claimed this code.
                             */

                            $this->assertCodeAvailable(
                                $codeValue,
                                $existingCode->id
                            );

                        }

                        if ($existingCode->trashed()) {
                            $existingCode->restore();
                        }

                        $existingCode->update([

                            'code' => $codeValue,

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

                    $existingCode = $category
                        ->codes()
                        ->withTrashed()
                        ->where('code', $codeValue)
                        ->lockForUpdate()
                        ->first();

                    if ($existingCode) {

                        if ($existingCode->trashed()) {

                            $this->assertCodeAvailable(
                                $codeValue,
                                $existingCode->id
                            );

                            $existingCode->restore();

                        }

                        $existingCode->update([

                            'name' => $codeData['name'],

                            'description' =>
                                $codeData['description'] ?? null,

                            'is_active' =>
                                $codeData['is_active'] ?? true,

                        ]);

                        $newCode = $existingCode;

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Create New Revenue Code
                        |--------------------------------------------------------------------------
                        */

                        $this->assertCodeAvailable($codeValue);

                        $newCode = $category
                            ->codes()
                            ->create([

                                'code' => $codeValue,

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

        }, 3);
    }

    /**
     * Delete category and its active revenue codes.
     */
    public function delete(
        RevenueCategory $category
    ): bool {

        return DB::transaction(function () use ($category) {

            $category = RevenueCategory::query()
                ->lockForUpdate()
                ->findOrFail($category->id);

            /*
             * Soft delete active revenue codes first.
             */

            $category->codes()->delete();

            /*
             * Soft delete category.
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
     * Check whether an active revenue code exists globally.
     *
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

    /**
     * --------------------------------------------------------------------------
     * Ensure Revenue Code Is Available
     * --------------------------------------------------------------------------
     *
     * Active revenue codes must be globally unique.
     *
     * Soft-deleted records are ignored by RevenueCode's
     * SoftDeletes global scope.
     *
     * When updating or restoring an existing record,
     * its own ID is excluded.
     */
    private function assertCodeAvailable(
        string $code,
        ?string $ignoreId = null
    ): void {

        $exists = RevenueCode::query()

            ->where('code', $code)

            ->when(
                $ignoreId !== null,
                fn ($query) => $query->where(
                    'id',
                    '!=',
                    $ignoreId
                )
            )

            ->exists();

        if ($exists) {

            throw ValidationException::withMessages([

                'codes' => [
                    "Revenue code {$code} already exists.",
                ],

            ]);

        }
    }
}
