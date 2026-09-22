<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PrivateFileController;


// ============================================================
// API HEALTH CHECK
// ============================================================

Route::get('/ping', function () {
    return response()->json([
        'message' => 'GMQ System server running',
    ]);
});


// ============================================================
// API VERSION 1
// ============================================================

Route::prefix('v1')->group(function () {

    // ========================================================
    // PUBLIC ROUTES
    // NO AUTHENTICATION REQUIRED
    // ========================================================

    require base_path(
        'routes/Api/v1/auth.php'
    );


    // ========================================================
    // PAYMENT WEBHOOKS
    // PUBLIC / GATEWAY ACCESS
    //
    // IMPORTANT:
    // Do NOT put this inside auth:sanctum.
    //
    // Chapa / Telebirr / CBE Birr / other gateways call
    // these endpoints directly from their servers.
    // ========================================================

    require base_path(
        'routes/Api/v1/payment-webhook.php'
    );


    // ----------------------------------------------------
    // PAYMENT
    //
    // User/application payment operations.
    // Sanctum authentication required.
    // ----------------------------------------------------

    require base_path(
        'routes/Api/v1/payment.php'
    );


    // ========================================================
    // PROTECTED ROUTES
    // SANCTUM REQUIRED
    // ========================================================

    Route::middleware('auth:sanctum')->group(function () {

        // ----------------------------------------------------
        // USER
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/user.php'
        );


        // ----------------------------------------------------
        // ADMINISTRATIVE
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/administrative.php'
        );


        // ----------------------------------------------------
        // CITIZEN
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/citizen.php'
        );


        // ----------------------------------------------------
        // SYSTEM
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/system.php'
        );


        // ----------------------------------------------------
        // REVENUE
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/revenue.php'
        );


        // ----------------------------------------------------
        // ASSESSMENT
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/assessment.php'
        );


        // ----------------------------------------------------
        // INVOICE
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/invoice.php'
        );

        // ----------------------------------------------------
        // AUDIT
        // ----------------------------------------------------

        require base_path(
            'routes/Api/v1/audit.php'
        );

        // DIRECT COLLECTION

        require base_path(
            'routes/Api/v1/direct-collection.php'
        );



        // ----------------------------------------------------
        // PRIVATE FILE ACCESS
        // ----------------------------------------------------

        Route::get(
            '/private-file/{file:uuid}/url',
            [PrivateFileController::class, 'show']
        );


    });
});