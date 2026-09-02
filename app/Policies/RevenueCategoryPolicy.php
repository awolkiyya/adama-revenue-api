<?php

namespace App\Policies;

use App\Models\RevenueCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;
use Illuminate\Support\Facades\Log;

class RevenueCategoryPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY REVENUE CATEGORIES
     * ============================================================
     *
     * Allows the user to view the revenue category listing.
     *
     * Permission:
     * revenue_categorys.view
     */
    public function viewAny(User $user): bool
    {
        return $this->authorizeWithLog(
            $user,
            'revenue_categorys.view',
            'viewAny'
        );
    }

    /**
     * ============================================================
     * VIEW REVENUE CATEGORY
     * ============================================================
     *
     * Allows the user to view a specific revenue category.
     *
     * Permission:
     * revenue_categorys.view
     */
    public function view(
        User $user,
        RevenueCategory $category
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_categorys.view',
            'view',
            [
                'resource_id' => $category->id,
            ]
        );
    }

    /**
     * ============================================================
     * CREATE REVENUE CATEGORY
     * ============================================================
     *
     * Allows the user to create a new revenue category.
     *
     * Permission:
     * revenue_categorys.create
     */
    public function create(User $user): bool
    {
        return $this->authorizeWithLog(
            $user,
            'revenue_categorys.create',
            'create'
        );
    }

    /**
     * ============================================================
     * UPDATE REVENUE CATEGORY
     * ============================================================
     *
     * Allows the user to update an existing revenue category.
     *
     * Permission:
     * revenue_categorys.update
     */
    public function update(
        User $user,
        RevenueCategory $category
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_categorys.update',
            'update',
            [
                'resource_id' => $category->id,
            ]
        );
    }

    /**
     * ============================================================
     * DELETE REVENUE CATEGORY
     * ============================================================
     *
     * Allows the user to delete a revenue category.
     *
     * IMPORTANT:
     * Dependency/business-rule checks should remain inside
     * RevenueCategoryService, not inside this policy.
     *
     * Permission:
     * revenue_categorys.delete
     */
    public function delete(
        User $user,
        RevenueCategory $category
    ): bool {
        return $this->authorizeWithLog(
            $user,
            'revenue_categorys.delete',
            'delete',
            [
                'resource_id' => $category->id,
            ]
        );
    }

    /**
     * ============================================================
     * AUTHORIZATION + LOGGING
     * ============================================================
     *
     * Logs BOTH successful and failed authorization checks.
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
            'Revenue category policy authorization check.',
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