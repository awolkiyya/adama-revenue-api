<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCode;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;


class RevenueServiceService
{

    /**
     * Paginated revenue services.
     *
     * Supported query params (all optional):
     * - search:            string   — matches service name or code
     * - revenue_domain:    string   — RevenueCategory.revenue_domain (via revenueCode.category)
     * - code:               string   — RevenueCode.code
     * - collection_mode:   string   — RevenueService.collection_mode
     * - is_active:          bool     — RevenueService.is_active
     * - per_page:           int      — page size, defaults to 20
     */
    public function paginate(Request $request)
    {
        return RevenueService::query()
            ->with([
                /*
                 * Revenue hierarchy
                 *
                 * RevenueService
                 *      ↓
                 * RevenueCode
                 *      ↓
                 * RevenueCategory
                 */
                'revenueCode.category',
    
                /*
                 * Service field configuration
                 *
                 * RevenueServiceField
                 *      ↓
                 * BaseField
                 *      ├── MeasurementUnit
                 *      └── Options
                 */
                'fields.baseField.measurementUnit',
                'fields.baseField.options',
            ])
            ->withCount('fields')
    
            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            |
            | Matches against the service's own name, plus its parent revenue
            | code — since officers often search by code number rather than name.
            |
            */
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $search = trim($request->string('search'));
    
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhereHas('revenueCode', function (Builder $codeQuery) use ($search) {
                            $codeQuery->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
    
            /*
            |--------------------------------------------------------------------------
            | Revenue Domain
            |--------------------------------------------------------------------------
            |
            | Lives on RevenueCategory, two hops up via revenueCode.category,
            | so this must be a whereHas across both relations.
            |
            */
            ->when($request->filled('revenue_domain'), function (Builder $query) use ($request) {
                $query->whereHas('revenueCode.category', function (Builder $categoryQuery) use ($request) {
                    $categoryQuery->where('revenue_domain', $request->string('revenue_domain'));
                });
            })
    
            /*
            |--------------------------------------------------------------------------
            | Revenue Code
            |--------------------------------------------------------------------------
            */
            ->when($request->filled('code'), function (Builder $query) use ($request) {
                $query->whereHas('revenueCode', function (Builder $codeQuery) use ($request) {
                    $codeQuery->where('code', $request->string('code'));
                });
            })
    
            /*
            |--------------------------------------------------------------------------
            | Collection Mode
            |--------------------------------------------------------------------------
            */
            ->when($request->filled('collection_mode'), function (Builder $query) use ($request) {
                $query->where('collection_mode', $request->string('collection_mode'));
            })
    
            /*
            |--------------------------------------------------------------------------
            | Active Status
            |--------------------------------------------------------------------------
            |
            | The frontend sends a real boolean (true/false), but query strings
            | arrive as "1"/"0" or "true"/"false" strings over HTTP, so this
            | must be normalized rather than compared with ===.
            |
            */
            ->when($request->has('is_active'), function (Builder $query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
    
            ->latest()
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();
    }

    /**
     * Summary cards.
     */
    public function summary(): array
    {
        return [
            'total' => RevenueService::count(),

            'active' => RevenueService::where(
                'is_active',
                true
            )->count(),

            'inactive' => RevenueService::where(
                'is_active',
                false
            )->count(),

            'deleted' => RevenueService::onlyTrashed()->count(),
        ];
    }

    /**
     * Create Revenue Service.
     *
     * The revenue service and its configured fields are created
     * inside the same database transaction.
     */
    public function create(array $data): RevenueService
    {
        return DB::transaction(function () use ($data) {

            /*
            |--------------------------------------------------------------------------
            | Validate Revenue Code
            |--------------------------------------------------------------------------
            */

            $this->validateRevenueCode(
                $data['revenue_code_id']
            );

            /*
            |--------------------------------------------------------------------------
            | Extract service fields
            |--------------------------------------------------------------------------
            |
            | `fields` belongs to revenue_service_fields, not
            | revenue_services.
            |
            */

            $fields = $data['fields'] ?? [];

            unset($data['fields']);

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $data['created_by'] = auth()->id();

            /*
            |--------------------------------------------------------------------------
            | Create Service
            |--------------------------------------------------------------------------
            */

            $service = RevenueService::create($data);

            /*
            |--------------------------------------------------------------------------
            | Create Service Fields
            |--------------------------------------------------------------------------
            */

            if (!empty($fields)) {
                $this->syncFields(
                    $service,
                    $fields
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Service
            |--------------------------------------------------------------------------
            */

            return $service->fresh([
                'revenueCode',
                'fields.baseField',
            ]);
        });
    }

    /**
     * Update Revenue Service.
     *
     * Service fields are synchronized when `fields` is present
     * in the request.
     */
    public function update(
        RevenueService $service,
        array $data
    ): RevenueService {
        return DB::transaction(function () use (
            $service,
            $data
        ) {

            /*
            |--------------------------------------------------------------------------
            | Prevent updating deleted service
            |--------------------------------------------------------------------------
            */

            if ($service->trashed()) {
                throw ValidationException::withMessages([
                    'service' =>
                        'Deleted service cannot be updated.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Revenue Code Changed
            |--------------------------------------------------------------------------
            */

            if (
                array_key_exists(
                    'revenue_code_id',
                    $data
                )
                &&
                $data['revenue_code_id']
                    !==
                $service->revenue_code_id
            ) {
                $this->validateRevenueCode(
                    $data['revenue_code_id']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Extract Fields
            |--------------------------------------------------------------------------
            |
            | Do NOT send fields into RevenueService::update().
            |
            */

            $hasFields = array_key_exists(
                'fields',
                $data
            );

            $fields = $data['fields'] ?? [];

            unset($data['fields']);

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $data['updated_by'] = auth()->id();

            /*
            |--------------------------------------------------------------------------
            | Update Service
            |--------------------------------------------------------------------------
            */

            if (!empty($data)) {
                $service->update($data);
            }

            /*
            |--------------------------------------------------------------------------
            | Synchronize Service Fields
            |--------------------------------------------------------------------------
            |
            | Important:
            |
            | We only synchronize fields when the caller explicitly
            | supplied the `fields` property.
            |
            | This makes PATCH-style updates safe.
            |
            */

            if ($hasFields) {
                $this->syncFields(
                    $service,
                    $fields
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Return Fresh Service
            |--------------------------------------------------------------------------
            */

            return $service->fresh([
                'revenueCode',
                'fields.baseField',
            ]);
        });
    }

    /**
     * Synchronize Revenue Service fields.
     *
     * The incoming `$fields` array represents the desired complete
     * configuration of the service fields.
     *
     * Existing fields are updated.
     * New fields are created.
     * Removed fields are deleted.
     */
    private function syncFields(
        RevenueService $service,
        array $fields
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate BaseField references
        |--------------------------------------------------------------------------
        */

        $baseFieldIds = collect($fields)
            ->pluck('base_field_id')
            ->filter()
            ->unique()
            ->values();

        if ($baseFieldIds->isNotEmpty()) {

            $validBaseFieldIds = DB::table('base_fields')
                ->whereIn(
                    'id',
                    $baseFieldIds
                )
                ->where(
                    'is_active',
                    true
                )
                ->pluck('id');

            $invalidBaseFieldIds = $baseFieldIds
                ->diff($validBaseFieldIds)
                ->values();

            if ($invalidBaseFieldIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'fields' =>
                        'One or more selected base fields are invalid or inactive.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Existing Service Fields
        |--------------------------------------------------------------------------
        */

        $existingFields = RevenueServiceField::query()
            ->where(
                'service_id',
                $service->id
            )
            ->get()
            ->keyBy('base_field_id');

        /*
        |--------------------------------------------------------------------------
        | Track Incoming Base Fields
        |--------------------------------------------------------------------------
        */

        $incomingBaseFieldIds = [];

        /*
        |--------------------------------------------------------------------------
        | Create / Update Fields
        |--------------------------------------------------------------------------
        */

        foreach ($fields as $index => $field) {

            $baseFieldId =
                $field['base_field_id'];

            $incomingBaseFieldIds[] =
                $baseFieldId;

            /*
            |--------------------------------------------------------------------------
            | Normalize Values
            |--------------------------------------------------------------------------
            */

            $payload = [
                'sort_order' =>
                    $field['sort_order']
                    ?? $index,

                'is_required' =>
                    $field['is_required']
                    ?? false,

                'label' =>
                    $field['label']
                    ?? null,

                'help_text' =>
                    $field['help_text']
                    ?? null,

                'validation_rules' =>
                    $field['validation_rules']
                    ?? null,

                /*
                |--------------------------------------------------------------------------
                | Keep field active when explicitly configured.
                |--------------------------------------------------------------------------
                */

                'is_active' =>
                    $field['is_active']
                    ?? true,
            ];

            /*
            |--------------------------------------------------------------------------
            | Existing Field
            |--------------------------------------------------------------------------
            */

            if (
                $existingFields->has(
                    $baseFieldId
                )
            ) {

                $serviceField =
                    $existingFields->get(
                        $baseFieldId
                    );

                $serviceField->update(
                    $payload
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | New Field
            |--------------------------------------------------------------------------
            */

            RevenueServiceField::create([
                'service_id' =>
                    $service->id,

                'base_field_id' =>
                    $baseFieldId,

                'sort_order' =>
                    $payload['sort_order'],

                'is_required' =>
                    $payload['is_required'],

                'label' =>
                    $payload['label'],

                'help_text' =>
                    $payload['help_text'],

                'validation_rules' =>
                    $payload['validation_rules'],

                'is_active' =>
                    $payload['is_active'],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Deleted Fields
        |--------------------------------------------------------------------------
        |
        | The frontend sends the complete desired field configuration.
        |
        | Therefore any existing field that is not present anymore
        | should be removed from the service.
        |
        */

        if ($existingFields->isNotEmpty()) {

            $fieldsToRemove = $existingFields
                ->filter(
                    function (
                        RevenueServiceField $field
                    ) use ($incomingBaseFieldIds) {

                        return !in_array(
                            $field->base_field_id,
                            $incomingBaseFieldIds,
                            true
                        );
                    }
                );

            foreach ($fieldsToRemove as $field) {
                $field->delete();
            }
        }
    }

    /**
     * Validate Revenue Code.
     */
    private function validateRevenueCode(
        string $id
    ): void {
        $exists = RevenueCode::query()
            ->where(
                'id',
                $id
            )
            ->where(
                'is_active',
                true
            )
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'revenue_code_id' =>
                    'Revenue code is inactive or invalid.',
            ]);
        }
    }

    /**
     * Soft delete Revenue Service.
     */
    public function delete(
        RevenueService $service
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Prevent deletion when assessments exist
        |--------------------------------------------------------------------------
        */

        if (
            $service
                ->assessments()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'service' =>
                    'Cannot delete service with existing assessments.',
            ]);
        }

        return $service->delete();
    }

    /**
     * Restore deleted service.
     */
    public function restore(
        string $id
    ): RevenueService {
        $service = RevenueService::onlyTrashed()
            ->findOrFail($id);

        $service->restore();

        return $service->fresh([
            'revenueCode',
            'fields.baseField',
        ]);
    }

    /**
     * Permanently delete service.
     */
    public function forceDelete(
        RevenueService $service
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Prevent deletion when assessments exist
        |--------------------------------------------------------------------------
        */

        if (
            $service
                ->assessments()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'service' =>
                    'Service is already used and cannot be permanently deleted.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Delete service
        |--------------------------------------------------------------------------
        |
        | revenue_service_fields has cascadeOnDelete(), so its fields
        | are automatically removed by the database.
        |
        */

        return $service->forceDelete();
    }
}