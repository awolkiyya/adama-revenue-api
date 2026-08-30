<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Resources\AuthUserResource;
use Illuminate\Http\Request;

class SessionService
{
    /*
    |--------------------------------------------------------------------------
    | WEB
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated web user.
     *
     * Authentication:
     *
     *     Laravel web session
     *
     * Cookie:
     *
     *     laravel_session
     */
    public function webUser(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => new AuthUserResource($user),
        ];
    }

    /**
     * Get current web session information.
     */
    public function sessionInfo(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'authenticated' => true,
            'user' => new AuthUserResource($user),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated mobile user.
     *
     * Authentication:
     *
     *     Sanctum personal access token
     *
     * Header:
     *
     *     Authorization: Bearer {token}
     */
    public function mobileUser(Request $request): array
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => new AuthUserResource(
                $user,
                $request->bearerToken()
            ),
        ];
    }

    /**
     * Get current mobile authentication information.
     */
    public function mobileSessionInfo(Request $request): array
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'authenticated' => true,
            'user' => new AuthUserResource(
                $user,
                $request->bearerToken()
            ),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | USER RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Load relations required by the authenticated user.
     */
    private function loadUserRelations($user): void
    {
        /*
        |--------------------------------------------------------------------------
        | Common Relations
        |--------------------------------------------------------------------------
        */

        $relations = [
            'administrativeUnit.parent.parent',
            'avatar',
        ];

        /*
        |--------------------------------------------------------------------------
        | Employee
        |--------------------------------------------------------------------------
        |
        | Employees use Spatie roles and permissions.
        |
        */

        if ($user->user_type === 'employee') {
            $relations[] = 'roles.permissions';
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen
        |--------------------------------------------------------------------------
        |
        | Citizens use citizen account/profile.
        |
        */

        if ($user->user_type === 'citizen') {
            $relations[] = 'citizenAccount.citizen.avatar';
        }

        /*
        |--------------------------------------------------------------------------
        | Load Relations
        |--------------------------------------------------------------------------
        */

        $user->load($relations);
    }
}