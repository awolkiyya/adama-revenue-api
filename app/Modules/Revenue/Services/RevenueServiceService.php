<?php

namespace App\Modules\Revenue\Services;

use App\Models\RevenueCode;
use App\Models\RevenueService;
use App\Models\RevenueServiceField;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueServiceService
{
    /**
     * Paginated revenue services.
     *
     * Supported query params (all optional):
     *
     * - search:            string   — matches service name or code
     * - revenue_domain:    string   — RevenueCategory.revenue_domain
     * - code:              string   — RevenueCode.code
     * - collection_mode:   string   — further filters the user's allowed modes
     * - is_active:         bool     — RevenueService.is_active
     * - per_page:          int      — page size, defaults to 20
     *
     * Workflow access:
     *
     * - SYSTEM_ADMIN:
     *      Can access all revenue services.
     *
     * - REVENUE_COLLECTOR:
     *      Can access collection-capable services:
     *
     *          COLLECTION_ONLY
     *          ASSESSMENT_AND_COLLECTION
     *
     *      Collection is centralized in the Revenue Office,
     *      so sector service access rules are NOT applied.
     *
     * - Other users:
     *      Can access assessment-capable services:
     *
     *          ASSESSMENT_ONLY
     *          ASSESSMENT_AND_COLLECTION
     *
     *      Additionally, the service must have an active
     *      service access rule for the user's sector.
     */
    public function paginate(Request $request)
    {
        $user = auth()->user();

        $query = RevenueService::query();

        /*
        |--------------------------------------------------------------------------
        | User Workflow / Access Scope
        |--------------------------------------------------------------------------
        |
        | This is the primary authorization-aware service scope.
        |
        | SYSTEM_ADMIN
        |     → all services
        |
        | REVENUE_COLLECTOR
        |     → collection-capable services
        |
        | Other users
        |     → assessment-capable services
        |     → sector access required
        |
        */
        $this->applyUserScope(
            $query,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Relationships
        |--------------------------------------------------------------------------
        |
        | RevenueService
        |      ↓
        | RevenueCode
        |      ↓
        | RevenueCategory
        |
        */
        $query->with([
            'revenueCode.category',

            /*
             * RevenueService
             *      ↓
             * RevenueServiceField
             *      ↓
             * BaseField
             *      ├── MeasurementUnit
             *      └── Options
             */
            'fields.baseField.measurementUnit',
            'fields.baseField.options',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Field Count
        |--------------------------------------------------------------------------
        */

        $query->withCount('fields');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        |
        | Matches:
        |
        | - service name
        | - revenue code
        | - revenue code name
        |
        */
        $query->when(
            $request->filled('search'),
            function (Builder $query) use ($request) {

                $search = trim(
                    $request->string('search')
                );

                $query->where(
                    function (Builder $inner) use ($search) {

                        $inner
                            ->where(
                                'name',
                                'like',
                                "%{$search}%"
                            )

                            ->orWhereHas(
                                'revenueCode',
                                function (Builder $codeQuery) use ($search) {

                                    $codeQuery
                                        ->where(
                                            'code',
                                            'like',
                                            "%{$search}%"
                                        )

                                        ->orWhere(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        );
                                }
                            );
                    }
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Revenue Domain
        |--------------------------------------------------------------------------
        |
        | Revenue domain is stored on RevenueCategory.
        |
        | RevenueService
        |      ↓
        | RevenueCode
        |      ↓
        | RevenueCategory
        |
        */
        $query->when(
            $request->filled('revenue_domain'),
            function (Builder $query) use ($request) {

                $query->whereHas(
                    'revenueCode.category',
                    function (Builder $categoryQuery) use ($request) {

                        $categoryQuery->where(
                            'revenue_domain',
                            $request->string(
                                'revenue_domain'
                            )
                        );
                    }
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Revenue Code
        |--------------------------------------------------------------------------
        */

        $query->when(
            $request->filled('code'),
            function (Builder $query) use ($request) {

                $query->whereHas(
                    'revenueCode',
                    function (Builder $codeQuery) use ($request) {

                        $codeQuery->where(
                            'code',
                            $request->string('code')
                        );
                    }
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Collection Mode
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | This filter only narrows the already-authorized workflow scope.
        |
        | It cannot grant additional access.
        |
        | Example:
        |
        | REVENUE_COLLECTOR
        |     allowed:
        |         COLLECTION_ONLY
        |         ASSESSMENT_AND_COLLECTION
        |
        | If collector sends:
        |
        |     ?collection_mode=COLLECTION_ONLY
        |
        |     → COLLECTION_ONLY
        |
        | If collector sends:
        |
        |     ?collection_mode=ASSESSMENT_AND_COLLECTION
        |
        |     → ASSESSMENT_AND_COLLECTION
        |
        | Normal sector user:
        |
        |     ?collection_mode=COLLECTION_ONLY
        |
        |     → empty result
        |
        */
        $query->when(
            $request->filled('collection_mode'),
            function (Builder $query) use ($request) {

                $query->where(
                    'collection_mode',
                    $request->string(
                        'collection_mode'
                    )
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Active Status
        |--------------------------------------------------------------------------
        |
        | Handles:
        |
        | true
        | false
        | "true"
        | "false"
        | "1"
        | "0"
        |
        */
        $query->when(
            $request->has('is_active'),
            function (Builder $query) use ($request) {

                $query->where(
                    'is_active',
                    $request->boolean(
                        'is_active'
                    )
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Ordering
        |--------------------------------------------------------------------------
        */

        $query->latest();

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        return $query
            ->paginate(
                $request->integer(
                    'per_page',
                    20
                )
            )
            ->withQueryString();
    }

    /**
     * Apply workflow and access scope based on the authenticated user.
     *
     * This method is the central point that determines which
     * revenue services a user is allowed to see.
     *
     * Rules:
     *
     * SYSTEM_ADMIN
     *     → all services
     *
     * REVENUE_COLLECTOR
     *     → COLLECTION_ONLY
     *     → ASSESSMENT_AND_COLLECTION
     *
     * Other users
     *     → ASSESSMENT_ONLY
     *     → ASSESSMENT_AND_COLLECTION
     *     → active sector access rule required
     */
    private function applyUserScope(
        Builder $query,
        User $user
    ): Builder {

        /*
        |--------------------------------------------------------------------------
        | SYSTEM ADMIN
        |--------------------------------------------------------------------------
        |
        | System administrators are not restricted by workflow
        | or sector service access.
        |
        */
        if ($user->hasRole('SYSTEM_ADMIN')) {
            return $query;
        }

        /*
        |--------------------------------------------------------------------------
        | REVENUE COLLECTOR
        |--------------------------------------------------------------------------
        |
        | Collection is centralized in the Revenue Office.
        |
        | Therefore:
        |
        | - sector service access is NOT checked
        | - only collection-capable services are returned
        |
        */
        if ($user->hasRole('REVENUE_COLLECTOR')) {

            return $query->whereIn(
                'collection_mode',
                [
                    'COLLECTION_ONLY',
                    'ASSESSMENT_AND_COLLECTION',
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | OTHER USERS
        |--------------------------------------------------------------------------
        |
        | Other municipal users work on assessment.
        |
        | Therefore:
        |
        | - collection-only services are hidden
        | - service must support assessment
        | - user's sector must have active access
        |
        */
        return $query
            ->whereIn(
                'collection_mode',
                [
                    'ASSESSMENT_ONLY',
                    'ASSESSMENT_AND_COLLECTION',
                ]
            )
            ->accessibleTo($user);
    }

    /**
     * Summary cards.
     *
     * IMPORTANT:
     *
     * The summary uses exactly the same user workflow/access
     * scope as the paginated service list.
     *
     * Therefore:
     *
     * - SYSTEM_ADMIN sees all services.
     * - REVENUE_COLLECTOR sees collection-capable services.
     * - Other users see assessment-capable services available
     *   to their sector.
     */
    public function summary(): array
    {
        $user = auth()->user();

        /*
        |--------------------------------------------------------------------------
        | Accessible Services Query
        |--------------------------------------------------------------------------
        */

        $query = RevenueService::query();

        $this->applyUserScope(
            $query,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        return [
            'total' =>
                (clone $query)->count(),

            'active' =>
                (clone $query)
                    ->where(
                        'is_active',
                        true
                    )
                    ->count(),

            'inactive' =>
                (clone $query)
                    ->where(
                        'is_active',
                        false
                    )
                    ->count(),

            /*
            |--------------------------------------------------------------------------
            | Deleted Services
            |--------------------------------------------------------------------------
            |
            | Deleted services are not part of the normal active
            | service list.
            |
            | We still apply the exact same user workflow/access
            | scope so deleted counts do not expose unrelated
            | services.
            |
            */
            'deleted' =>
                $this->deletedServicesQuery(
                    $user
                )->count(),
        ];
    }

    /**
     * Build the deleted-service query using the same
     * workflow/access rules as the normal service query.
     */
    private function deletedServicesQuery(
        User $user
    ): Builder {

        $query = RevenueService::onlyTrashed();

        /*
        |--------------------------------------------------------------------------
        | SYSTEM ADMIN
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('SYSTEM_ADMIN')) {
            return $query;
        }

        /*
        |--------------------------------------------------------------------------
        | REVENUE COLLECTOR
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('REVENUE_COLLECTOR')) {

            return $query->whereIn(
                'collection_mode',
                [
                    'COLLECTION_ONLY',
                    'ASSESSMENT_AND_COLLECTION',
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | OTHER USERS
        |--------------------------------------------------------------------------
        */

        return $query
            ->whereIn(
                'collection_mode',
                [
                    'ASSESSMENT_ONLY',
                    'ASSESSMENT_AND_COLLECTION',
                ]
            )
            ->accessibleTo($user);
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
            | Extract Service Fields
            |--------------------------------------------------------------------------
            |
            | `fields` belongs to revenue_service_fields,
            | not revenue_services.
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

            $service = RevenueService::create(
                $data
            );

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
     * Service fields are synchronized when `fields`
     * is present in the request.
     */
    public function update(
        RevenueService $service,
        array $data
    ): RevenueService {
        return DB::transaction(
            function () use (
                $service,
                $data
            ) {

                /*
                |--------------------------------------------------------------------------
                | Prevent Updating Deleted Service
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
                | Do not send fields into RevenueService::update().
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

                $data['updated_by'] =
                    auth()->id();

                /*
                |--------------------------------------------------------------------------
                | Update Service
                |--------------------------------------------------------------------------
                */

                if (!empty($data)) {

                    $service->update(
                        $data
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Synchronize Service Fields
                |--------------------------------------------------------------------------
                |
                | Only synchronize fields when the caller explicitly
                | supplied the `fields` property.
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
            }
        );
    }

    /**
     * Synchronize Revenue Service fields.
     *
     * The incoming `$fields` array represents the desired
     * complete configuration of the service fields.
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
        | Validate BaseField References
        |--------------------------------------------------------------------------
        */

        $baseFieldIds = collect($fields)
            ->pluck('base_field_id')
            ->filter()
            ->unique()
            ->values();

        if ($baseFieldIds->isNotEmpty()) {

            $validBaseFieldIds =
                DB::table('base_fields')
                    ->whereIn(
                        'id',
                        $baseFieldIds
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->pluck('id');

            $invalidBaseFieldIds =
                $baseFieldIds
                    ->diff(
                        $validBaseFieldIds
                    )
                    ->values();

            if (
                $invalidBaseFieldIds->isNotEmpty()
            ) {

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

        $existingFields =
            RevenueServiceField::query()
                ->where(
                    'service_id',
                    $service->id
                )
                ->get()
                ->keyBy(
                    'base_field_id'
                );

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

        foreach (
            $fields as $index => $field
        ) {

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
        | The frontend sends the complete desired field
        | configuration.
        |
        | Therefore any existing field that is no longer
        | present should be removed.
        |
        */

        if ($existingFields->isNotEmpty()) {

            $fieldsToRemove =
                $existingFields->filter(
                    function (
                        RevenueServiceField $field
                    ) use (
                        $incomingBaseFieldIds
                    ) {

                        return !in_array(
                            $field->base_field_id,
                            $incomingBaseFieldIds,
                            true
                        );
                    }
                );

            foreach (
                $fieldsToRemove as $field
            ) {

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
        | Prevent Deletion When Assessments Exist
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
        | Prevent Deletion When Assessments Exist
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
        | Delete Service
        |--------------------------------------------------------------------------
        |
        | revenue_service_fields has cascadeOnDelete(),
        | so its fields are automatically removed by the
        | database.
        |
        */

        return $service->forceDelete();
    }
}