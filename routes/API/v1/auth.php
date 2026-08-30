<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\OtpController;
use App\Modules\Auth\Controllers\SessionController;


/*
|--------------------------------------------------------------------------
| API V1 Authentication Routes
|--------------------------------------------------------------------------
|
| Prefix:
|     /api/v1/auth
|
|--------------------------------------------------------------------------
| AUTHENTICATION ARCHITECTURE
|--------------------------------------------------------------------------
|
| WEB
| ---
| Laravel Sanctum Stateful Authentication
|
| Middleware:
|     web
|     log.auth
|     auth:sanctum
|
| Browser credentials:
|     laravel-session
|     XSRF-TOKEN
|
|
| MOBILE
| ------
| Laravel Sanctum Personal Access Token
|
| Middleware:
|     auth:sanctum
|
| Flutter credentials:
|     Authorization: Bearer {token}
|
|--------------------------------------------------------------------------
*/


Route::prefix('auth')->group(function () {


    /*
    |--------------------------------------------------------------------------
    | WEB AUTHENTICATION
    |--------------------------------------------------------------------------
    |
    | Next.js / Browser
    |
    | Authentication:
    |     Laravel Sanctum Stateful Authentication
    |
    | Credentials:
    |     laravel-session
    |     XSRF-TOKEN
    |
    |--------------------------------------------------------------------------
    */

    // Route::middleware('web')->group(function () {


        /*
        |--------------------------------------------------------------------------
        | WEB EMPLOYEE LOGIN
        |--------------------------------------------------------------------------
        |
        | POST:
        |     /api/v1/auth/web-login
        |
        | Authentication:
        |     None before login
        |
        | Result:
        |     Laravel session cookie
        |
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/web-login',
            [AuthController::class, 'webLogin']
        )->middleware('throttle:login')->name('auth.web-login');


        /*
        |--------------------------------------------------------------------------
        | WEB CITIZEN OTP
        |--------------------------------------------------------------------------
        */

        Route::prefix('web-otp')->group(function () {


            /*
            |--------------------------------------------------------------------------
            | SEND OTP
            |--------------------------------------------------------------------------
            */

            Route::post(
                '/send',
                [OtpController::class, 'webSend']
            )->name('auth.web-otp.send');


            /*
            |--------------------------------------------------------------------------
            | VERIFY OTP
            |--------------------------------------------------------------------------
            */

            Route::post(
                '/verify',
                [OtpController::class, 'webVerify']
            )->name('auth.web-otp.verify');


            /*
            |--------------------------------------------------------------------------
            | RESEND OTP
            |--------------------------------------------------------------------------
            */

            Route::post(
                '/resend',
                [OtpController::class, 'webResend']
            )->name('auth.web-otp.resend');

        });


        /*
        |--------------------------------------------------------------------------
        | WEB PROTECTED
        |--------------------------------------------------------------------------
        |
        | Middleware order:
        |
        |     web
        |       ↓
        |     log.auth
        |       ↓
        |     auth:sanctum
        |       ↓
        |     Controller
        |
        | `web`:
        |     Starts Laravel's session middleware.
        |
        | `log.auth`:
        |     Temporary diagnostic middleware.
        |
        | `auth:sanctum`:
        |     Authenticates the browser using the
        |     Laravel session for stateful requests.
        |
        |--------------------------------------------------------------------------
        */

        Route::middleware([
            'log.auth',
            'auth:sanctum',
        ])->group(function () {


            /*
            |--------------------------------------------------------------------------
            | CURRENT WEB USER
            |--------------------------------------------------------------------------
            |
            | GET:
            |     /api/v1/auth/web-me
            |
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/web-me',
                [SessionController::class, 'webMe']
            )->name('auth.web-me');


            /*
            |--------------------------------------------------------------------------
            | WEB SESSION
            |--------------------------------------------------------------------------
            |
            | GET:
            |     /api/v1/auth/web-session
            |
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/web-session',
                [SessionController::class, 'webSession']
            )->name('auth.web-session');


            /*
            |--------------------------------------------------------------------------
            | WEB LOGOUT
            |--------------------------------------------------------------------------
            |
            | POST:
            |     /api/v1/auth/web-logout
            |
            |--------------------------------------------------------------------------
            */

            Route::post(
                '/web-logout',
                [AuthController::class, 'webLogout']
            )->name('auth.web-logout');

        });

    // });


});