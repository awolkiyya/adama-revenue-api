<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Administrative\Controllers\AdminUnitController;
use App\Modules\Administrative\Controllers\SectorController;
use App\Modules\Administrative\Controllers\ClusterController;



Route::prefix('administrative')->group(function () {
    
    // --- Administrative Units ---
    Route::get('/', [AdminUnitController::class, 'index']);
    Route::post('/', [AdminUnitController::class, 'store']);

    // --- Sectors ---
    // Note: Since sectors are linked to clusters, we pass cluster_id in the path
    Route::prefix('clusters')->group(function () {
        Route::get('/', [ClusterController::class, 'index']);
        Route::post('/', [ClusterController::class, 'store']);
    });

    

   // --- Global Sector Management ---
   // For direct access by UUID (e.g., creating/updating/deleting specific sectors)

   Route::prefix('sectors')->group(function () {

        // List sectors
        Route::get('/', [SectorController::class, 'index']);

        // Create sector
        Route::post('/', [SectorController::class, 'store']);

        // Get single sector
        Route::get('{sector}', [SectorController::class, 'show']);

        // Update sector
        Route::patch('{sector}', [SectorController::class, 'update']);

        // Delete sector
        Route::delete('{sector}', [SectorController::class, 'destroy']);

   });
});

