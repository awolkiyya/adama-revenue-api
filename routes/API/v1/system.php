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

                Route::prefix('roles')
                    ->as('roles.')
                    ->group(function () {

                        Route::get(
                            '/',
                            [RoleController::class, 'index']
                        )->name('index');

                        Route::post(
                            '/',
                            [RoleController::class, 'store']
                        )->name('store');

                        Route::get(
                            '{role}',
                            [RoleController::class, 'show']
                        )->name('show');

                        Route::match(
                            ['put', 'patch'],
                            '{role}',
                            [RoleController::class, 'update']
                        )->name('update');

                        Route::delete(
                            '{role}',
                            [RoleController::class, 'destroy']
                        )->name('destroy');

                        /*
                        |--------------------------------------------------------------------------
                        | Role Permissions
                        |--------------------------------------------------------------------------
                        */

                        Route::post(
                            '{role}/permissions',
                            [RoleController::class, 'assignPermissions']
                        )->name('permissions.assign');

                        Route::delete(
                            '{role}/permissions',
                            [RoleController::class, 'revokePermissions']
                        )->name('permissions.revoke');

                        /*
                        |--------------------------------------------------------------------------
                        | Role History
                        |--------------------------------------------------------------------------
                        */

                        Route::get(
                            '{role}/history',
                            [RoleController::class, 'history']
                        )->name('history');
                    });

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