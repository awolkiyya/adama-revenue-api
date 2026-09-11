<?php

namespace App\Policies;

use App\Models\RevenueService;
use App\Models\ServiceAccessRule;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;
use Illuminate\Support\Facades\Log;

class RevenueServicePolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY REVENUE SERVICES
     * ============================================================
     *
     * Controls access to the revenue service collection endpoint.
     *
     * Controller:
     *     index()
     *
     * Permission:
     *     revenue_services.read
     *
     * A non-system user must:
     *
     *     1. Have revenue_services.read
     *     2. Belong to a sector
     *     3. Have at least one active service access rule
     *
     * IMPORTANT:
     *
     * This method only determines whether the user can access
     * the collection endpoint.
     *
     * It does NOT determine which services are returned.
     *
     * The query/service layer must additionally filter the
     * revenue services using the user's sector and active
     * service_access_rules.
     */
    public function viewAny(User $user): bool
    {
        $allowed =
            $this->hasPermission(
                $user,
                'revenue_services.read'
            )
            && $this->hasServiceAccess($user);

        return $this->logAuthorization(
            $user,
            'revenue_services.read',
            'viewAny',
            $allowed
        );
    }

    /**
     * ============================================================
     * VIEW REVENUE SERVICE
     * ============================================================
     *
     * Controls access to a specific revenue service.
     *
     * Controller:
     *     show()
     *
     * Permission:
     *     revenue_services.read
     *
     * A non-system user must have an active access rule for
     * the specific service and their sector.
     */
    public function view(
        User $user,
        RevenueService $service
    ): bool {
        $allowed =
            $this->hasPermission(
                $user,
                'revenue_services.read'
            )
            && $this->hasServiceAccess(
                $user,
                $service
            );

        return $this->logAuthorization(
            $user,
            'revenue_services.read',
            'view',
            $allowed,
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * CREATE REVENUE SERVICE
     * ============================================================
     *
     * Permission:
     *     revenue_services.create
     *
     * Service access is not checked because the service does not
     * exist yet.
     */
    public function create(User $user): bool
    {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.create',
            'create'
        );
    }

    /**
     * ============================================================
     * UPDATE REVENUE SERVICE
     * ============================================================
     *
     * Permission:
     *     revenue_services.update
     *
     * This is a management operation.
     *
     * It does not depend on the user's sector-level service
     * access because service configuration is administrative.
     */
    public function update(
        User $user,
        RevenueService $service
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.update',
            'update',
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * DELETE REVENUE SERVICE
     * ============================================================
     *
     * Permission:
     *     revenue_services.delete
     *
     * If deletion is not supported by the application, this
     * ability should be removed and deactivation should be used.
     */
    public function delete(
        User $user,
        RevenueService $service
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.delete',
            'delete',
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * ACTIVATE REVENUE SERVICE
     * ============================================================
     *
     * Permission:
     *     revenue_services.activate
     *
     * Management operation.
     *
     * Sector-level service access is not checked here.
     */
    public function activate(
        User $user,
        RevenueService $service
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.activate',
            'activate',
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * DEACTIVATE REVENUE SERVICE
     * ============================================================
     *
     * Permission:
     *     revenue_services.deactivate
     *
     * Management operation.
     *
     * Sector-level service access is not checked here.
     */
    public function deactivate(
        User $user,
        RevenueService $service
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.deactivate',
            'deactivate',
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * VIEW REVENUE SERVICE HISTORY
     * ============================================================
     *
     * Permission:
     *     revenue_services.view_history
     *
     * History is considered read access to a specific revenue
     * service, therefore the user's service access is checked.
     */
    public function viewHistory(
        User $user,
        RevenueService $service
    ): bool {
        $allowed =
            $this->hasPermission(
                $user,
                'revenue_services.view_history'
            )
            && $this->hasServiceAccess(
                $user,
                $service
            );

        return $this->logAuthorization(
            $user,
            'revenue_services.view_history',
            'viewHistory',
            $allowed,
            [
                'resource_id' => $service->id,
            ]
        );
    }

    /**
     * ============================================================
     * SERVICE ACCESS CHECK
     * ============================================================
     *
     * Determines whether a user has access to revenue services
     * through service_access_rules.
     *
     * Two modes are supported.
     *
     * ------------------------------------------------------------
     * MODE 1: COLLECTION ACCESS
     * ------------------------------------------------------------
     *
     *     hasServiceAccess($user)
     *
     * Used by:
     *
     *     viewAny()
     *
     * Checks whether the user's sector has at least one active
     * service access rule.
     *
     * ------------------------------------------------------------
     * MODE 2: SPECIFIC SERVICE ACCESS
     * ------------------------------------------------------------
     *
     *     hasServiceAccess($user, $service)
     *
     * Used by:
     *
     *     view()
     *     viewHistory()
     *
     * Checks whether the user's sector has an active access rule
     * for the specific revenue service.
     *
     * ------------------------------------------------------------
     * ACCESS MODEL
     * ------------------------------------------------------------
     *
     * SYSTEM_ADMIN
     *     → ALLOWED
     *
     * User has no sector
     *     → DENIED
     *
     * Sector has no active service rule
     *     → DENIED
     *
     * Sector has active service rule
     *     → ALLOWED
     */
    private function hasServiceAccess(
        User $user,
        ?RevenueService $service = null
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | SYSTEM ADMINISTRATOR
        |--------------------------------------------------------------------------
        |
        | System administrators have unrestricted access to revenue
        | services and therefore bypass sector-level access rules.
        |
        */

        if ($user->hasRole('SYSTEM_ADMIN')) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | SECTOR REQUIRED
        |--------------------------------------------------------------------------
        |
        | A non-system user must belong to a sector because service
        | access rules are defined using sector_id.
        |
        */

        if (! $user->sector_id) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | SPECIFIC SERVICE
        |--------------------------------------------------------------------------
        |
        | Used by:
        *
        *     view()
        *     viewHistory()
        |
        | The user's sector must have an active rule for this
        | specific revenue service.
        |
        */

        if ($service !== null) {
            return ServiceAccessRule::query()
                ->where(
                    'service_id',
                    $service->id
                )
                ->where(
                    'sector_id',
                    $user->sector_id
                )
                ->where(
                    'is_active',
                    true
                )
                ->exists();
        }

        /*
        |--------------------------------------------------------------------------
        | ANY SERVICE
        |--------------------------------------------------------------------------
        |
        | Used by viewAny().
        |
        | The user only needs at least one active service access
        | rule to be allowed into the service collection endpoint.
        |
        | IMPORTANT:
        |
        | This does NOT mean the user can see every service.
        |
        | The RevenueService query must separately filter the
        | collection to only services accessible to this sector.
        |
        */

        return ServiceAccessRule::query()
            ->where(
                'sector_id',
                $user->sector_id
            )
            ->where(
                'is_active',
                true
            )
            ->exists();
    }

    /**
     * ============================================================
     * AUTHORIZATION + LOGGING
     * ============================================================
     *
     * Checks a permission and records the authorization decision.
     */
    private function authorizeWithLog(
        User $user,
        string $permission,
        string $ability,
        array $context = []
    ): bool {
        $allowed = $this->hasPermission(
            $user,
            $permission
        );

        return $this->logAuthorization(
            $user,
            $permission,
            $ability,
            $allowed,
            $context
        );
    }

    /**
     * ============================================================
     * LOG AUTHORIZATION
     * ============================================================
     *
     * Centralized authorization logging.
     */
    private function logAuthorization(
        User $user,
        string $permission,
        string $ability,
        bool $allowed,
        array $context = []
    ): bool {
        Log::info(
            'Revenue service policy authorization check.',
            array_merge(
                [
                    'user_id' =>
                        $user->id,

                    'ability' =>
                        $ability,

                    'permission' =>
                        $permission,

                    'authorized' =>
                        $allowed,

                    'ip_address' =>
                        request()->ip(),

                    'route' =>
                        request()->route()?->getName(),

                    'method' =>
                        request()->method(),
                ],
                $context
            )
        );

        return $allowed;
    }
}