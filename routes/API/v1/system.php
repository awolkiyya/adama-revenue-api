<?php

use Illuminate\Support\Facades\Route;

use App\Modules\System\AccessManagement\Controllers\RoleController;
use App\Modules\System\AccessManagement\Controllers\PermissionController;

/*
|--------------------------------------------------------------------------
| System Related Routes
|--------------------------------------------------------------------------
*/

Route::prefix('system')
    ->as('system.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Access Management
        |--------------------------------------------------------------------------
        */

        Route::prefix('access-management')
            ->as('access-management.')
            ->group(function () {

                /*
                |--------------------------------------------------------------------------
                | Roles
                |--------------------------------------------------------------------------
                */

                Route::apiResource(
                    'roles',
                    RoleController::class
                );

                /*
                |--------------------------------------------------------------------------
                | Role Permissions
                |--------------------------------------------------------------------------
                */

                Route::post(
                    'roles/{role}/permissions',
                    [RoleController::class, 'assignPermissions']
                )->name('roles.permissions.assign');

                Route::delete(
                    'roles/{role}/permissions',
                    [RoleController::class, 'revokePermissions']
                )->name('roles.permissions.revoke');

                /*
                |--------------------------------------------------------------------------
                | Role History
                |--------------------------------------------------------------------------
                */

                Route::get(
                    'roles/{role}/history',
                    [RoleController::class, 'history']
                )->name('roles.history');

                /*
                |--------------------------------------------------------------------------
                | Permissions
                |--------------------------------------------------------------------------
                */

                Route::get(
                    'permissions/grouped',
                    [PermissionController::class, 'grouped']
                )->name('permissions.grouped');
            });
    });