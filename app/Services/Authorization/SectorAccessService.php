<?php

namespace App\Services\Authorization;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SectorAccessService
{
    public function __construct(
        private readonly AdministrativeScopeService $administrativeScope
    ) {}

    /**
     * Determine whether the user has global administrative access.
     */
    public function isGlobalUser(User $user): bool
    {
        return $this->administrativeScope->isGlobal($user);
    }

    /**
     * Return the user's assigned sector ID.
     *
     * Global users do not need a sector assignment.
     */
    public function sectorId(User $user): ?string
    {
        if ($this->isGlobalUser($user)) {
            return null;
        }

        return $user->sector_id
            ? (string) $user->sector_id
            : null;
    }

    /**
     * Determine whether the user has a sector assignment.
     */
    public function hasSector(User $user): bool
    {
        return $this->isGlobalUser($user)
            || $this->sectorId($user) !== null;
    }

    /**
     * Check whether a user can access a particular revenue service.
     *
     * Authorization requires an active, non-deleted access rule
     * connecting the service to the user's sector.
     */
    public function canAccessService(
        User $user,
        string $serviceId
    ): bool {
        if ($serviceId === '') {
            return false;
        }

        if ($this->isGlobalUser($user)) {
            return true;
        }

        $sectorId = $this->sectorId($user);

        if ($sectorId === null) {
            return false;
        }

        return $this->sectorCanAccessService(
            $sectorId,
            $serviceId
        );
    }

