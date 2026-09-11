<?php

namespace App\Modules\Revenue\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\BaseField;
use App\Models\BaseFieldOption;

class BaseFieldService
{
    /**
     * Data types that support options.
     */
    private const OPTION_DATA_TYPES = [
        'SELECT',
        'RADIO',
        'CHECKBOX',
    ];

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

                /*
                |--------------------------------------------------------------------------
                | Create Base Field
                |--------------------------------------------------------------------------
                */

                $baseField = BaseField::create([
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                    'data_type' => $data['data_type'],
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $data['sort_order'] ?? 0,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Create Options
                |--------------------------------------------------------------------------
                */

                if (
                    $this->supportsOptions($baseField->data_type)
                    && !empty($data['options'])
                ) {
                    $this->createOptions(
                        $baseField,
                        $data['options']
                    );
                }

                return $baseField;
            });

            Log::info('Base field created successfully.', [
                'base_field_id' => $baseField->id,
                'code' => $baseField->code,
                'data_type' => $baseField->data_type,
                'options_count' => $baseField->options()->count(),
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

                /*
                |--------------------------------------------------------------------------
                | Update Base Field
                |--------------------------------------------------------------------------
                */

                $baseField->update([
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'measurement_unit_id' =>
                        $data['measurement_unit_id'] ?? null,
                    'data_type' => $data['data_type'],
                    'is_active' => $data['is_active']
                        ?? $baseField->is_active,
                    'sort_order' => $data['sort_order']
                        ?? $baseField->sort_order,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Synchronize Options
                |--------------------------------------------------------------------------
                */

                if ($this->supportsOptions($baseField->data_type)) {

                    $this->syncOptions(
                        $baseField,
                        $data['options'] ?? []
                    );

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Remove Old Options
                    |--------------------------------------------------------------------------
                    |
                    | If a field changes from SELECT/RADIO/CHECKBOX to
                    | another data type, it must no longer have options.
                    |
                    */

                    $baseField->options()->delete();
                }

                return $baseField;
            });

            Log::info('Base field updated successfully.', [
                'base_field_id' => $baseField->id,
                'code' => $baseField->code,
                'data_type' => $baseField->data_type,
                'options_count' => $baseField->options()->count(),
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
     * Create options for a base field.
     */
    private function createOptions(
        BaseField $baseField,
        array $options
    ): void {
        foreach ($options as $index => $option) {

            BaseFieldOption::create([
                'base_field_id' => $baseField->id,

                'value' => $option['value'],

                'label' => $option['label'],

                'sort_order' =>
                    $option['sort_order'] ?? $index,

                'is_default' =>
                    $option['is_default'] ?? false,
            ]);
        }
    }

    /**
     * Synchronize options during update.
     *
     * Existing options with an ID are updated.
     * New options without an ID are created.
     * Existing options omitted from the request are deleted.
     */
    private function syncOptions(
        BaseField $baseField,
        array $options
    ): void {
        /*
        |--------------------------------------------------------------------------
        | No Options
        |--------------------------------------------------------------------------
        |
        | If the frontend sends an empty array, remove all existing options.
        |
        */

        if (empty($options)) {
            $baseField->options()->delete();

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Option IDs
        |--------------------------------------------------------------------------
        */

        $existingOptionIds = $baseField
            ->options()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $submittedOptionIds = [];

        /*
        |--------------------------------------------------------------------------
        | Create / Update Options
        |--------------------------------------------------------------------------
        */

        foreach ($options as $index => $option) {

            $optionId = $option['id'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Update Existing Option
            |--------------------------------------------------------------------------
            */

            if ($optionId !== null) {

                $optionId = (string) $optionId;

                /*
                |--------------------------------------------------------------------------
                | Security Check
                |--------------------------------------------------------------------------
                |
                | Prevent updating an option belonging to another
                | BaseField.
                |
                */

                if (!in_array($optionId, $existingOptionIds, true)) {
                    throw new Exception(
                        'The selected option does not belong to this base field.'
                    );
                }

                $baseFieldOption = $baseField
                    ->options()
                    ->whereKey($optionId)
                    ->firstOrFail();

                $baseFieldOption->update([
                    'value' => $option['value'],

                    'label' => $option['label'],

                    'sort_order' =>
                        $option['sort_order'] ?? $index,

                    'is_default' =>
                        $option['is_default'] ?? false,
                ]);

                $submittedOptionIds[] = $optionId;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Create New Option
            |--------------------------------------------------------------------------
            */

            $newOption = BaseFieldOption::create([
                'base_field_id' => $baseField->id,

                'value' => $option['value'],

                'label' => $option['label'],

                'sort_order' =>
                    $option['sort_order'] ?? $index,

                'is_default' =>
                    $option['is_default'] ?? false,
            ]);

            $submittedOptionIds[] = (string) $newOption->id;
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Removed Options
        |--------------------------------------------------------------------------
        */

        $optionsToDelete = array_diff(
            $existingOptionIds,
            $submittedOptionIds
        );

        if (!empty($optionsToDelete)) {
            $baseField
                ->options()
                ->whereIn('id', $optionsToDelete)
                ->delete();
        }
    }

    /**
     * Determine whether a data type supports options.
     */
    private function supportsOptions(string $dataType): bool
    {
        return in_array(
            strtoupper($dataType),
            self::OPTION_DATA_TYPES,
            true
        );
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