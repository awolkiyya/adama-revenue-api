<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use App\Models\User;
use App\Services\Authorization\SectorAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentQueryService
{
    /**
     * ============================================================
     * CONSTANTS
     * ============================================================
     */

    private const SOURCE_TYPES = [
        'NEW',
        'EXISTING_LIZZ',
    ];

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

            // Assessment administrative unit, independent of creator.
            'administrativeUnit.parent.parent',

            // Creator.
            'creator.administrativeUnit.parent.parent',
            'creator.sector',

            // Updater.
            'updater.administrativeUnit.parent.parent',
            'updater.sector',

            // Decision officer.
            'decisionOfficer.administrativeUnit.parent.parent',
            'decisionOfficer.sector',

            // Assessment services.
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
            $this->log(
                'warning',
                'Assessment query rejected: unauthenticated user.'
            );

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
     * Build the base assessment query with eager-loaded relationships.
     */
    protected function query(): Builder
    {
        $relations = $this->assessmentRelations();

        $this->log(
            'debug',
            'Building assessment query with eager-loaded relationships.',
            [
                'relations' => $relations,
            ]
        );

        return $this->authorizedQuery()->with($relations);
    }

    /**
     * ============================================================
     * DATE FILTER NORMALIZATION
     * ============================================================
     */

    /**
     * Read a date filter while supporting both snake_case and
     * camelCase request parameter names.
     */
    protected function getDateFilter(
        Request $request,
        string $snakeCase,
        string $camelCase
    ): ?string {
        $value = $request->input($snakeCase);

        if ($value === null) {
            $value = $request->input($camelCase);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Normalize and validate creation date filters.
     *
     * Expected format: YYYY-MM-DD
     *
     * These filters apply to assessments.created_at.
     *
     * @throws ValidationException
     */
    protected function normalizeDateFilters(Request $request): array
    {
        $dateFrom = $this->getDateFilter(
            $request,
            'date_from',
            'dateFrom'
        );

        $dateTo = $this->getDateFilter(
            $request,
            'date_to',
            'dateTo'
        );

        $validator = Validator::make(
            [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            [
                'date_from' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],
                'date_to' => [
                    'nullable',
                    'date_format:Y-m-d',
                    'after_or_equal:date_from',
                ],
            ],
            [
                'date_from.date_format' => 'The start date must use YYYY-MM-DD format.',
                'date_to.date_format' => 'The end date must use YYYY-MM-DD format.',
                'date_to.after_or_equal' => 'The end date must be on or after the start date.',
            ]
        );

        if ($validator->fails()) {
            $this->log(
                'warning',
                'Invalid assessment creation date filters supplied.',
                [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'validation_errors' => $validator->errors()->toArray(),
                ]
            );

            $validator->validate();
        }

        $normalized = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        $this->log(
            'debug',
            'Assessment creation date filters normalized successfully.',
            $normalized
        );

        return $normalized;
    }

    /**
     * ============================================================
     * SOURCE TYPE FILTER NORMALIZATION
     * ============================================================
     */

    /**
     * Normalize and validate assessment source type.
     *
     * Supported values:
     *
     * NEW
     * EXISTING_LIZZ
     *
     * If omitted, all source types are included.
     *
     * Supports both:
     * source_type
     * sourceType
     *
     * @throws ValidationException
     */
    protected function normalizeSourceTypeFilter(
        Request $request
    ): ?string {
        $sourceType = $request->input('source_type');

        if ($sourceType === null) {
            $sourceType = $request->input('sourceType');
        }

        if ($sourceType === null || $sourceType === '') {
            return null;
        }

        if (!is_string($sourceType)) {
            $this->log(
                'warning',
                'Invalid assessment source type supplied.',
                [
                    'source_type' => $sourceType,
                ]
            );

            throw ValidationException::withMessages([
                'source_type' => [
                    'The source type must be NEW or EXISTING_LIZZ.',
                ],
            ]);
        }

        $sourceType = strtoupper(trim($sourceType));

        $validator = Validator::make(
            [
                'source_type' => $sourceType,
            ],
            [
                'source_type' => [
                    'required',
                    'string',
                    'in:' . implode(',', self::SOURCE_TYPES),
                ],
            ],
            [
                'source_type.in' => 'The selected source type is invalid.',
            ]
        );

        if ($validator->fails()) {
            $this->log(
                'warning',
                'Assessment source type validation failed.',
                [
                    'source_type' => $sourceType,
                    'validation_errors' => $validator->errors()->toArray(),
                ]
            );

            $validator->validate();
        }

        return $sourceType;
    }

    /**
     * ============================================================
     * FILTERS
     * ============================================================
     */

    /**
     * Apply request filters.
     *
     * @param Builder $query
     * @param Request $request
     * @param bool $includeStatus Whether to apply pending/status filters.
     *
     * @return Builder
     */
    protected function applyAssessmentFilters(
        Builder $query,
        Request $request,
        bool $includeStatus = true
    ): Builder {
        $dates = $this->normalizeDateFilters($request);

        $sourceType = $this->normalizeSourceTypeFilter($request);

        $search = trim((string) $request->input('search', ''));

        $taxpayerId = $request->input('taxpayerId')
            ?? $request->input('taxpayer_id');

        $serviceId = $request->input('serviceId')
            ?? $request->input('service_id');

        $serviceCode = $request->input('serviceCode')
            ?? $request->input('service_code');

        $this->log(
            'debug',
            'Applying assessment request filters.',
            [
                'has_search' => filled($search),
                'has_taxpayer_filter' => filled($taxpayerId),
                'source_type' => $sourceType,
                'pending' => $request->boolean('pending'),
                'has_status_filter' => filled($request->input('status')),
                'include_status' => $includeStatus,
                'has_service_filter' => filled($serviceId),
                'has_service_code_filter' => filled($serviceCode),
                'date_from' => $dates['date_from'],
                'date_to' => $dates['date_to'],
                'date_filter_column' => 'assessments.created_at',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {
            $this->log(
                'debug',
                'Applying assessment search filter.',
                [
                    'search' => $search,
                ]
            );

            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where(
                        'assessments.assessment_number',
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

        if (filled($taxpayerId)) {
            $this->log(
                'debug',
                'Applying taxpayer filter.',
                [
                    'taxpayer_id' => $taxpayerId,
                ]
            );

            $query->where(
                'assessments.citizen_id',
                $taxpayerId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Source Type
        |--------------------------------------------------------------------------
        |
        | NEW:
        |   Only new assessments.
        |
        | EXISTING_LIZZ:
        |   Only assessments created from existing LIZZ agreements.
        |
        | Omitted:
        |   Both source types.
        |
        | This filter is applied to both pagination and summary.
        |
        */

        if ($sourceType !== null) {
            $this->log(
                'debug',
                'Applying assessment source type filter.',
                [
                    'column' => 'assessments.source_type',
                    'source_type' => $sourceType,
                ]
            );

            $query->where(
                'assessments.source_type',
                $sourceType
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pending / Status
        |--------------------------------------------------------------------------
        |
        | Status filters apply only to the paginated list.
        | Summary counts all statuses using the other active filters.
        |
        */

        if ($includeStatus) {
            if ($request->boolean('pending')) {
                $this->log(
                    'debug',
                    'Applying pending approval filter.'
                );

                $query->where(
                    'assessments.status',
                    'PENDING_APPROVAL'
                );

            } elseif (filled($request->input('status'))) {
                $status = $request->input('status');

                $this->log(
                    'debug',
                    'Applying assessment status filter.',
                    [
                        'status' => $status,
                    ]
                );

                $query->where(
                    'assessments.status',
                    $status
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Service
        |--------------------------------------------------------------------------
        */

        if (filled($serviceId)) {
            $this->log(
                'debug',
                'Applying assessment service filter.',
                [
                    'service_id' => $serviceId,
                ]
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

        if (filled($serviceCode)) {
            $this->log(
                'debug',
                'Applying assessment service code filter.',
                [
                    'service_code' => $serviceCode,
                ]
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
        | CREATED DATE
        |--------------------------------------------------------------------------
        |
        | Filter by when the assessment record was created.
        | Do NOT use assessment_date here.
        |
        | Both boundaries are inclusive.
        |
        */

        if ($dates['date_from'] !== null) {
            $this->log(
                'debug',
                'Applying assessment creation start date filter.',
                [
                    'column' => 'assessments.created_at',
                    'operator' => '>=',
                    'date_from' => $dates['date_from'],
                ]
            );

            $query->whereDate(
                'assessments.created_at',
                '>=',
                $dates['date_from']
            );
        }

        if ($dates['date_to'] !== null) {
            $this->log(
                'debug',
                'Applying assessment creation end date filter.',
                [
                    'column' => 'assessments.created_at',
                    'operator' => '<=',
                    'date_to' => $dates['date_to'],
                ]
            );

            $query->whereDate(
                'assessments.created_at',
                '<=',
                $dates['date_to']
            );
        }

        $this->log(
            'debug',
            'Assessment request filters applied successfully.',
            [
                'include_status' => $includeStatus,
                'source_type' => $sourceType,
                'date_from' => $dates['date_from'],
                'date_to' => $dates['date_to'],
                'date_filter_column' => 'assessments.created_at',
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]
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
            'Starting assessment pagination.',
            [
                'request_filters' => $request->only([
                    'search',
                    'status',
                    'pending',
                    'source_type',
                    'sourceType',
                    'taxpayerId',
                    'taxpayer_id',
                    'serviceId',
                    'service_id',
                    'serviceCode',
                    'service_code',
                    'date_from',
                    'date_to',
                    'dateFrom',
                    'dateTo',
                    'page',
                    'per_page',
                    'sort',
                    'direction',
                ]),
            ]
        );

        try {
            $query = $this->query();

            $this->applyAssessmentFilters(
                $query,
                $request,
                includeStatus: true
            );

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
                    [
                        'requested_sort' => $sort,
                    ]
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
                    [
                        'requested_direction' => $direction,
                    ]
                );

                $direction = 'desc';
            }

            $query->orderBy(
                'assessments.' . $sort,
                $direction
            );

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
     *
     * Uses the same search, taxpayer, source type, service and
     * creation-date filters as pagination, but intentionally
     * excludes status filtering.
     */
    public function summary(?Request $request = null): array
    {
        $startedAt = microtime(true);

        $request ??= request();

        $this->log(
            'info',
            'Starting assessment summary calculation.'
        );

        try {
            $query = $this->authorizedQuery();

            $this->applyAssessmentFilters(
                $query,
                $request,
                includeStatus: false
            );

            $this->log(
                'debug',
                'Executing assessment summary query.',
                [
                    'sql' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                ]
            );

            $result = $query
                ->selectRaw('COUNT(*) as total')
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE assessments.status = 'DRAFT') as draft"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE assessments.status = 'PENDING_APPROVAL') as pending_approval"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE assessments.status = 'RETURNED') as returned"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE assessments.status = 'APPROVED') as approved"
                )
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE assessments.status = 'CANCELLED') as cancelled"
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
                    'source_type' => $request->input('source_type')
                        ?? $request->input('sourceType'),
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
