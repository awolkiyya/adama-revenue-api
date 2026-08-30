<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

trait ChecksHierarchy
{
    /**
     * ============================================================
     * CONFIGURATION
     * ============================================================
     */

    private const GUARD = 'api';

    /**
     * ============================================================
     * PERMISSION
     * ============================================================
     *
     * Determines WHAT the authenticated user is allowed to do.
     *
     * Roles are intentionally NOT checked here.
     *
     * Authorization model:
     *
     *      Permission + Scope
     *
     * Example:
     *
     *      users.update
     *      +
     *      same city
     *
     * A role only groups permissions. It is not an authorization
     * rule inside this method.
     */
    protected function hasPermission(
        User $user,
        string $permission
    ): bool {
        try {
            $result = $user->hasPermissionTo(
                $permission,
                self::GUARD
            );

            Log::debug(
                'Authorization permission check',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'guard' => self::GUARD,
                    'result' => $result,
                ]
            );

            return $result;
        } catch (PermissionDoesNotExist $exception) {
            /**
             * A permission that does not exist should never grant
             * access.
             */
            Log::warning(
                'Authorization permission does not exist',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'guard' => self::GUARD,
                    'message' => $exception->getMessage(),
                ]
            );

            return false;
        } catch (\Throwable $exception) {
            /**
             * Fail closed.
             *
             * Any unexpected authorization failure results in
             * denied access.
             */
            Log::error(
                'Authorization permission check failed',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'guard' => self::GUARD,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]
            );

            return false;
        }
    }

    /**
     * ============================================================
     * GLOBAL SCOPE ACCESS
     * ============================================================
     *
     * SYSTEM_ADMIN is a global administrator.
     *
     * SYSTEM_ADMIN intentionally does NOT require:
     *
     *      level
     *      city_id
     *      subcity_id
     *      wereda_id
     *      sector_id
     *      administrative_unit_id
     *
     * The user must still have the required permission.
     *
     * This method only determines whether the user has GLOBAL
     * organizational scope.
     */
    protected function hasGlobalScopeAccess(
        User $user
    ): bool {
        try {
            $result = $user->hasRole(
                'SYSTEM_ADMIN',
                self::GUARD
            );

            Log::debug(
                'Authorization global scope check',
                [
                    'user_id' => $user->id,
                    'role' => 'SYSTEM_ADMIN',
                    'guard' => self::GUARD,
                    'result' => $result,
                ]
            );

            return $result;
        } catch (\Throwable $exception) {
            /**
             * Fail closed.
             *
             * If the global-role check itself fails, do not grant
             * global access.
             */
            Log::error(
                'Authorization global scope check failed',
                [
                    'user_id' => $user->id,
                    'role' => 'SYSTEM_ADMIN',
                    'guard' => self::GUARD,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]
            );

            return false;
        }
    }

    /**
     * ============================================================
     * ORGANIZATIONAL SCOPE
     * ============================================================
     *
     * These methods determine WHERE a user can access a resource.
     *
     * They NEVER grant permission by themselves.
     *
     * A policy should normally use:
     *
     *      hasPermission(...)
     *              &&
     *      hasAccessToModel(...)
     *
     * Example:
     *
     *      if (
     *          !$this->hasPermission($user, 'users.update')
     *          || !$this->hasUserScopeAccess($user, $model)
     *      ) {
     *          return false;
     *      }
     */

    /**
     * ============================================================
     * SAME SECTOR
     * ============================================================
     */
    protected function sameSector(
        User $user,
        mixed $model
    ): bool {
        $userSectorId = $user->sector_id;
        $modelSectorId = data_get($model, 'sector_id');

        $result =
            $userSectorId !== null
            && $modelSectorId !== null
            && (int) $userSectorId === (int) $modelSectorId;

        Log::debug(
            'Authorization scope: sector comparison',
            [
                'user_id' => $user->id,
                'user_sector_id' => $userSectorId,
                'model_sector_id' => $modelSectorId,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * SAME CITY
     * ============================================================
     */
    protected function sameCity(
        User $user,
        mixed $model
    ): bool {
        $userCityId = $user->city_id;
        $modelCityId = data_get($model, 'city_id');

        $result =
            $userCityId !== null
            && $modelCityId !== null
            && (int) $userCityId === (int) $modelCityId;

        Log::debug(
            'Authorization scope: city comparison',
            [
                'user_id' => $user->id,
                'user_city_id' => $userCityId,
                'model_city_id' => $modelCityId,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * SAME SUBCITY
     * ============================================================
     */
    protected function sameSubcity(
        User $user,
        mixed $model
    ): bool {
        $userSubcityId = $user->subcity_id;
        $modelSubcityId = data_get($model, 'subcity_id');

        $result =
            $userSubcityId !== null
            && $modelSubcityId !== null
            && (int) $userSubcityId === (int) $modelSubcityId;

        Log::debug(
            'Authorization scope: subcity comparison',
            [
                'user_id' => $user->id,
                'user_subcity_id' => $userSubcityId,
                'model_subcity_id' => $modelSubcityId,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * SAME WEREDA
     * ============================================================
     */
    protected function sameWereda(
        User $user,
        mixed $model
    ): bool {
        $userWeredaId = $user->wereda_id;
        $modelWeredaId = data_get($model, 'wereda_id');

        $result =
            $userWeredaId !== null
            && $modelWeredaId !== null
            && (int) $userWeredaId === (int) $modelWeredaId;

        Log::debug(
            'Authorization scope: wereda comparison',
            [
                'user_id' => $user->id,
                'user_wereda_id' => $userWeredaId,
                'model_wereda_id' => $modelWeredaId,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * SAME ADMINISTRATIVE UNIT
     * ============================================================
     *
     * Generic administrative-unit comparison.
     *
     * This is useful when both the user and target resource use
     * administrative_unit_id directly.
     */
    protected function sameAdministrativeUnit(
        User $user,
        mixed $model
    ): bool {
        $userUnitId = $user->administrative_unit_id;
        $modelUnitId = data_get($model, 'administrative_unit_id');

        $result =
            $userUnitId !== null
            && $modelUnitId !== null
            && (int) $userUnitId === (int) $modelUnitId;

        Log::debug(
            'Authorization scope: administrative unit comparison',
            [
                'user_id' => $user->id,
                'user_administrative_unit_id' => $userUnitId,
                'model_administrative_unit_id' => $modelUnitId,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * USER SCOPE ACCESS
     * ============================================================
     *
     * Determines whether the authenticated user can access
     * another user's record based ONLY on organizational scope.
     *
     * Permission is checked separately.
     *
     * SYSTEM_ADMIN is global and therefore bypasses the
     * organizational-level requirement.
     *
     * Supported scope levels:
     *
     *      CITY
     *      SUBCITY
     *      WEREDA
     *      SECTOR
     */
    protected function hasUserScopeAccess(
        User $user,
        User $model
    ): bool {
        /**
         * SYSTEM_ADMIN has global scope.
         *
         * They intentionally do not need a level.
         */
        if ($this->hasGlobalScopeAccess($user)) {
            Log::debug(
                'Authorization user scope granted: global administrator',
                [
                    'user_id' => $user->id,
                    'target_user_id' => $model->id,
                ]
            );

            return true;
        }

        /**
         * Non-global users must have an organizational level.
         */
        if (!$user->level) {
            Log::warning(
                'Authorization scope denied: user has no level',
                [
                    'user_id' => $user->id,
                    'target_user_id' => $model->id,
                ]
            );

            return false;
        }

        $level = strtoupper((string) $user->level);

        $result = match ($level) {
            'CITY' => $this->sameCity($user, $model),

            'SUBCITY' => $this->sameSubcity($user, $model),

            'WEREDA' => $this->sameWereda($user, $model),

            'SECTOR' => $this->sameSector($user, $model),

            default => false,
        };

        Log::debug(
            'Authorization user scope check',
            [
                'user_id' => $user->id,
                'target_user_id' => $model->id,
                'level' => $level,
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * GENERIC MODEL SCOPE ACCESS
     * ============================================================
     *
     * Determines whether the authenticated user can access a
     * resource based on organizational scope.
     *
     * Permission is checked separately.
     *
     * SYSTEM_ADMIN has global scope and therefore does not require
     * an organizational level.
     *
     * Supported models may expose one or more of:
     *
     *      administrative_unit_id
     *      city_id
     *      subcity_id
     *      wereda_id
     *      sector_id
     */
    protected function hasAccessToModel(
        User $user,
        mixed $model
    ): bool {
        /**
         * SYSTEM_ADMIN has global scope.
         *
         * This check MUST happen before checking $user->level.
         */
        if ($this->hasGlobalScopeAccess($user)) {
            Log::debug(
                'Authorization scope granted: global administrator',
                [
                    'user_id' => $user->id,
                    'model_type' => $this->modelType($model),
                    'model_id' => data_get($model, 'id'),
                ]
            );

            return true;
        }

        /**
         * All non-global users require an organizational level.
         */
        if (!$user->level) {
            Log::warning(
                'Authorization scope denied: user has no level',
                [
                    'user_id' => $user->id,
                    'model_type' => $this->modelType($model),
                    'model_id' => data_get($model, 'id'),
                ]
            );

            return false;
        }

        $level = strtoupper((string) $user->level);

        $result = match ($level) {
            'CITY' => $this->hasCityScopeAccess($user, $model),

            'SUBCITY' => $this->hasSubcityScopeAccess($user, $model),

            'WEREDA' => $this->hasWeredaScopeAccess($user, $model),

            'SECTOR' => $this->hasSectorScopeAccess($user, $model),

            default => false,
        };

        Log::debug(
            'Authorization model scope check',
            [
                'user_id' => $user->id,
                'level' => $level,
                'model_type' => $this->modelType($model),
                'model_id' => data_get($model, 'id'),
                'result' => $result,
            ]
        );

        return $result;
    }

    /**
     * ============================================================
     * CITY SCOPE
     * ============================================================
     *
     * A CITY-level user can access resources belonging to the
     * same city.
     */
    protected function hasCityScopeAccess(
        User $user,
        mixed $model
    ): bool {
        return $this->sameCity($user, $model);
    }

    /**
     * ============================================================
     * SUBCITY SCOPE
     * ============================================================
     */
    protected function hasSubcityScopeAccess(
        User $user,
        mixed $model
    ): bool {
        return $this->sameSubcity($user, $model);
    }

    /**
     * ============================================================
     * WEREDA SCOPE
     * ============================================================
     */
    protected function hasWeredaScopeAccess(
        User $user,
        mixed $model
    ): bool {
        return $this->sameWereda($user, $model);
    }

    /**
     * ============================================================
     * SECTOR SCOPE
     * ============================================================
     *
     * Useful for sector-specific resources.
     *
     * This is intentionally explicit instead of being tied to
     * a particular role.
     */
    protected function hasSectorScopeAccess(
        User $user,
        mixed $model
    ): bool {
        return $this->sameSector($user, $model);
    }

    /**
     * ============================================================
     * PERMISSION + SCOPE
     * ============================================================
     *
     * Requires BOTH:
     *
     *      WHAT  = permission
     *      WHERE = organizational scope
     *
     * SYSTEM_ADMIN:
     *
     *      permission + global scope
     *
     * Scoped user:
     *
     *      permission + matching organizational scope
     */
    protected function canAccess(
        User $user,
        string $permission,
        mixed $model
    ): bool {
        /**
         * Permission is checked first.
         *
         * If the user does not have the permission, there is no
         * reason to perform the scope check.
         */
        if (!$this->hasPermission($user, $permission)) {
            Log::debug(
                'Authorization denied: missing permission',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'model_type' => $this->modelType($model),
                    'model_id' => data_get($model, 'id'),
                ]
            );

            return false;
        }

        /**
         * Permission exists.
         *
         * Now verify organizational scope.
         *
         * SYSTEM_ADMIN will pass here through global scope.
         */
        if (!$this->hasAccessToModel($user, $model)) {
            Log::debug(
                'Authorization denied: outside organizational scope',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'model_type' => $this->modelType($model),
                    'model_id' => data_get($model, 'id'),
                ]
            );

            return false;
        }

        Log::debug(
            'Authorization granted: permission and scope matched',
            [
                'user_id' => $user->id,
                'permission' => $permission,
                'model_type' => $this->modelType($model),
                'model_id' => data_get($model, 'id'),
            ]
        );

        return true;
    }

    /**
     * ============================================================
     * PERMISSION + USER SCOPE
     * ============================================================
     *
     * Convenience method specifically for User policies.
     *
     * SYSTEM_ADMIN:
     *
     *      permission + global scope
     *
     * Scoped user:
     *
     *      permission + matching organizational scope
     */
    protected function canAccessUser(
        User $user,
        string $permission,
        User $model
    ): bool {
        if (!$this->hasPermission($user, $permission)) {
            Log::debug(
                'User authorization denied: missing permission',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'target_user_id' => $model->id,
                ]
            );

            return false;
        }

        if (!$this->hasUserScopeAccess($user, $model)) {
            Log::debug(
                'User authorization denied: outside organizational scope',
                [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'target_user_id' => $model->id,
                ]
            );

            return false;
        }

        Log::debug(
            'User authorization granted',
            [
                'user_id' => $user->id,
                'permission' => $permission,
                'target_user_id' => $model->id,
            ]
        );

        return true;
    }

    /**
     * ============================================================
     * MODEL TYPE
     * ============================================================
     */
    private function modelType(mixed $model): ?string
    {
        if ($model instanceof Model) {
            return $model::class;
        }

        return is_object($model)
            ? $model::class
            : null;
    }
}
