<?php

namespace App\Modules\Revenue\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\MeasurementUnit;

class MeasurementUnitService
{
    /**
     * Get all measurement units.
     */
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = MeasurementUnit::query()
            ->withCount('baseFields');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['search'])) {

            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {

                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('is_active', $filters)) {

            $query->where(
                'is_active',
                filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $allowedSorts = [

            'code',
            'name',
            'symbol',
            'sort_order',
            'created_at',
        ];

        $sortBy = $filters['sort_by'] ?? 'sort_order';

        if (!in_array($sortBy, $allowedSorts)) {

            $sortBy = 'sort_order';
        }

        $sortDirection = strtolower(
            $filters['sort_direction'] ?? 'asc'
        );

        if (!in_array($sortDirection, ['asc', 'desc'])) {

            $sortDirection = 'asc';
        }

        $query->orderBy($sortBy, $sortDirection);

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) ($filters['per_page'] ?? 15);

        return $query->paginate($perPage);
    }

    /**
     * Find measurement unit by id.
     */
    public function findById(string $id): MeasurementUnit
    {
        return MeasurementUnit::withCount('baseFields')
            ->findOrFail($id);
    }

    /**
     * Create a new measurement unit.
     */
    public function create(array $data): MeasurementUnit
    {
        DB::beginTransaction();

        try {

            $measurementUnit = MeasurementUnit::create([

                'code' => $data['code'],

                'name' => $data['name'],

                'symbol' => $data['symbol'] ?? null,

                'description' => $data['description'] ?? null,

                'is_active' => $data['is_active'] ?? true,

                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            DB::commit();

            Log::info('Measurement unit created successfully.', [

                'measurement_unit_id' => $measurementUnit->id,

                'code' => $measurementUnit->code,
            ]);

            return $measurementUnit;
        } catch (Exception $exception) {

            DB::rollBack();

            Log::error('Failed to create measurement unit.', [

                'message' => $exception->getMessage(),

                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Update an existing measurement unit.
     */
    public function update(
        string $id,
        array $data
    ): MeasurementUnit {

        DB::beginTransaction();

        try {

            $measurementUnit = MeasurementUnit::findOrFail($id);

            $measurementUnit->update([

                'code' => $data['code'],

                'name' => $data['name'],

                'symbol' => $data['symbol'] ?? null,

                'description' => $data['description'] ?? null,

                'is_active' => $data['is_active'] ?? $measurementUnit->is_active,

                'sort_order' => $data['sort_order'] ?? $measurementUnit->sort_order,
            ]);

            DB::commit();

            Log::info('Measurement unit updated successfully.', [

                'measurement_unit_id' => $measurementUnit->id,

                'code' => $measurementUnit->code,
            ]);

            return $measurementUnit->fresh()->loadCount('baseFields');

        } catch (Exception $exception) {

            DB::rollBack();

            Log::error('Failed to update measurement unit.', [

                'measurement_unit_id' => $id,

                'message' => $exception->getMessage(),

                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }
    
    /**
     * Soft delete measurement unit.
     */
    public function delete(string $id): bool
    {
        DB::beginTransaction();

        try {

            $measurementUnit = MeasurementUnit::findOrFail($id);

            $result = $measurementUnit->delete();

            DB::commit();

            Log::info('Measurement unit deleted successfully.', [

                'measurement_unit_id' => $id,

                'code' => $measurementUnit->code,
            ]);

            return $result;

        } catch (Exception $exception) {

            DB::rollBack();

            Log::error('Failed to delete measurement unit.', [

                'measurement_unit_id' => $id,

                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }


    /**
     * Restore deleted measurement unit.
     */
    public function restore(string $id): MeasurementUnit
    {
        DB::beginTransaction();

        try {

            $measurementUnit = MeasurementUnit::withTrashed()
                ->findOrFail($id);


            $measurementUnit->restore();


            DB::commit();


            Log::info('Measurement unit restored successfully.', [

                'measurement_unit_id' => $id,

                'code' => $measurementUnit->code,
            ]);


            return $measurementUnit
                ->fresh()
                ->loadCount('baseFields');


        } catch (Exception $exception) {


            DB::rollBack();


            Log::error('Failed to restore measurement unit.', [

                'measurement_unit_id' => $id,

                'message' => $exception->getMessage(),
            ]);


            throw $exception;
        }
    }



    /**
     * Change active status.
     */
    public function changeStatus(
        string $id,
        bool $status
    ): MeasurementUnit {


        DB::beginTransaction();


        try {


            $measurementUnit = MeasurementUnit::findOrFail($id);


            $measurementUnit->update([

                'is_active' => $status,

            ]);


            DB::commit();



            Log::info('Measurement unit status changed.', [

                'measurement_unit_id' => $id,

                'status' => $status,
            ]);



            return $measurementUnit->fresh();



        } catch (Exception $exception) {


            DB::rollBack();



            Log::error('Failed to change measurement unit status.', [

                'measurement_unit_id' => $id,

                'message' => $exception->getMessage(),
            ]);



            throw $exception;

        }

    }

}
    


