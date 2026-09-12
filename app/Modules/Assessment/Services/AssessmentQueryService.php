<?php

namespace App\Modules\Assessment\Services;

use App\Models\Assessment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AssessmentQueryService
{
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
        ];
    }

    /**
     * ============================================================
     * QUERY
     * ============================================================
     */

    /**
     * Build the base assessment query.
     */
    protected function query(): Builder
    {
        return Assessment::query()
            ->with($this->assessmentRelations());
    }

    /**
     * Apply request filters.
     */
    protected function applyAssessmentFilters(
        Builder $query,
        Request $request
    ): Builder {
        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where('assessment_number', 'ilike', "%{$search}%")
                    ->orWhereHas('taxpayer', function (Builder $taxpayerQuery) use ($search) {
                        $taxpayerQuery
                            ->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('national_id', 'ilike', "%{$search}%");
                    });
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Taxpayer
        |--------------------------------------------------------------------------
        */

        if ($taxpayerId = $request->input('taxpayerId')) {
            $query->where('citizen_id', $taxpayerId);
        }

        /*
        |--------------------------------------------------------------------------
        | Pending
        |--------------------------------------------------------------------------
        |
        | `pending=true` intentionally overrides the normal status filter.
        |
        */

        if ($request->boolean('pending')) {
            $query->where('status', 'PENDING_APPROVAL');
        } elseif ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        /*
        |--------------------------------------------------------------------------
        | Service
        |--------------------------------------------------------------------------
        */

        if ($serviceId = $request->input('serviceId')) {
            $query->whereHas('services', function (Builder $serviceQuery) use ($serviceId) {
                $serviceQuery->where('service_id', $serviceId);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Service Code
        |--------------------------------------------------------------------------
        */

        if ($serviceCode = $request->input('serviceCode')) {
            $query->whereHas('services', function (Builder $serviceQuery) use ($serviceCode) {
                $serviceQuery->where('service_code', $serviceCode);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Assessment Date
        |--------------------------------------------------------------------------
        */

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('assessment_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('assessment_date', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * ============================================================
     * PAGINATION
     * ============================================================
     */

    /**
     * Paginate assessments using request filters and sorting.
     */
    public function paginate(): LengthAwarePaginator
    {
        $request = request();

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

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $direction = strtolower(
            (string) $request->input('direction', 'desc')
        );

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $query->orderBy($sort, $direction);

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) $request->input('per_page', 15);

        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }

    /**
     * ============================================================
     * SUMMARY
     * ============================================================
     */

    /**
     * Get assessment status summary.
     */
    public function summary(): array
    {
        $query = Assessment::query();

        $result = $query
            ->selectRaw(
                'COUNT(*) as total'
            )
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

        return [
            'total' => (int) ($result->total ?? 0),
            'draft' => (int) ($result->draft ?? 0),
            'pending_approval' => (int) ($result->pending_approval ?? 0),
            'returned' => (int) ($result->returned ?? 0),
            'approved' => (int) ($result->approved ?? 0),
            'cancelled' => (int) ($result->cancelled ?? 0),
        ];
    }

    /**
     * ============================================================
     * FIND
     * ============================================================
     */

    /**
     * Find an assessment or throw a 404 exception.
     */
    public function findOrFail(string $id): Assessment
    {
        return $this->query()->findOrFail($id);
    }
}