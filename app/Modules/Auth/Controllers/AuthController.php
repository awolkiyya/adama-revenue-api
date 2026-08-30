<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\AuthenticationService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private AuthenticationService $authService,
        private AuthSessionService $authSessionService
    ) {
    }

    /*
|--------------------------------------------------------------------------
| WEB LOGIN
|--------------------------------------------------------------------------
|
| Authentication:
|
|     Laravel Web Session
|
| Browser receives:
|
|     laravel_session
|     role                 ← employee frontend routing hint only
|
| IMPORTANT:
|
| - Laravel session is the REAL authentication mechanism.
| - The role cookie is NOT trusted for backend authorization.
| - Employee users receive a role cookie.
| - Citizen users do NOT receive a role cookie.
| - No Sanctum personal access token is created.
|
*/

public function webLogin(
    LoginRequest $request
): JsonResponse {

    Log::info('========== WEB LOGIN START ==========');

    Log::info('Web login request received', [
        'email' => $request->input('email'),
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ]);

    try {

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $validated = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Validate Credentials
        |--------------------------------------------------------------------------
        |
        | Returns:
        |
        |     User
        |
        | Does NOT create a Sanctum token.
        |
        */

        $user = $this->authService->validateCredentials(
            $validated
        );

        Log::info('Web credentials authenticated', [
            'user_id' => $user->id,
            'user_type' => $user->user_type,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Laravel Web Session
        |--------------------------------------------------------------------------
        |
        | AuthSessionService:
        |
        |     Auth::guard('web')->login()
        |     session()->regenerate()
        |
        */

        $this->authSessionService->authenticate(
            $request,
            $user
        );

        Log::info('Laravel web session created', [
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Update Last Login
        |--------------------------------------------------------------------------
        */

        $user->update([
            'last_login_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Load User Relations
        |--------------------------------------------------------------------------
        |
        | Employee:
        |
        |     roles.permissions
        |
        | Citizen:
        |
        |     citizenAccount.citizen.avatar
        |
        */

        $this->loadUserRelations($user);

        /*
        |--------------------------------------------------------------------------
        | Build Response
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | No Sanctum token is returned.
        |
        */

        $resource = new AuthUserResource(
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Create API Response
        |--------------------------------------------------------------------------
        */

        $response = ApiResponse::success(
            $resource,
            'Login successful'
        );

        /*
        |--------------------------------------------------------------------------
        | EMPLOYEE ROLE COOKIE
        |--------------------------------------------------------------------------
        |
        | The role cookie is ONLY used by Next.js middleware
        | for frontend route gating.
        |
        | It is NOT a security authority.
        |
        | Laravel MUST independently enforce:
        |
        |     - authentication
        |     - role
        |     - permissions
        |     - organizational scope
        |     - resource ownership
        |
        |--------------------------------------------------------------------------
        */

        if (
            $user->user_type === 'employee' &&
            $user->roles->isNotEmpty()
        ) {

            /*
            |--------------------------------------------------------------------------
            | Primary Role
            |--------------------------------------------------------------------------
            |
            | For the frontend we use the user's primary/first
            | assigned role.
            |
            */

            $primaryRole = $user->roles
                ->first()
                ->name;

            /*
            |--------------------------------------------------------------------------
            | Remember Me
            |--------------------------------------------------------------------------
            |
            | If LoginRequest contains:
            |
            |     remember = true
            |
            | role cookie:
            |
            |     30 days
            |
            | Otherwise:
            |
            |     24 hours
            |
            */

            $remember = (bool) (
                $validated['remember'] ?? false
            );

            /*
            |--------------------------------------------------------------------------
            | Attach Role Cookie
            |--------------------------------------------------------------------------
            |
            | Cookie:
            |
            |     role=SYSTEM_ADMIN
            |
            | Security:
            |
            |     Secure   → production only
            |     HttpOnly → true
            |     SameSite → Lax
            |
            | IMPORTANT:
            |
            | HttpOnly does NOT prevent Next.js middleware
            | from reading the cookie.
            |
            | It only prevents browser JavaScript from reading
            | the cookie through document.cookie.
            |
            */

            $response->withCookie(
                cookie(
                    'role',

                    // Exact role value
                    $primaryRole,

                    // Lifetime
                    $remember
                        ? 60 * 24 * 30   // 30 days
                        : 60 * 24,       // 24 hours

                    // Path
                    '/',

                    // Domain
                    null,

                    // Secure
                    app()->environment('production'),

                    // HttpOnly
                    true,

                    // Raw
                    false,

                    // SameSite
                    'Lax'
                )
            );

            Log::info('Frontend role cookie created', [
                'user_id' => $user->id,
                'role' => $primaryRole,
                'remember' => $remember,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | CITIZEN LOGIN
        |--------------------------------------------------------------------------
        |
        | Citizens do NOT have RBAC roles.
        |
        | Therefore:
        |
        |     - No role cookie is created.
        |     - Laravel session remains the authentication mechanism.
        |
        */

        if ($user->user_type === 'citizen') {

            Log::info(
                'Citizen web login completed without role cookie',
                [
                    'user_id' => $user->id,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        Log::info('========== WEB LOGIN SUCCESS ==========');

        return $response;

    } catch (\Throwable $e) {

        Log::error(
            '========== WEB LOGIN FAILED ==========',
            [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_email' => $request->input('email'),
                'ip' => $request->ip(),
            ]
        );

        throw $e;
    }
}



    /*
    |--------------------------------------------------------------------------
    | WEB LOGOUT
    |--------------------------------------------------------------------------
    |
    | Authentication:
    |
    |     Laravel Web Session
    |
    */

    public function webLogout(
        Request $request
    ): JsonResponse {

        Log::info('Web logout', [
            'user_id' => $request->user()?->id,
        ]);

        $this->authSessionService->logout(
            $request
        );

        return ApiResponse::success(
            null,
            'Successfully logged out.'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | USER RELATIONS
    |--------------------------------------------------------------------------
    */

    private function loadUserRelations($user): void
    {
        $relations = [
            'administrativeUnit.parent.parent',
            'avatar',
        ];

        /*
        |--------------------------------------------------------------------------
        | Employee
        |--------------------------------------------------------------------------
        */

        if ($user->user_type === 'employee') {
            $relations[] = 'roles.permissions';
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen
        |--------------------------------------------------------------------------
        */

        if ($user->user_type === 'citizen') {
            $relations[] = 'citizenAccount.citizen.avatar';
        }

        $user->load($relations);
    }
}