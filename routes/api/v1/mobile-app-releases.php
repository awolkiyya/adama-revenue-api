<?php

use Illuminate\Support\Facades\Route;
use App\Modules\System\MobileAppRelease\Controllers\MobileAppReleaseController;
use App\Modules\System\MobileAppRelease\Controllers\MobileAppReleaseUploadController;

Route::prefix('mobile-app-releases')
    ->name('mobile-app-releases.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Mobile App Release Management
        |--------------------------------------------------------------------------
        */

        // List mobile app releases.
        Route::get('/', [
            MobileAppReleaseController::class,
            'index',
        ])->name('index');

        // Create a draft release.
        // APK upload will happen through the resumable upload endpoints.
        Route::post('/', [
            MobileAppReleaseController::class,
            'store',
        ])->name('store');

        // Publish a draft release.
        Route::post('/{mobile_app_release}/publish', [
            MobileAppReleaseController::class,
            'publish',
        ])->whereUuid('mobile_app_release')
            ->name('publish');

        // Withdraw a release.
        Route::post('/{mobile_app_release}/withdraw', [
            MobileAppReleaseController::class,
            'withdraw',
        ])->whereUuid('mobile_app_release')
            ->name('withdraw');

        // View a specific release.
        Route::get('/{mobile_app_release}', [
            MobileAppReleaseController::class,
            'show',
        ])->whereUuid('mobile_app_release')
            ->name('show');

        // Update a draft release.
        Route::put('/{mobile_app_release}', [
            MobileAppReleaseController::class,
            'update',
        ])->whereUuid('mobile_app_release')
            ->name('update');


        /*
        |--------------------------------------------------------------------------
        | Resumable APK Upload
        |--------------------------------------------------------------------------
        |
        | Supports large APK uploads through independently uploaded chunks.
        |
        */

        Route::prefix('uploads')
            ->name('uploads.')
            ->controller(MobileAppReleaseUploadController::class)
            ->group(function () {

                // Create upload session.
                Route::post('/', 'store')
                    ->name('store');

                // Upload an individual chunk.
                Route::put('/{upload:uuid}/chunks/{chunkIndex}', 'uploadChunk')
                    ->whereNumber('chunkIndex')
                    ->name('chunks.store');

                // Get upload progress.
                Route::get('/{upload:uuid}', 'show')
                    ->name('show');

                // Assemble chunks and finalize upload.
                Route::post('/{upload:uuid}/complete', 'complete')
                    ->name('complete');
            });
    });