    /**
     * Check whether a sector has active access to a service.
     */
    public function sectorCanAccessService(
        string $sectorId,
        string $serviceId
    ): bool {
        if ($sectorId === '' || $serviceId === '') {
            return false;
        }

        return DB::table('service_access_rules')
            ->where('sector_id', $sectorId)
            ->where('service_id', $serviceId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Apply service-access filtering to a revenue_services query.
     *
     * Example:
     *
     * $query = $sectorAccess->scopeServicesForUser(
     *     RevenueService::query(),
     *     $user
     * );
     */
    public function scopeServicesForUser(
        Builder $query,
        User $user,
        string $serviceKey = 'revenue_services.id'
    ): Builder {
        $this->validateColumnName($serviceKey);

        if ($this->isGlobalUser($user)) {
            return $query;
        }

        $sectorId = $this->sectorId($user);

        if ($sectorId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(
            function ($subquery) use ($sectorId, $serviceKey) {
                $subquery
                    ->selectRaw('1')
                    ->from('service_access_rules')
                    ->whereColumn(
                        'service_access_rules.service_id',
                        $serviceKey
                    )
                    ->where(
                        'service_access_rules.sector_id',
                        $sectorId
                    )
                    ->where('service_access_rules.is_active', true)
                    ->whereNull('service_access_rules.deleted_at');
            }
        );
    }

    /**
     * Check whether at least one service on an assessment is
     * accessible to the user's sector.
     *
     * This does not guarantee that every service is accessible.
     */
    public function canAccessAssessment(
        User $user,
        Assessment $assessment
    ): bool {
        if ($this->isGlobalUser($user)) {
            return true;
        }

        if (!$this->administrativeScope->canAccessUnit(
            $user,
            $assessment->administrative_unit_id
                ? (string) $assessment->administrative_unit_id
                : null
        )) {
            return false;
        }

        $sectorId = $this->sectorId($user);

        if ($sectorId === null) {
            return false;
        }

        return DB::table('assessment_services')
            ->join(
                'service_access_rules',
                'service_access_rules.service_id',
                '=',
                'assessment_services.service_id'
            )
            ->where(
                'assessment_services.assessment_id',
                $assessment->getKey()
            )
            ->where(
                'service_access_rules.sector_id',
                $sectorId
            )
            ->where('service_access_rules.is_active', true)
            ->whereNull('service_access_rules.deleted_at')
            ->exists();
    }

    /**
     * Check whether every service on an assessment is accessible.
     *
     * Non-global users are denied access if the assessment has no
     * services or contains any service without an active access
     * rule for their sector.
     */
    public function canAccessEntireAssessment(
        User $user,
        Assessment $assessment
    ): bool {
        if ($this->isGlobalUser($user)) {
            return true;
        }

        if (!$this->administrativeScope->canAccessUnit(
            $user,
            $assessment->administrative_unit_id
                ? (string) $assessment->administrative_unit_id
                : null
        )) {
            return false;
        }

        $sectorId = $this->sectorId($user);

        if ($sectorId === null) {
            return false;
        }

        $servicesQuery = DB::table('assessment_services')
            ->where(
                'assessment_id',
                $assessment->getKey()
            );

        if (!(clone $servicesQuery)->exists()) {
            return false;
        }

        $hasInaccessibleService = (clone $servicesQuery)
            ->whereNotExists(
                function ($subquery) use ($sectorId) {
                    $subquery
                        ->selectRaw('1')
                        ->from('service_access_rules')
                        ->whereColumn(
                            'service_access_rules.service_id',
                            'assessment_services.service_id'
                        )
                        ->where(
                            'service_access_rules.sector_id',
                            $sectorId
                        )
                        ->where('service_access_rules.is_active', true)
                        ->whereNull('service_access_rules.deleted_at');
                }
            )
            ->exists();

        return !$hasInaccessibleService;
    }

    /**
     * Scope assessments to the user's administrative units and
     * service-access permissions.
     *
     * When $requireAllServices is true, every service on an
     * assessment must be accessible.
     *
     * Assumes assessments.id is the primary key and
     * assessment_services.assessment_id references it.
     */
    public function scopeAssessmentsForUser(
        Builder $query,
        User $user,
        bool $requireAllServices = true
    ): Builder {
        $query = $this->administrativeScope
            ->applyAdministrativeScope(
                $query,
                $user,
                'assessments.administrative_unit_id'
            );

        if ($this->isGlobalUser($user)) {
            return $query;
        }

        $sectorId = $this->sectorId($user);

        if ($sectorId === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($requireAllServices) {
            // Require at least one service.
            $query->whereExists(
                function ($subquery) {
                    $subquery
                        ->selectRaw('1')
                        ->from('assessment_services')
                        ->whereColumn(
                            'assessment_services.assessment_id',
                            'assessments.id'
                        );
                }
            );

            // Exclude assessments containing any inaccessible service.
            return $query->whereNotExists(
                function ($subquery) use ($sectorId) {
                    $subquery
                        ->selectRaw('1')
                        ->from('assessment_services')
                        ->whereColumn(
                            'assessment_services.assessment_id',
                            'assessments.id'
                        )
                        ->whereNotExists(
                            function ($accessQuery) use ($sectorId) {
                                $accessQuery
                                    ->selectRaw('1')
                                    ->from('service_access_rules')
                                    ->whereColumn(
                                        'service_access_rules.service_id',
                                        'assessment_services.service_id'
                                    )
                                    ->where(
                                        'service_access_rules.sector_id',
                                        $sectorId
                                    )
                                    ->where(
                                        'service_access_rules.is_active',
                                        true
                                    )
                                    ->whereNull(
                                        'service_access_rules.deleted_at'
                                    );
                            }
                        );
                }
            );
        }

        // Less restrictive mode: at least one service is accessible.
        return $query->whereExists(
            function ($subquery) use ($sectorId) {
                $subquery
                    ->selectRaw('1')
                    ->from('assessment_services')
                    ->join(
                        'service_access_rules',
                        'service_access_rules.service_id',
                        '=',
                        'assessment_services.service_id'
                    )
                    ->whereColumn(
                        'assessment_services.assessment_id',
                        'assessments.id'
                    )
                    ->where(
                        'service_access_rules.sector_id',
                        $sectorId
                    )
                    ->where('service_access_rules.is_active', true)
                    ->whereNull('service_access_rules.deleted_at');
            }
        );
    }

    /**
     * Abort with HTTP 403 when the user cannot access a service.
     */
    public function authorizeService(
        User $user,
        string $serviceId
    ): void {
        abort_unless(
            $this->canAccessService($user, $serviceId),
            403,
            'You are not authorized to access this revenue service.'
        );
    }

    /**
     * Abort with HTTP 403 when the user cannot access an assessment.
     */
    public function authorizeAssessment(
        User $user,
        Assessment $assessment,
        bool $requireAllServices = true
    ): void {
        $allowed = $requireAllServices
            ? $this->canAccessEntireAssessment($user, $assessment)
            : $this->canAccessAssessment($user, $assessment);

        abort_unless(
            $allowed,
            403,
            'You are not authorized to access this assessment.'
        );
    }

    /**
     * Validate a SQL column identifier before using it in a query.
     */
    private function validateColumnName(string $column): void
    {
        if (!preg_match(
            '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/',
            $column
        )) {
            throw new InvalidArgumentException(
                'Invalid SQL column identifier.'
            );
        }
    }
}