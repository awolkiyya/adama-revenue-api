<?php

namespace App\Modules\Dashboard\Services;

use Illuminate\Support\Facades\DB;

class SystemStatisticsService
{
    private const REVENUE_COLLECTOR_ROLE = 'AGENT';

    private const API_GUARD = 'api';

    private const USER_MODEL = 'App\\Models\\User';

    private const INVOICE_SOURCE_ASSESSMENT = 'ASSESSMENT';

    private const INVOICE_SOURCE_DIRECT = 'DIRECT_COLLECTION';

    private const COUNTABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'PAID',
        'OVERDUE',
    ];

    /**
     * Return system-wide record counts.
     *
     * These figures are independent of the revenue reporting period.
     *
     * @return array{
     *     totalUsers: int,
     *     totalTaxpayers: int,
     *     totalAgents: int,
     *     totalAssessments: int,
     *     totalAssessmentInvoices: int,
     *     totalDirectCollectionInvoices: int
     * }
     */
    public function summarize(): array
    {
        return [
            'totalUsers' => $this->countUsers(),

            // Taxpayer records are stored in the citizens table.
            'totalTaxpayers' => $this->countRecords(
                'citizens',
                hasSoftDeletes: true,
            ),

            'totalAgents' => $this->countRevenueCollectors(),

            'totalAssessments' => $this->countRecords(
                'assessments',
                hasSoftDeletes: true,
            ),

            'totalAssessmentInvoices' => $this->countInvoices(
                self::INVOICE_SOURCE_ASSESSMENT,
            ),

            'totalDirectCollectionInvoices' => $this->countInvoices(
                self::INVOICE_SOURCE_DIRECT,
            ),
        ];
    }

    /**
     * Count users.
     *
     * The users table does not have a deleted_at column.
     */
    private function countUsers(): int
    {
        return (int) DB::table('users')->count();
    }

    /**
     * Count records, optionally excluding soft-deleted rows.
     *
     * Table names are internal constants supplied by this service,
     * never directly from a request.
     */
    private function countRecords(
        string $table,
        bool $hasSoftDeletes,
    ): int {
        $query = DB::table($table);

        if ($hasSoftDeletes) {
            $query->whereNull($table . '.deleted_at');
        }

        return (int) $query->count();
    }

    /**
     * Count users assigned the REVENUE_COLLECTOR role.
     *
     * Uses the application's UUID-based Spatie pivot column,
     * model_uuid, and the API permission guard.
     */
    private function countRevenueCollectors(): int
    {
        return (int) DB::table('users')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('model_has_roles')
                    ->join(
                        'roles',
                        'roles.id',
                        '=',
                        'model_has_roles.role_id',
                    )
                    ->whereColumn(
                        'model_has_roles.model_uuid',
                        'users.id',
                    )
                    ->where(
                        'model_has_roles.model_type',
                        self::USER_MODEL,
                    )
                    ->where(
                        'roles.name',
                        self::REVENUE_COLLECTOR_ROLE,
                    )
                    ->where(
                        'roles.guard_name',
                        self::API_GUARD,
                    );
            })
            ->count('users.id');
    }

    /**
     * Count issued invoices, excluding draft, cancelled, and void invoices.
     */
    private function countInvoices(string $sourceType): int
    {
        return (int) DB::table('invoices')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.source_type', $sourceType)
            ->whereIn(
                'invoices.status',
                self::COUNTABLE_INVOICE_STATUSES,
            )
            ->count();
    }
}