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
use Illuminate\Support\Facades\Log;

class RevenueServiceService
{
   





/**
 * Paginated revenue services with detailed diagnostic logging.
 *
 * Supported pagination parameters:
 * - page / per_page
 * - offset / limit
 *
 * Searchable fields:
 * - Revenue service name
 * - Revenue code
 * - Revenue code name
 * - Revenue category name
 */
public function paginate(Request $request)
{
    $user = auth()->user();

    try {

        /*
        |--------------------------------------------------------------------------
        | 1. Request Information
        |--------------------------------------------------------------------------
        */

        Log::info('Revenue Services: Pagination started', [
            'user_id' => $user?->id,
            'user_roles' => $user && method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->all()
                : [],
            'sector_id' => $user?->sector_id,
            'query_parameters' => $request->query(),
            'search' => $request->input('search'),
            'database_connection' => DB::connection()->getDriverName(),
            'database_name' => DB::connection()->getDatabaseName(),
        ]);

        $query = RevenueService::query();

        /*
        |--------------------------------------------------------------------------
        | 2. User Workflow / Access Scope
        |--------------------------------------------------------------------------
        */

        Log::info('Revenue Services: Applying user access scope', [
            'user_id' => $user?->id,
            'roles' => $user && method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->all()
                : [],
            'sector_id' => $user?->sector_id,
        ]);

        $this->applyUserScope($query, $user);

        /*
        |--------------------------------------------------------------------------
        | 3. Relationships
        |--------------------------------------------------------------------------
        */

        $query->with([
            'revenueCode.category',
            'fields.baseField.measurementUnit',
            'fields.baseField.options',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Field Count
        |--------------------------------------------------------------------------
        */

        $query->withCount('fields');

        /*
        |--------------------------------------------------------------------------
        | 5. Search
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {

            $search = trim(
                $request->string('search')->toString()
            );

            if ($search !== '') {

                $searchTerm = "%{$search}%";

                Log::info('Revenue Services: Search initiated', [
                    'search' => $search,
                    'search_pattern' => $searchTerm,
                    'search_length' => strlen($search),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Diagnostic A: Matching Revenue Codes
                |--------------------------------------------------------------------------
                |
                | This query intentionally runs independently of the user's
                | workflow scope to determine whether the revenue code exists.
                |
                */

                $matchingRevenueCodes = DB::table('revenue_codes')
                    ->whereNull('deleted_at')
                    ->whereRaw(
                        'CAST(revenue_codes.code AS TEXT) ILIKE ?',
                        [$searchTerm]
                    )
                    ->get([
                        'id',
                        'code',
                        'name',
                        'category_id',
                    ]);

                Log::info('Revenue Services: Revenue code search results', [
                    'search' => $search,
                    'match_count' => $matchingRevenueCodes->count(),
                    'matching_codes' => $matchingRevenueCodes->toArray(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Diagnostic B: Associated Revenue Services
                |--------------------------------------------------------------------------
                |
                | This query intentionally ignores the user's workflow scope.
                | It helps distinguish missing services from access restrictions.
                |
                */

                $matchingServices = RevenueService::query()
                    ->whereHas(
                        'revenueCode',
                        function (Builder $codeQuery) use ($searchTerm) {

                            $codeQuery->whereRaw(
                                'CAST(revenue_codes.code AS TEXT) ILIKE ?',
                                [$searchTerm]
                            );
                        }
                    )
                    ->get([
                        'id',
                        'name',
                        'revenue_code_id',
                        'collection_mode',
                        'is_active',
                    ]);

                Log::info('Revenue Services: Services matching revenue code', [
                    'search' => $search,
                    'match_count' => $matchingServices->count(),
                    'matching_services' => $matchingServices->toArray(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Actual Search Query
                |--------------------------------------------------------------------------
                */

                $query->where(
                    function (Builder $inner) use ($searchTerm) {

                        // Revenue service name.
                        $inner->where(
                            'name',
                            'ILIKE',
                            $searchTerm
                        );

                        // Revenue code, code name, and category name.
                        $inner->orWhereHas(
                            'revenueCode',
                            function (Builder $codeQuery) use ($searchTerm) {

                                $codeQuery
                                    ->whereRaw(
                                        'CAST(revenue_codes.code AS TEXT) ILIKE ?',
                                        [$searchTerm]
                                    )
                                    ->orWhere(
                                        'name',
                                        'ILIKE',
                                        $searchTerm
                                    )
                                    ->orWhereHas(
                                        'category',
                                        function (Builder $categoryQuery) use ($searchTerm) {

                                            $categoryQuery->where(
                                                'name',
                                                'ILIKE',
                                                $searchTerm
                                            );
                                        }
                                    );
                            }
                        );
                    }
                );

                Log::info('Revenue Services: Search filters applied', [
                    'search' => $search,
                ]);

            } else {

                Log::info('Revenue Services: Empty search ignored');
            }

        } else {

            Log::info('Revenue Services: No search parameter provided');
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Revenue Domain
        |--------------------------------------------------------------------------
        */

        if ($request->filled('revenue_domain')) {

            $revenueDomain = $request
                ->string('revenue_domain')
                ->toString();

            Log::info('Revenue Services: Revenue domain filter', [
                'revenue_domain' => $revenueDomain,
            ]);

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

        /*
        |--------------------------------------------------------------------------
        | 7. Revenue Code UUID
        |--------------------------------------------------------------------------
        |
        | The code parameter represents the RevenueCode UUID, not its
        | human-readable code such as 1778.
        |
        */

        if ($request->filled('code')) {

            $codeId = $request->input('code');

            Log::info('Revenue Services: Revenue code UUID filter', [
                'code_id' => $codeId,
            ]);

            $query->whereHas(
                'revenueCode',
                function (Builder $codeQuery) use ($codeId) {

                    $codeQuery->whereKey($codeId);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Collection Mode
        |--------------------------------------------------------------------------
        */

        if ($request->filled('collection_mode')) {

            $collectionMode = $request
                ->string('collection_mode')
                ->toString();

            Log::info('Revenue Services: Collection mode filter', [
                'collection_mode' => $collectionMode,
            ]);

            $query->where(
                'collection_mode',
                $collectionMode
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Active Status
        |--------------------------------------------------------------------------
        */

        if ($request->has('is_active')) {

            $isActive = $request->boolean('is_active');

            Log::info('Revenue Services: Active status filter', [
                'is_active' => $isActive,
                'raw_value' => $request->input('is_active'),
            ]);

            $query->where(
                'is_active',
                $isActive
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Ordering
        |--------------------------------------------------------------------------
        */

        $query->latest();

        /*
        |--------------------------------------------------------------------------
        | 11. Pagination Configuration
        |--------------------------------------------------------------------------
        |
        | Supports both:
        |
        | Laravel:
        | ?page=1&per_page=20
        |
        | Flutter:
        | ?offset=0&limit=20
        |
        | If both formats are supplied, page/per_page take precedence.
        |
        */

        $perPage = $request->integer(
            'per_page',
            $request->integer('limit', 20)
        );

        $perPage = max(1, min($perPage, 100));

        if ($request->filled('page')) {

            $page = max(1, $request->integer('page', 1));

        } else {

            $offset = max(0, $request->integer('offset', 0));

            $page = (int) floor($offset / $perPage) + 1;
        }

        Log::info('Revenue Services: Pagination parameters resolved', [
            'requested_page' => $request->input('page'),
            'requested_per_page' => $request->input('per_page'),
            'requested_offset' => $request->input('offset'),
            'requested_limit' => $request->input('limit'),
            'resolved_page' => $page,
            'resolved_per_page' => $perPage,
            'resolved_offset' => ($page - 1) * $perPage,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 12. Generated SQL
        |--------------------------------------------------------------------------
        */

        Log::info('Revenue Services: Generated SQL', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'page' => $page,
            'per_page' => $perPage,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 13. Execute Pagination
        |--------------------------------------------------------------------------
        */

        $result = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $page
        )->withQueryString();

        /*
        |--------------------------------------------------------------------------
        | 14. Pagination Results
        |--------------------------------------------------------------------------
        */

        Log::info('Revenue Services: Pagination completed', [
            'user_id' => $user?->id,
            'search' => $request->input('search'),

            'total' => $result->total(),
            'count' => $result->count(),

            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
            'per_page' => $result->perPage(),

            'returned_services' => $result->getCollection()
                ->map(function ($service) {

                    return [
                        'id' => $service->id,
                        'name' => $service->name,

                        'revenue_code_id' => $service->revenue_code_id,
                        'revenue_code' => $service->revenueCode?->code,
                        'revenue_code_name' => $service->revenueCode?->name,

                        'category_name' => $service
                            ->revenueCode
                            ?->category
                            ?->name,

                        'collection_mode' => $service->collection_mode,
                        'is_active' => $service->is_active,
                        'fields_count' => $service->fields_count,
                    ];
                })
                ->values()
                ->toArray(),
        ]);

        return $result;

    } catch (\Throwable $exception) {

        /*
        |--------------------------------------------------------------------------
        | 15. Exception Logging
        |--------------------------------------------------------------------------
        */

        Log::error('Revenue Services: Pagination failed', [
            'user_id' => $user?->id,
            'query_parameters' => $request->query(),

            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        throw $exception;
    }
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
        | - only field-collection-capable services are returned
        |
        */
        if ($user->hasRole('REVENUE_COLLECTOR')) {

            return $query->whereIn(
                'collection_mode',
                [
                    'FIELD_COLLECTION',
                    'BOTH',
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
        | Validate Collection Configuration
        |--------------------------------------------------------------------------
        |
        | Multiple entries are only supported for services
        | that support field collection.
        |
        */

        $this->validateCollectionConfiguration(
            collectionMode: $data['collection_mode'],
            allowMultiple: (bool) ($data['allow_multiple'] ?? false),
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
        | Default Multiple Entry Configuration
        |--------------------------------------------------------------------------
        */

        $data['allow_multiple'] = $data['allow_multiple'] ?? false;

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
 * Service fields are synchronized only when `fields`
 * is explicitly present in the update data.
 */
public function update(
    RevenueService $service,
    array $data
): RevenueService {
    return DB::transaction(function () use ($service, $data) {

        /*
        |--------------------------------------------------------------------------
        | Prevent Updating Deleted Service
        |--------------------------------------------------------------------------
        */

        if ($service->trashed()) {
            throw ValidationException::withMessages([
                'service' => 'Deleted service cannot be updated.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Effective Collection Configuration
        |--------------------------------------------------------------------------
        |
        | Use incoming values when provided.
        | Otherwise, preserve existing database values.
        |
        */

        $this->validateCollectionConfiguration(
            collectionMode: $data['collection_mode']
                ?? $service->collection_mode,

            allowMultiple: (bool) (
                $data['allow_multiple']
                ?? $service->allow_multiple
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Revenue Code Changed
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists('revenue_code_id', $data) &&
            $data['revenue_code_id'] !== $service->revenue_code_id
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

        $hasFields = array_key_exists('fields', $data);

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
        |
        | allow_multiple is updated only when supplied.
        | If omitted, the existing database value is preserved.
        |
        */

        if (!empty($data)) {
            $service->update($data);
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
    });
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

    /**
     * Validate revenue service collection configuration.
     *
     * Multiple entries are only supported for services
     * that support field collection.
     */
    private function validateCollectionConfiguration(
        string $collectionMode,
        bool $allowMultiple
    ): void {
        if (
            $allowMultiple &&
            $collectionMode === 'ASSESSMENT_ONLY'
        ) {
            throw ValidationException::withMessages([
                'allow_multiple' =>
                    'Multiple collection entries can only be enabled for services that support field collection.',
            ]);
        }
    }
}