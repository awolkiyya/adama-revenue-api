<?php

namespace App\Policies;

use App\Models\SystemLog;
use App\Models\User;
use App\Policies\Concerns\ChecksHierarchy;


class SystemLogPolicy
{
    use ChecksHierarchy;

    /**
     * ============================================================
     * VIEW ANY AUDIT LOGS
     * ============================================================
     *
     * Permission:
     *
     *     audit.view
     *
     * Any authenticated user with audit.view can access
     * the audit log list.
     *
     * Audit logs are system-level resources.
     *
     * Therefore, no CITY/SUBCITY/WEREDA/SECTOR or
     * administrative-unit scope restriction applies.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission(
            $user,
            'audit.view'
        );
    }

    /**
     * ============================================================
     * VIEW AUDIT LOG
     * ============================================================
     *
     * Permission:
     *
     *     audit.view
     *
     * Any authenticated user with audit.view can view
     * an individual audit log.
     *
     * No organizational scope restriction applies because
     * audit logs are system-level records.
     */
    public function view(
        User $user,
        SystemLog $systemLog
    ): bool {
        return $this->hasPermission(
            $user,
            'audit.view'
        );
    }
}
