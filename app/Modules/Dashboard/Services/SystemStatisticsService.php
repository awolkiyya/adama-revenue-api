<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Models\Assessment;
use App\Models\Citizen;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Authorization\AdministrativeScopeService;
use Illuminate\Database\Eloquent\Builder;

final class SystemStatisticsService
{
    private const REVENUE_COLLECTOR_ROLE = 'REVENUE_COLLECTOR';

    private const API_GUARD = 'api';

    private const COUNTABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'PAID',
        'OVERDUE',
    ];

    public function __construct(
        private readonly AdministrativeScopeService $administrativeScope,
    ) {}

    /**
     * Return record counts restricted to the user's accessible
     * administrative units.
     *
     * System administrators retain global access through
     * AdministrativeScopeService.
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
    public function summarize(User $user): array
    {
        return [
            'totalUsers' => $this->countUsers($user),

            'totalTaxpayers' => $this->countTaxpayers($user),

            'totalAgents' => $this->countRevenueCollectors($user),

            'totalAssessments' => $this->countAssessments($user),

            'totalAssessmentInvoices' => $this->countInvoices(
                user: $user,
                sourceType: 'ASSESSMENT',
            ),

            'totalDirectCollectionInvoices' => $this->countInvoices(
                user: $user,
                sourceType: 'DIRECT_COLLECTION',
            ),
        ];
    }

    /**
     * Count users assigned to accessible administrative units.
     *
     * The users table does not use soft deletes.
     */
    private function countUsers(User $user): int
    {
        $query = User::query();

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'users.administrative_unit_id',
        );

        return $query->count();
    }

    /**
     * Count citizens within accessible administrative units.
     */
    private function countTaxpayers(User $user): int
    {
        $query = Citizen::query();

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'citizens.administrative_unit_id',
        );

        return $query->count();
    }

    /**
     * Count revenue collectors assigned to accessible administrative
     * units and the API guard.
     */
    private function countRevenueCollectors(User $user): int
    {
        $query = User::query()
            ->whereHas(
                'roles',
                function (Builder $roleQuery): void {
                    $roleQuery
                        ->where('roles.name', self::REVENUE_COLLECTOR_ROLE)
                        ->where('roles.guard_name', self::API_GUARD);
                },
            );

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'users.administrative_unit_id',
        );

        return $query->count();
    }

    /**
     * Count assessments within accessible administrative units.
     */
    private function countAssessments(User $user): int
    {
        $query = Assessment::query();

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'assessments.administrative_unit_id',
        );

        return $query->count();
    }

    /**
     * Count eligible invoices of a given source type within
     * accessible administrative units.
     */
    private function countInvoices(
        User $user,
        string $sourceType,
    ): int {
        $query = Invoice::query()
            ->where('invoices.source_type', $sourceType)
            ->whereIn(
                'invoices.status',
                self::COUNTABLE_INVOICE_STATUSES,
            );

        $this->administrativeScope->applyAdministrativeScope(
            $query,
            $user,
            'invoices.administrative_unit_id',
        );

        return $query->count();
    }
}