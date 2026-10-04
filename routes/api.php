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
    // External payment providers call these endpoints directly.
    // ========================================================

    require base_path(
        'routes/Api/v1/payment-webhook.php'
    );


    // ========================================================
    // PROTECTED ROUTES
    // SANCTUM REQUIRED
    // ========================================================

    Route::middleware('auth:sanctum')->group(function () {

        // ====================================================
        // PAYMENT
        // ====================================================
        //
        // All authenticated payment operations.
        //
        // payment.php is the entry point for:
        //
        // - common payment operations
        // - online payments
        // - cash payments
        // - bank transfers
        //
        // Channel-specific route files are loaded by
        // payment.php.
        // ====================================================

        require base_path(
            'routes/Api/v1/payment/payment.php'
        );


        // ====================================================
        // OFFICE PORTAL
        // ====================================================
        //
        // All routes below belong to the municipal Office
        // portal.
        //
        // office.portal_access
        //     → determines whether the authenticated user
        //       may access Office portal APIs.
        //
        // Individual route permissions
        //     → determine what the user may do.
        //
        // Roles are NOT used as the authorization boundary.
        // ====================================================

        // Route::middleware('permission:office.portal_access')
        //     ->group(function () {

                // ------------------------------------------------
                // USER
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/user.php'
                );


                // ------------------------------------------------
                // ADMINISTRATIVE
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/administrative.php'
                );


                // ------------------------------------------------
                // CITIZEN
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/citizen.php'
                );


                // ------------------------------------------------
                // SYSTEM
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/system.php'
                );


                // ------------------------------------------------
                // REVENUE
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/revenue.php'
                );


                // ------------------------------------------------
                // ASSESSMENT
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/assessment.php'
                );


                // ------------------------------------------------
                // INVOICE
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/invoice.php'
                );


                // ------------------------------------------------
                // AUDIT
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/audit.php'
                );


                // ------------------------------------------------
                // DIRECT COLLECTION
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/direct-collection.php'
                );


                // ------------------------------------------------
                // PAYMENT SCHEDULE
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/payment-schedule.php'
                );


                // ------------------------------------------------
                // TAXPAYER
                // ------------------------------------------------

                require base_path(
                    'routes/Api/v1/taxpayer.php'
                );
            // });


        // ====================================================
        // AGENT PORTAL
        // ====================================================
        //
        // agent.php should contain its own agent-specific
        // authorization.
        //
        // No role:AGENT middleware is required here.
        // ====================================================

        require base_path(
            'routes/Api/v1/agent.php'
        );


        // ====================================================
        // PRIVATE FILE ACCESS
        // ====================================================

        Route::get(
            '/private-file/{file:uuid}/url',
            [PrivateFileController::class, 'show']
        );
    });
});