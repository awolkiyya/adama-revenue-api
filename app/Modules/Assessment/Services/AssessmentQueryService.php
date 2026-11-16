<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\User;
use App\Services\Authorization\SectorAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssessmentQueryService
{
    /**
     * ============================================================
     * DEPENDENCIES
     * ============================================================
     */

    public function __construct(
        private readonly SectorAccessService $sectorAccess
    ) {}

    /**
     * ============================================================
     * LOGGING
     * ============================================================
     */

    /**
     * Log assessment query operations with consistent context.
     */
    protected function log(
        string $level,
        string $message,
        array $context = []
    ): void {
        Log::log($level, $message, array_merge([
            'module' => 'Assessment',
            'service' => self::class,
            'timestamp' => now()->toDateTimeString(),
            'request_id' => request()->header('X-Request-ID'),
            'user_id' => request()->user()?->getAuthIdentifier(),
        ], $context));
    }

    /**
     * Get safe user authorization context.
     */
    protected function userContext(User $user): array
    {
        return [
            'user_id' => $user->getAuthIdentifier(),
            'administrative_unit_id' => $user->administrative_unit_id,
            'sector_id' => $user->sector_id,
            'is_global_user' => $this->sectorAccess->isGlobalUser($user),
        ];
    }

    /**
     * ============================================================
     * RELATIONS
     * ============================================================
     */

    /**
     * Relations required by assessment resources.
     */
    protected function assessmentRelations(): array
    {
        return [
            'taxpayer',
            'creator',
            'updater',
            'decisionOfficer',
            'services',
            'services.service',
            'services.values',
            'services.values.files',
            'services.service.revenueCode.paymentScheduleRule',
        ];
    }

    /**
     * ============================================================
     * AUTHORIZATION / BASE QUERY
     * ============================================================
     */

    /**
     * Build an assessment query restricted to the authenticated
     * user's administrative and sector permissions.
     */
    protected function authorizedQuery(): Builder
    {
        $user = request()->user();

        if (!$user instanceof User) {
            $this->log('warning', 'Assessment query rejected: unauthenticated user.');

            abort(401, 'Unauthenticated.');
        }

        $context = $this->userContext($user);

        $this->log(
            'info',
            'Building authorized assessment query.',
            $context
        );

        try {
            $query = $this->sectorAccess->scopeAssessmentsForUser(
                Assessment::query(),
                $user,
                requireAllServices: true
            );

            $this->log(
                'info',
                'Assessment authorization scope applied successfully.',
                array_merge($context, [
                    'require_all_services' => true,
                    'authorization_scope' => $this->sectorAccess->isGlobalUser($user)
                        ? 'global'
                        : 'administrative_unit_and_sector',
                ])
            );

            return $query;

        } catch (Throwable $e) {
            $this->log(
                'error',
                'Failed to apply assessment authorization scope.',
                array_merge($context, [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ])
            );

            throw $e;
        }
    }

    /**
     * Build the base assessment query with authorization
     * and eager-loaded relationships.
     */
    protected function query(): Builder
    {
        $this->log(
            'debug',
            'Building assessment query with eager-loaded relationships.',
            [
                'relations' => $this->assessmentRelations(),
            ]
        );

        return $this->authorizedQuery()
            ->with($this->assessmentRelations());
    }

    /**
     * ============================================================
     * FILTERS
     * ============================================================
     */

    /**
     * Apply request filters.
     */
    protected function applyAssessmentFilters(
        Builder $query,
        Request $request
    ): Builder {

        $this->log(
            'debug',
            'Applying assessment request filters.',
            [
                'has_search' => filled($request->input('search')),
                'has_taxpayer_filter' => filled($request->input('taxpayerId')),
                'pending' => $request->boolean('pending'),
                'has_status_filter' => filled($request->input('status')),
                'has_service_filter' => filled($request->input('serviceId')),
                'has_service_code_filter' => filled($request->input('serviceCode')),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search = trim((string) $request->input('search'))) {

            $this->log('debug', 'Applying assessment search filter.');

            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where(
                        'assessment_number',
                        'ilike',
                        "%{$search}%"
                    )
                    ->orWhereHas(
                        'taxpayer',
                        function (Builder $taxpayerQuery) use ($search) {
                            $taxpayerQuery
                                ->where('full_name', 'ilike', "%{$search}%")
                                ->orWhere('national_id', 'ilike', "%{$search}%");
                        }
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Taxpayer
        |--------------------------------------------------------------------------
        */

        if ($taxpayerId = $request->input('taxpayerId')) {

            $this->log('debug', 'Applying taxpayer filter.');

            $query->where('citizen_id', $taxpayerId);
        }

        /*
        |--------------------------------------------------------------------------
        | Pending / Status
        |--------------------------------------------------------------------------
        */

        if ($request->boolean('pending')) {

            $this->log(
                'debug',
                'Applying pending approval filter.'
            );

            $query->where('status', 'PENDING_APPROVAL');

        } elseif ($status = $request->input('status')) {

            $this->log(
                'debug',
                'Applying assessment status filter.',
                ['status' => $status]
            );

            $query->where('status', $status);
        }

        /*
        |--------------------------------------------------------------------------
        | Service
        |--------------------------------------------------------------------------
        */

        if ($serviceId = $request->input('serviceId')) {

            $this->log(
                'debug',
                'Applying assessment service filter.',
                ['service_id' => $serviceId]
            );

            $query->whereHas(
                'services',
                function (Builder $serviceQuery) use ($serviceId) {
                    $serviceQuery->where('service_id', $serviceId);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Service Code
        |--------------------------------------------------------------------------
        */

        if ($serviceCode = $request->input('serviceCode')) {

            $this->log(
                'debug',
                'Applying assessment service code filter.',
                ['service_code' => $serviceCode]
            );

            $query->whereHas(
                'services',
                function (Builder $serviceQuery) use ($serviceCode) {
                    $serviceQuery->where('service_code', $serviceCode);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assessment Date
        |--------------------------------------------------------------------------
        */

        if ($dateFrom = $request->input('date_from')) {

            $this->log(
                'debug',
                'Applying assessment start date filter.',
                ['date_from' => $dateFrom]
            );

            $query->whereDate('assessment_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {

            $this->log(
                'debug',
                'Applying assessment end date filter.',
                ['date_to' => $dateTo]
            );

            $query->whereDate('assessment_date', '<=', $dateTo);
        }

        $this->log(
            'debug',
            'Assessment request filters applied successfully.'
        );

        return $query;
    }

    /**
     * ============================================================
     * PAGINATION
     * ============================================================
     */

    /**
     * Paginate authorized assessments.
     */
    public function paginate(): LengthAwarePaginator
    {
        $startedAt = microtime(true);

        $request = request();

        $this->log(
            'info',
            'Starting assessment pagination.'
        );

        try {

            $query = $this->query();

            $this->applyAssessmentFilters($query, $request);

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */

            $allowedSorts = [
                'created_at',
                'assessment_date',
                'assessment_number',
                'status',
            ];

            $sort = $request->input('sort', 'created_at');

            if (!in_array($sort, $allowedSorts, true)) {
                $this->log(
                    'warning',
                    'Invalid assessment sort column supplied. Using default.',
                    ['requested_sort' => $sort]
                );

                $sort = 'created_at';
            }

            $direction = strtolower(
                (string) $request->input('direction', 'desc')
            );

            if (!in_array($direction, ['asc', 'desc'], true)) {
                $this->log(
                    'warning',
                    'Invalid assessment sort direction supplied. Using default.',
                    ['requested_direction' => $direction]
                );

                $direction = 'desc';
            }

            $query->orderBy($sort, $direction);

            $this->log(
                'debug',
                'Assessment sorting applied.',
                [
                    'sort' => $sort,
                    'direction' => $direction,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $perPage = (int) $request->input('per_page', 15);

            $perPage = max(1, min($perPage, 100));

            $result = $query->paginate($perPage);

            $duration = round(
                (microtime(true) - $startedAt) * 1000,
                2
            );

            $this->log(
                'info',
                'Assessment pagination completed successfully.',
                [
                    'total' => $result->total(),
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'returned_records' => $result->count(),
                    'duration_ms' => $duration,
                ]
            );

            return $result;

        } catch (Throwable $e) {

            $this->log(
                'error',
                'Assessment pagination failed.',
                [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            throw $e;
        }
    }

    /**
     * ============================================================
     * SUMMARY
     * ============================================================
     */

    /**
     * Get authorized assessment status summary.
     */
    public function summary(): array
    {
        $startedAt = microtime(true);

        $this->log(
            'info',
            'Starting assessment summary calculation.'
        );

        try {

            $query = $this->authorizedQuery();

            $result = $query
                ->selectRaw('COUNT(*) as total')
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE status = 'DRAFT') as draft"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE status = 'PENDING_APPROVAL') as pending_approval"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE status = 'RETURNED') as returned"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE status = 'APPROVED') as approved"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE status = 'CANCELLED') as cancelled"
                )
                ->first();

            $summary = [
                'total' => (int) ($result->total ?? 0),
                'draft' => (int) ($result->draft ?? 0),
                'pending_approval' => (int) ($result->pending_approval ?? 0),
                'returned' => (int) ($result->returned ?? 0),
                'approved' => (int) ($result->approved ?? 0),
                'cancelled' => (int) ($result->cancelled ?? 0),
            ];

            $this->log(
                'info',
                'Assessment summary calculated successfully.',
                array_merge($summary, [
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ])
            );

            return $summary;

        } catch (Throwable $e) {

            $this->log(
                'error',
                'Assessment summary calculation failed.',
                [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            throw $e;
        }
    }

    /**
     * ============================================================
     * FIND
     * ============================================================
     */

    /**
     * Find an authorized assessment or throw a 404 exception.
     */
    public function findOrFail(string $id): Assessment
    {
        $startedAt = microtime(true);

        $this->log(
            'info',
            'Starting assessment retrieval.',
            [
                'assessment_id' => $id,
            ]
        );

        try {

            $assessment = $this->query()->findOrFail($id);

            $this->log(
                'info',
                'Assessment retrieved successfully.',
                [
                    'assessment_id' => $assessment->getKey(),
                    'assessment_number' => $assessment->assessment_number,
                    'status' => $assessment->status,
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            return $assessment;

        } catch (Throwable $e) {

            $this->log(
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    ? 'warning'
                    : 'error',
                'Assessment retrieval failed.',
                [
                    'assessment_id' => $id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => round(
                        (microtime(true) - $startedAt) * 1000,
                        2
                    ),
                ]
            );

            throw $e;
        }
    }
}