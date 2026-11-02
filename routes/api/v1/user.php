<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Users\Controllers\UserController;


/**
 * =====================================================
 * USER MANAGEMENT ROUTES
 * =====================================================
 */

 Route::prefix('users')->group(function () {

    // GET ALL USERS
    Route::get('/', [UserController::class, 'index']);

    // CREATE USER
    Route::post('/', [UserController::class, 'store']);

    // GET SINGLE USER
    Route::get('{user}', [UserController::class, 'show']);

    // UPDATE USER PROFILE
    Route::put('{user}', [UserController::class, 'update']);

    // DELETE USER
    Route::delete('{user}', [UserController::class, 'destroy']);

    // PASSWORD UPDATE
    Route::put('{user}/password', [UserController::class, 'updatePassword']);

    // STATUS UPDATE (ACTIVE / INACTIVE / SUSPENDED)
    Route::patch('{user}/status', [UserController::class, 'updateStatus']);
});