<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    /**
     * Create audit log manually.
     *
     * Example:
     *
     * $auditService->log(
     *     'UPDATE',
     *     'TARIFF',
     *     $tariff,
     *     $old,
     *     $new
     * );
     *
     * The optional $userId allows domain services to explicitly
     * provide the user responsible for the action.
     */
    public function log(
        string $action,
        string $module,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $userId = null
    ): AuditLog {
        return AuditLog::create([
            /**
             * Who performed the action.
             *
             * Prefer the explicitly supplied user ID.
             *
             * Fall back to the currently authenticated user for
             * existing callers such as login/logout/general HTTP
             * operations.
             */
            'user_id' => $userId ?? Auth::id(),

            /**
             * Action.
             *
             * CREATE
             * UPDATE
             * DELETE
             * LOGIN
             * LOGOUT
             * ACTIVATE
             * DEACTIVATE
             * APPROVE
             * REJECT
             */
            'action' => strtoupper($action),

            /**
             * Business module.
             *
             * USER
             * REVENUE
             * TARIFF
             * PENALTY
             * CITIZEN
             * AUTH
             */
            'module' => strtoupper($module),

            /**
             * Affected database table.
             */
            'table_name' => $model?->getTable(),

            /**
             * Affected record UUID.
             */
            'record_id' => $model?->id,

            /**
             * Previous state.
             */
            'old_values' => $oldValues,

            /**
             * New state.
             */
            'new_values' => $newValues,

            /**
             * Request/security information.
             */
            'ip_address' => request()->ip(),

            'user_agent' => request()->userAgent(),

            /**
             * Human-readable description.
             */
            'description' => $description,
        ]);
    }

    /**
     * Create audit from model changes.
     *
     * Smart update tracking.
     *
     * Only changed attributes are stored.
     */
    public function logModelUpdate(
        Model $oldModel,
        Model $newModel,
        string $module,
        ?string $userId = null
    ): ?AuditLog {
        $old = $oldModel->getAttributes();

        $new = $newModel->getAttributes();

        /**
         * Detect changed fields.
         */
        $changes = [];

        foreach ($new as $key => $value) {
            if (
                array_key_exists($key, $old)
                && $old[$key] != $value
            ) {
                $changes[$key] = [
                    'old' => $old[$key],
                    'new' => $value,
                ];
            }
        }

        /**
         * Nothing changed.
         */
        if (empty($changes)) {
            return null;
        }

        return $this->log(
            action: 'UPDATE',

            module: $module,

            model: $newModel,

            oldValues: collect($changes)
                ->mapWithKeys(
                    fn ($item, $key) => [
                        $key => $item['old'],
                    ]
                )
                ->toArray(),

            newValues: collect($changes)
                ->mapWithKeys(
                    fn ($item, $key) => [
                        $key => $item['new'],
                    ]
                )
                ->toArray(),

            description: "{$module} updated",

            userId: $userId
        );
    }

    /**
     * ============================================================
     * LOGIN
     * ============================================================
     */
    public function login(
        Model $user
    ): AuditLog {
        return $this->log(
            action: 'LOGIN',

            module: 'AUTH',

            model: $user,

            description: 'User logged in'
        );
    }

    /**
     * ============================================================
     * LOGOUT
     * ============================================================
     */
    public function logout(
        Model $user
    ): AuditLog {
        return $this->log(
            action: 'LOGOUT',

            module: 'AUTH',

            model: $user,

            description: 'User logged out'
        );
    }

    /**
     * ============================================================
     * APPROVAL WORKFLOW
     * ============================================================
     */
    public function approval(
        string $module,
        Model $model,
        string $status,
        ?string $comment = null,
        ?string $userId = null
    ): AuditLog {
        return $this->log(
            action: strtoupper($status),

            module: $module,

            model: $model,

            description:
                $comment ??
                "{$module} {$status}",

            userId: $userId
        );
    }
}