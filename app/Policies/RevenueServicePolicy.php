<?php

namespace App\Policies;

use App\Models\RevenueService;
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
     * Controls access to the revenue services listing endpoint.
     *
     * Controller:
     *     index()
     *
     * Permission:
     *     revenue_services.view
     */
    public function viewAny(User $user): bool
    {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.view',
            'viewAny'
        );
    }

    /**
     * ============================================================
     * VIEW REVENUE SERVICE
     * ============================================================
     *
     * Controls access to viewing a specific revenue service.
     *
     * Controller:
     *     show()
     *
     * Permission:
     *     revenue_services.view
     */
    public function view(
        User $user,
        RevenueService $service
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_services.view',
            'view',
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
     * Controls access to creating a new revenue service.
     *
     * Controller:
     *     store()
     *
     * Permission:
     *     revenue_services.create
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
     * Controls access to updating an existing revenue service.
     *
     * Controller:
     *     update()
     *
     * Permission:
     *     revenue_services.update
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
     * Controls access to deleting a revenue service.
     *
     * Controller:
     *     destroy()
     *
     * Permission:
     *     revenue_services.delete
     *
     * IMPORTANT:
     * Dependency checks should remain inside
     * RevenueServiceService::delete().
     *
     * The policy only answers:
     *
     *     "Does this user have permission to delete?"
     *
     * The service answers:
     *
     *     "Is this service actually safe/allowed to delete?"
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
     * AUTHORIZATION + LOGGING
     * ============================================================
     *
     * Logs BOTH successful and failed authorization checks.
     *
     * Example successful log:
     *
     *     authorized: true
     *
     * Example denied log:
     *
     *     authorized: false
     *
     * This gives us an audit trail showing:
     *
     *     - Who attempted the action
     *     - Which ability was checked
     *     - Which permission was required
     *     - Whether authorization succeeded
     *     - Which resource was involved
     *     - IP address
     *     - HTTP method
     *     - Route name
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

        Log::info(
            'Revenue service policy authorization check.',
            array_merge(
                [
                    'user_id' => $user->id,
                    'ability' => $ability,
                    'permission' => $permission,
                    'authorized' => $allowed,
                    'ip_address' => request()->ip(),
                    'route' => request()->route()?->getName(),
                    'method' => request()->method(),
                ],
                $context
            )
        );

        return $allowed;
    }
}