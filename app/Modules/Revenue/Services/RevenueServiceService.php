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
 * - search:            string   — matches service name, revenue code, or code name
 * - revenue_domain:    string   — RevenueCategory.revenue_domain
 * - code:              string   — RevenueCode UUID (revenue_codes.id)
 * - collection_mode:   string   — further filters the user's allowed modes
 * - is_active:         bool     — RevenueService.is_active
 * - page:              int      — page number
 * - per_page:          int      — page size, defaults to 20
 *
 * Workflow access:
 *
 * - SYSTEM_ADMIN:
 *      Can access all revenue services.
 *
 * - REVENUE_COLLECTOR:
 *      Can access collection-capable services:
 *          COLLECTION_ONLY
 *          ASSESSMENT_AND_COLLECTION
 *
 *      Sector service access rules are not applied.
 *
 * - Other users:
 *      Can access assessment-capable services:
 *          ASSESSMENT_ONLY
 *          ASSESSMENT_AND_COLLECTION
 *
 *      An active service access rule for the user's sector is required.
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
    */

    $query->with([
        'revenueCode.category',
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
    | - service name
    | - revenue code
    | - revenue code name
    |
    */
    $query->when(
        $request->filled('search'),
        function (Builder $query) use ($request) {
            $search = trim(
                $request->string('search')->toString()
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
            $revenueDomain = $request
                ->string('revenue_domain')
                ->toString();

            $query->whereHas(
                'revenueCode.category',
                function (Builder $categoryQuery) use ($revenueDomain) {
                    $categoryQuery->where(
                        'revenue_domain',
                        $revenueDomain
                    );
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Revenue Code
    |--------------------------------------------------------------------------
    |
    | The frontend sends the selected RevenueCode UUID, for example:
    |
    | code=01a0a718-17fe-7281-b796-93d0cff856a2
    |
    | Filter by the related RevenueCode primary key, not its human-readable
    | code column.
    |
    */
    $query->when(
        $request->filled('code'),
        function (Builder $query) use ($request) {
            $codeId = $request->input('code');

            $query->whereHas(
                'revenueCode',
                function (Builder $codeQuery) use ($codeId) {
                    $codeQuery->whereKey($codeId);
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Collection Mode
    |--------------------------------------------------------------------------
    |
    | This filter narrows the authorized workflow scope. It does not grant
    | access to services outside that scope.
    |
    */
    $query->when(
        $request->filled('collection_mode'),
        function (Builder $query) use ($request) {
            $collectionMode = $request
                ->string('collection_mode')
                ->toString();

            $query->where(
                'collection_mode',
                $collectionMode
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Active Status
    |--------------------------------------------------------------------------
    |
    | Supports true, false, "true", "false", "1", and "0".
    |
    */
    $query->when(
        $request->has('is_active'),
        function (Builder $query) use ($request) {
            $query->where(
                'is_active',
                $request->boolean('is_active')
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

    $perPage = $request->integer('per_page', 20);

    // Prevent invalid or excessively large page sizes.
    $perPage = max(1, min($perPage, 100));

    return $query
        ->paginate($perPage)
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
     * The incoming $fields array represents the complete desired
     * configuration of the service fields.
     *
     * - Existing fields are updated.
     * - Soft-deleted fields are restored when selected again.
     * - New fields are created only when no relationship exists.
     * - Fields omitted from the submitted configuration are soft-deleted.
     */
    private function syncFields(
        RevenueService $service,
        array $fields
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Base Field References
        |--------------------------------------------------------------------------
        */

        $baseFieldIds = collect($fields)
            ->pluck('base_field_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Field Selections
        |--------------------------------------------------------------------------
        */

        if ($baseFieldIds->count() !== $baseFieldIds->unique()->count()) {
            throw ValidationException::withMessages([
                'fields' => 'A base field cannot be selected more than once for the same revenue service.',
            ]);
        }

        $baseFieldIds = $baseFieldIds->unique()->values();

        /*
        |--------------------------------------------------------------------------
        | Validate Active Base Fields
        |--------------------------------------------------------------------------
        */

        if ($baseFieldIds->isNotEmpty()) {
            $validBaseFieldIds = DB::table('base_fields')
                ->whereIn('id', $baseFieldIds)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (string) $id);

            $invalidBaseFieldIds = $baseFieldIds
                ->diff($validBaseFieldIds)
                ->values();

            if ($invalidBaseFieldIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'fields' => 'One or more selected base fields are invalid or inactive.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Load Existing Fields Including Soft-Deleted Records
        |--------------------------------------------------------------------------
        |
        | withTrashed() is essential. Without it, previously deleted
        | relationships would be missed and duplicate inserts attempted.
        |
        */

        $existingFields = RevenueServiceField::withTrashed()
            ->where('service_id', $service->id)
            ->get()
            ->keyBy(fn (RevenueServiceField $field) => (string) $field->base_field_id);

        /*
        |--------------------------------------------------------------------------
        | Track Incoming Base Field IDs
        |--------------------------------------------------------------------------
        */

        $incomingBaseFieldIds = $baseFieldIds->all();

        /*
        |--------------------------------------------------------------------------
        | Create, Update, or Restore Fields
        |--------------------------------------------------------------------------
        */

        foreach ($fields as $index => $field) {
            $baseFieldId = (string) $field['base_field_id'];

            /*
            |--------------------------------------------------------------------------
            | Normalize Field Configuration
            |--------------------------------------------------------------------------
            */

            $payload = [
                'sort_order' => $field['sort_order'] ?? $index,
                'is_required' => $field['is_required'] ?? false,
                'label' => $field['label'] ?? null,
                'help_text' => $field['help_text'] ?? null,
                'validation_rules' => $field['validation_rules'] ?? null,
                'is_active' => $field['is_active'] ?? true,
            ];

            /*
            |--------------------------------------------------------------------------
            | Existing Relationship
            |--------------------------------------------------------------------------
            */

            if ($existingFields->has($baseFieldId)) {
                /** @var RevenueServiceField $serviceField */
                $serviceField = $existingFields->get($baseFieldId);

                /*
                |--------------------------------------------------------------------------
                | Restore Previously Deleted Relationship
                |--------------------------------------------------------------------------
                */

                if ($serviceField->trashed()) {
                    $serviceField->restore();
                }

                /*
                |--------------------------------------------------------------------------
                | Update Existing Relationship
                |--------------------------------------------------------------------------
                */

                $serviceField->update($payload);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Create New Relationship
            |--------------------------------------------------------------------------
            */

            RevenueServiceField::create([
                'service_id' => $service->id,
                'base_field_id' => $baseFieldId,
                ...$payload,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Soft-Delete Fields Removed From the Configuration
        |--------------------------------------------------------------------------
        |
        | Only fields absent from the submitted configuration are removed.
        | Already soft-deleted records remain deleted unless selected again.
        |
        */

        foreach ($existingFields as $baseFieldId => $serviceField) {
            if (
                !in_array((string) $baseFieldId, $incomingBaseFieldIds, true)
                && !$serviceField->trashed()
            ) {
                $serviceField->delete();
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