<?php

// bootstrap/app.php (Laravel 11+)
//
// This wires EVERY exception thrown anywhere in the app — validation
// failures, missing routes, auth failures, account lockouts, DB errors,
// rate limits, and anything unexpected — through the same ApiResponse
// envelope, but ONLY for API/JSON requests.
//
// Regular web routes keep Laravel's normal HTML error pages untouched.

use App\Modules\Auth\Exceptions\AccountLockedException;
use App\Services\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    ->withMiddleware(function (Middleware $middleware) {

        /*
        |--------------------------------------------------------------------------
        | API Request ID
        |--------------------------------------------------------------------------
        |
        | Attach a request_id to every API request as early as possible.
        | This allows logs and ApiResponse to share the same correlation ID.
        |
        */

        $middleware->api(prepend: [
            \App\Http\Middleware\AssignRequestId::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Sanctum Stateful API
        |--------------------------------------------------------------------------
        |
        | Required for cookie/session-based SPA authentication.
        |
        */

        $middleware->statefulApi();


         /*
        |--------------------------------------------------------------------------
        | Cookie Encryption
        |--------------------------------------------------------------------------
        |
        | The role cookie is intentionally readable by Next.js middleware.
        | It is NOT used for backend authorization.
        |
        */

        $middleware->encryptCookies(except: [
            'role',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Custom Middleware Aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'log.auth' => \App\Http\Middleware\LogAuthenticationRequest::class,
        ]);
    })

    /*
    |--------------------------------------------------------------------------
    | Exception Handling
    |--------------------------------------------------------------------------
    */

    ->withExceptions(function (Exceptions $exceptions) {

        /*
        |--------------------------------------------------------------------------
        | Determine Whether This Is an API/JSON Request
        |--------------------------------------------------------------------------
        |
        | API/JSON requests receive our standardized ApiResponse envelope.
        |
        | Normal web requests are allowed to use Laravel's default HTML
        | exception rendering.
        |
        */

        $wantsJson = fn (Request $request): bool =>
            $request->is('api/*') ||
            $request->expectsJson();


        /*
        |--------------------------------------------------------------------------
        | Validation Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 422
        |
        */

        $exceptions->render(
            function (
                ValidationException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::validation(
                    $e->errors(),
                    'The given data was invalid'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Account Locked Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 423 Locked
        |
        | This is a security/business condition, not a server error.
        |
        */

        $exceptions->render(
            function (
                AccountLockedException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::error(
                    $e->getMessage(),
                    [
                        'remaining_seconds' => $e->remainingSeconds,
                        'locked_until' => $e->lockedUntil?->toIso8601String(),
                    ],
                    423,
                    'ACCOUNT_LOCKED'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Authentication Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 401
        |
        */

        $exceptions->render(
            function (
                AuthenticationException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::unauthorized(
                    'Authentication required'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Authorization Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 403
        |
        */

        $exceptions->render(
            function (
                AuthorizationException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::forbidden(
                    $e->getMessage()
                        ?: 'You do not have permission to perform this action'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Model Not Found
        |--------------------------------------------------------------------------
        |
        | HTTP 404
        |
        */

        $exceptions->render(
            function (
                ModelNotFoundException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                $model = class_basename($e->getModel());

                return ApiResponse::notFound(
                    "{$model} not found"
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Route Not Found
        |--------------------------------------------------------------------------
        |
        | HTTP 404
        |
        */

        $exceptions->render(
            function (
                NotFoundHttpException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::notFound(
                    'The requested endpoint does not exist'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Method Not Allowed
        |--------------------------------------------------------------------------
        |
        | HTTP 405
        |
        */

        $exceptions->render(
            function (
                MethodNotAllowedHttpException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::error(
                    'This HTTP method is not supported for this endpoint',
                    null,
                    405,
                    'METHOD_NOT_ALLOWED'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Too Many Requests
        |--------------------------------------------------------------------------
        |
        | HTTP 429
        |
        */

        $exceptions->render(
            function (
                TooManyRequestsHttpException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::tooManyRequests(
                    'Too many requests, please slow down',
                    $e->getHeaders()['Retry-After'] ?? null
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Database Exception
        |--------------------------------------------------------------------------
        |
        | HTTP 500
        |
        | Never expose SQL queries, database structure, table names,
        | credentials, or internal DB information to the client.
        |
        */

        $exceptions->render(
            function (
                QueryException $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Log Full Exception Internally
                |--------------------------------------------------------------------------
                */

                report($e);

                return ApiResponse::serverError(
                    'A database error occurred. Please try again later.'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Generic HTTP Exception
        |--------------------------------------------------------------------------
        |
        | Handles Symfony/Laravel HTTP exceptions not already handled above.
        |
        */

        $exceptions->render(
            function (
                HttpExceptionInterface $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                return ApiResponse::error(
                    $e->getMessage() ?: 'An error occurred',
                    null,
                    $e->getStatusCode(),
                    'HTTP_ERROR'
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Final Safety Net
        |--------------------------------------------------------------------------
        |
        | Anything unexpected that reaches this point is treated as a
        | server error.
        |
        | The complete exception is reported internally, while the client
        | receives only a safe generic message.
        |
        */

        $exceptions->render(
            function (
                \Throwable $e,
                Request $request
            ) use ($wantsJson) {

                if (! $wantsJson($request)) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Internal Logging
                |--------------------------------------------------------------------------
                */

                report($e);

                /*
                |--------------------------------------------------------------------------
                | Safe Client Response
                |--------------------------------------------------------------------------
                */

                return ApiResponse::serverError(
                    'Something went wrong on our end. Please try again later.',
                    $e
                );
            }
        );
    })

    ->create();