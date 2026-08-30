<?php

namespace App\Modules\Audit\Services;

use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SystemLogService
{
    /**
     * ============================================================
     * PAGINATE AUDIT LOGS
     * ============================================================
     *
     * Returns filtered and paginated system audit logs.
     *
     * Authorization is intentionally NOT handled here.
     * SystemLogPolicy is responsible for authorization.
     *
     * Expected filters:
     *
     * - search
     * - action
     * - module
     * - user_id
     * - resource_type
     * - resource_id
     * - request_id
     * - ip_address
     * - from
     * - to
     * - sort_by
     * - sort_direction
     * - per_page
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = min(
            max(
                (int) ($filters['per_page'] ?? 20),
                1
            ),
            100
        );

        /*
        |--------------------------------------------------------------------------
        | Base Query
        |--------------------------------------------------------------------------
        */

        $query = SystemLog::query()
            ->with('user');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['search']),
            function ($query) use ($filters) {

                $search = trim(
                    (string) $filters['search']
                );

                $query->where(function ($q) use ($search) {

                    $q->where(
                        'description',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'action',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'module',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'resource_type',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'resource_id',
                        'ILIKE',
                        "%{$search}%"
                    );
                });
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Action
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['action']),
            fn ($query) =>
                $query->where(
                    'action',
                    $filters['action']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | Module
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['module']),
            fn ($query) =>
                $query->where(
                    'module',
                    $filters['module']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | User
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['user_id']),
            fn ($query) =>
                $query->where(
                    'user_id',
                    $filters['user_id']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | Resource Type
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['resource_type']),
            fn ($query) =>
                $query->where(
                    'resource_type',
                    $filters['resource_type']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | Resource ID
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['resource_id']),
            fn ($query) =>
                $query->where(
                    'resource_id',
                    $filters['resource_id']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | Request ID
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['request_id']),
            fn ($query) =>
                $query->where(
                    'request_id',
                    $filters['request_id']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | IP Address
        |--------------------------------------------------------------------------
        */

        $query->when(
            !empty($filters['ip_address']),
            fn ($query) =>
                $query->where(
                    'ip_address',
                    $filters['ip_address']
                )
        );

        /*
        |--------------------------------------------------------------------------
        | FROM DATE
        |--------------------------------------------------------------------------
        |
        | If the frontend sends:
        |
        |     2026-08-30
        |
        | treat it as:
        |
        |     2026-08-30 00:00:00
        |
        */

        $query->when(
            !empty($filters['from']),
            function ($query) use ($filters) {

                $from = Carbon::parse(
                    $filters['from']
                )->startOfDay();

                $query->where(
                    'created_at',
                    '>=',
                    $from
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | TO DATE
        |--------------------------------------------------------------------------
        |
        | Important:
        |
        | 2026-08-30
        |
        | becomes:
        |
        | 2026-08-30 23:59:59
        |
        | so the entire selected day is included.
        |
        */

        $query->when(
            !empty($filters['to']),
            function ($query) use ($filters) {

                $to = Carbon::parse(
                    $filters['to']
                )->endOfDay();

                $query->where(
                    'created_at',
                    '<=',
                    $to
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | SORTING
        |--------------------------------------------------------------------------
        |
        | The request already validates sort_by and sort_direction.
        |
        */

        $sortBy = $filters['sort_by']
            ?? 'created_at';

        $sortDirection = $filters['sort_direction']
            ?? 'desc';

        $query->orderBy(
            $sortBy,
            $sortDirection
        );

        /*
        |--------------------------------------------------------------------------
        | Secondary Sorting
        |--------------------------------------------------------------------------
        |
        | Gives deterministic ordering when multiple logs have the
        | same created_at timestamp.
        |
        */

        if ($sortBy !== 'id') {
            $query->orderBy(
                'id',
                'desc'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        return $query->paginate(
            $perPage
        );
    }

    /**
     * ============================================================
     * FIND AUDIT LOG
     * ============================================================
     *
     * Returns a single audit log with its actor.
     *
     * Authorization is handled by SystemLogPolicy.
     */
    public function findById(string $id): SystemLog
    {
        return SystemLog::query()
            ->with('user')
            ->findOrFail($id);
    }
}