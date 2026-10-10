<?php

use App\Modules\System\MobileAppRelease\Controllers\MobileAppReleaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile-app-releases')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated Release Management
    |--------------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum'])->group(function () {

        // List mobile app releases.
        Route::get('/', [
            MobileAppReleaseController::class,
            'index',
        ])->name('mobile-app-releases.index');

        // Create a draft release and upload its APK.
        Route::post('/', [
            MobileAppReleaseController::class,
            'store',
        ])->name('mobile-app-releases.store');

        // Publish a draft release.
        Route::post('/{mobile_app_release}/publish', [
            MobileAppReleaseController::class,
            'publish',
        ])->whereUuid('mobile_app_release')
            ->name('mobile-app-releases.publish');

        // Withdraw a release.
        Route::post('/{mobile_app_release}/withdraw', [
            MobileAppReleaseController::class,
            'withdraw',
        ])->whereUuid('mobile_app_release')
            ->name('mobile-app-releases.withdraw');


        // View a specific release.
        Route::get('/{mobile_app_release}', [
            MobileAppReleaseController::class,
            'show',
        ])->whereUuid('mobile_app_release')
            ->name('mobile-app-releases.show');

        // Update a draft release.
        Route::put('/{mobile_app_release}', [
            MobileAppReleaseController::class,
            'update',
        ])->whereUuid('mobile_app_release')
            ->name('mobile-app-releases.update');
    });
});

