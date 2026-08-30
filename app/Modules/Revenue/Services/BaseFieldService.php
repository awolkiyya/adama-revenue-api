<?php

namespace App\Modules\Revenue\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\BaseField;

class BaseFieldService
{
    /**
     * Relationships required for the complete BaseField representation.
     */
    private const RELATIONS = [
        'measurementUnit',
        'options',
    ];

    /**
     * Get all base fields.
     */
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = BaseField::query()
            ->with(self::RELATIONS);

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
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Measurement Unit Filter
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['measurement_unit_id'])) {
            $query->where(
                'measurement_unit_id',
                $filters['measurement_unit_id']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Data Type Filter
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['data_type'])) {
            $query->where(
                'data_type',
                $filters['data_type']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Active Status Filter
        |--------------------------------------------------------------------------
        */

        if (array_key_exists('is_active', $filters)) {
            $query->where(
                'is_active',
                filter_var(
                    $filters['is_active'],
                    FILTER_VALIDATE_BOOLEAN
                )
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
            'data_type',
            'sort_order',
            'created_at',
        ];

        $sortBy = $filters['sort_by'] ?? 'sort_order';

        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'sort_order';
        }

        $sortDirection = strtolower(
            $filters['sort_direction'] ?? 'asc'
        );

        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'asc';
        }

        $query->orderBy($sortBy, $sortDirection);

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) ($filters['per_page'] ?? 15);

        /*
        |--------------------------------------------------------------------------
        | Per Page Protection
        |--------------------------------------------------------------------------
        */

        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }

    /**
     * Find base field by ID.
     */
    public function findById(string $id): BaseField
    {
        return BaseField::query()
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    /**
     * Create a new base field.
     */
    public function create(array $data): BaseField
    {
        try {
            $baseField = DB::transaction(function () use ($data) {
                return BaseField::create([
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                    'data_type' => $data['data_type'],
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $data['sort_order'] ?? 0,
                ]);
            });

            Log::info('Base field created successfully.', [
                'base_field_id' => $baseField->id,
                'code' => $baseField->code,
            ]);

            return $baseField->load(self::RELATIONS);
        } catch (Exception $exception) {
            Log::error('Failed to create base field.', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Update an existing base field.
     */
    public function update(
        string $id,
        array $data
    ): BaseField {
        try {
            $baseField = DB::transaction(function () use ($id, $data) {
                $baseField = BaseField::findOrFail($id);

                $baseField->update([
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                    'data_type' => $data['data_type'],
                    'is_active' => $data['is_active']
                        ?? $baseField->is_active,
                    'sort_order' => $data['sort_order']
                        ?? $baseField->sort_order,
                ]);

                return $baseField;
            });

            Log::info('Base field updated successfully.', [
                'base_field_id' => $baseField->id,
                'code' => $baseField->code,
            ]);

            return $baseField->load(self::RELATIONS);
        } catch (Exception $exception) {
            Log::error('Failed to update base field.', [
                'base_field_id' => $id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Soft delete base field.
     */
    public function delete(string $id): bool
    {
        try {
            $baseField = BaseField::findOrFail($id);

            $result = DB::transaction(
                fn () => $baseField->delete()
            );

            Log::info('Base field deleted successfully.', [
                'base_field_id' => $id,
                'code' => $baseField->code,
            ]);

            return $result;
        } catch (Exception $exception) {
            Log::error('Failed to delete base field.', [
                'base_field_id' => $id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Restore deleted base field.
     */
    public function restore(string $id): BaseField
    {
        try {
            $baseField = DB::transaction(function () use ($id) {
                $baseField = BaseField::withTrashed()
                    ->findOrFail($id);

                $baseField->restore();

                return $baseField;
            });

            Log::info('Base field restored successfully.', [
                'base_field_id' => $id,
                'code' => $baseField->code,
            ]);

            return $baseField->load(self::RELATIONS);
        } catch (Exception $exception) {
            Log::error('Failed to restore base field.', [
                'base_field_id' => $id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Change base field active status.
     */
    public function changeStatus(
        string $id,
        bool $status
    ): BaseField {
        try {
            $baseField = DB::transaction(function () use ($id, $status) {
                $baseField = BaseField::findOrFail($id);

                $baseField->update([
                    'is_active' => $status,
                ]);

                return $baseField;
            });

            Log::info('Base field status changed.', [
                'base_field_id' => $id,
                'status' => $status,
            ]);

            return $baseField->load(self::RELATIONS);
        } catch (Exception $exception) {
            Log::error('Failed to change base field status.', [
                'base_field_id' => $id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }
}